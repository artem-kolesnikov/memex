<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Every note's nearest notes in meaning, kept in `note_neighbours` so that
 * finding duplicates reads a table rather than measuring every pair of the
 * vault, which grows with the square of its size: 23 s at 3,000 notes.
 *
 * A note's list is its {@see KEPT} nearest notes closer than the model's
 * horizon ({@see EmbeddingModel::neighbourHorizon()}), nearest first, ties by
 * id. The schema's triggers put a note in
 * `note_neighbours_stale` when its vector is written, and the notes that
 * listed it when its vector or the note goes, dropping its rows. Settling a
 * note is one nearest-neighbour search: its own list, and its place in the
 * list of every note it is near.
 */
final class NoteNeighbours
{
    public const KEPT = 5;

    /** sqlite-vec's ceiling on `k`. */
    private const KNN_MAX = 4096;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EmbeddingSpace $space,
    ) {
    }

    /**
     * Settles as many stale notes as `$measurements` distances allow, one
     * note at least.
     *
     * @return int how many notes are still stale
     */
    public function settle(int $measurements): int
    {
        $conn = $this->em->getConnection();
        $vectors = max(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM note_embeddings'));
        $ids = $conn->fetchFirstColumn(
            'SELECT note_id FROM note_neighbours_stale ORDER BY note_id LIMIT :take',
            ['take' => max(1, intdiv($measurements, $vectors))],
            ['take' => ParameterType::INTEGER],
        );
        foreach ($ids as $id) {
            $conn->transactional(fn () => $this->settleOne((int) $id));
        }

        return (int) $conn->fetchOne('SELECT COUNT(*) FROM note_neighbours_stale');
    }

    private function settleOne(int $id): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM note_neighbours_stale WHERE note_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM note_neighbours WHERE note_id = :id', ['id' => $id]);

        $vector = $conn->fetchOne(
            'SELECT embedding FROM note_embedding_vectors WHERE rowid = :id',
            ['id' => $id],
            ['id' => ParameterType::INTEGER],
        );
        if (!\is_string($vector)) {
            return;
        }

        $near = $this->near($id, $vector);
        if ($near === []) {
            return;
        }

        // Distances travel as JSON, which keeps every bit: a bound float
        // arrives as fourteen digits of text, and a tie rounded apart is
        // decided by the rounding rather than the id.
        $conn->executeStatement(
            "INSERT INTO note_neighbours (note_id, neighbour_id, distance)
             SELECT :id, json_extract(value, '$[0]'), json_extract(value, '$[1]') FROM json_each(:own)",
            ['id' => $id, 'own' => self::json(\array_slice($near, 0, self::KEPT))],
            ['id' => ParameterType::INTEGER],
        );

        // Into each list it is nearer than the last of, then those lists cut
        // back to their nearest.
        $pairs = self::json($near);
        $entered = $conn->fetchFirstColumn(
            "SELECT p.note_id FROM (
                 SELECT json_extract(value, '$[0]') AS note_id, json_extract(value, '$[1]') AS distance FROM json_each(:pairs)
             ) p
             WHERE (SELECT COUNT(*) FROM note_neighbours n WHERE n.note_id = p.note_id) < ".self::KEPT."
                OR (p.distance, :id) < (
                    SELECT n.distance, n.neighbour_id FROM note_neighbours n WHERE n.note_id = p.note_id
                    ORDER BY n.distance DESC, n.neighbour_id DESC LIMIT 1
                )",
            ['pairs' => $pairs, 'id' => $id],
            ['id' => ParameterType::INTEGER],
        );
        $distances = array_column($near, 'distance', 'id');
        foreach ($entered as $neighbour) {
            $neighbour = (int) $neighbour;
            $conn->executeStatement(
                "INSERT OR REPLACE INTO note_neighbours (note_id, neighbour_id, distance) VALUES (:neighbour, :id, json_extract(:distance, '$'))",
                ['neighbour' => $neighbour, 'id' => $id, 'distance' => json_encode($distances[$neighbour], JSON_THROW_ON_ERROR)],
                ['neighbour' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
            );
            $conn->executeStatement(
                'DELETE FROM note_neighbours WHERE note_id = :neighbour AND neighbour_id IN (
                     SELECT neighbour_id FROM note_neighbours WHERE note_id = :neighbour
                     ORDER BY distance ASC, neighbour_id ASC LIMIT -1 OFFSET '.self::KEPT.'
                 )',
                ['neighbour' => $neighbour],
                ['neighbour' => ParameterType::INTEGER],
            );
        }
    }

    /**
     * Every note inside the horizon, nearest first, ties by id, from
     * one nearest-neighbour search: it reads the vectors in bulk, where
     * reading them row by row slows with the vault.
     *
     * @return list<array{id: int, distance: float}>
     */
    private function near(int $id, string $vector): array
    {
        $conn = $this->em->getConnection();
        $found = $conn->fetchAllAssociative(
            'SELECT rowid AS id, distance FROM note_embedding_vectors WHERE embedding MATCH :vector AND k = :k',
            ['vector' => $vector, 'k' => self::KNN_MAX],
            ['vector' => ParameterType::BINARY, 'k' => ParameterType::INTEGER],
        );
        $near = [];
        foreach ($found as $row) {
            $near[(int) $row['id']] = (float) $row['distance'];
        }

        unset($near[$id]);
        $horizon = $this->space->model()->neighbourHorizon();
        $rows = [];
        foreach ($near as $neighbour => $distance) {
            if ($distance < $horizon) {
                $rows[] = ['id' => $neighbour, 'distance' => $distance];
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$a['distance'], $a['id']] <=> [$b['distance'], $b['id']]);

        return $rows;
    }

    /** @param list<array{id: int, distance: float}> $rows */
    private static function json(array $rows): string
    {
        return json_encode(array_map(static fn (array $row): array => [$row['id'], $row['distance']], $rows), JSON_THROW_ON_ERROR);
    }
}
