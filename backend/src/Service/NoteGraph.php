<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The collection as nodes and edges, annotated with what the curation queue
 * already knows about each note.
 *
 * Nothing here is a new judgement: the edges are `note_links` rows and the
 * node colours are {@see CurationQueue}'s own defect predicates, so the map
 * and the queue cannot come to disagree about what needs work.
 */
class NoteGraph
{
    public const MAX_DEPTH = 2;

    private const SEMANTIC_NEIGHBOURS = 4;

    /** The most rows sqlite-vec returns from one k-NN. */
    private const KNN_MAX = 4096;

    public const MAP_NODE_CAP = 600;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurationQueue $queue,
        private readonly EmbeddingSpace $space,
    ) {
    }

    /**
     * @return array{center: ?int, depth: int, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>, truncated: bool, total_notes: int}
     */
    public function neighbourhood(int $centerId, int $depth): array
    {
        $depth = max(1, min(self::MAX_DEPTH, $depth));
        $conn = $this->em->getConnection();

        $hops = [$centerId => 0];
        $frontier = [$centerId];
        for ($hop = 1; $hop <= $depth && $frontier !== []; ++$hop) {
            $rows = $conn->fetchFirstColumn(
                'SELECT DISTINCT n.id
                 FROM note_links l
                 JOIN notes n ON n.id = CASE WHEN l.from_note_id IN (:ids) THEN l.to_note_id ELSE l.from_note_id END
                 WHERE (l.from_note_id IN (:ids) OR l.to_note_id IN (:ids))
                   AND l.to_note_id IS NOT NULL',
                ['ids' => $frontier],
                ['ids' => ArrayParameterType::INTEGER],
            );

            $frontier = [];
            foreach ($rows as $id) {
                $id = (int) $id;
                if (!isset($hops[$id])) {
                    $hops[$id] = $hop;
                    $frontier[] = $id;
                }
            }
        }

        $semantic = $this->semanticNeighbours($centerId, array_keys($hops));
        foreach ($semantic as $row) {
            if (!isset($hops[$row['note_id']])) {
                $hops[$row['note_id']] = null;
            }
        }

        $ids = array_keys($hops);
        $edges = $this->linkEdgesAmong($ids);
        foreach ($semantic as $row) {
            $edges[] = [
                'from' => $centerId,
                'to' => $row['note_id'],
                'kind' => 'semantic',
                'distance' => round($row['dist'], 4),
            ];
        }

        return self::drawn([
            'center' => $centerId,
            'depth' => $depth,
            'nodes' => $this->nodes($ids, $hops),
            'edges' => $edges,
            'truncated' => false,
            'total_notes' => count($ids),
        ]);
    }

    /**
     * Only what is drawn: a note retired between the query that gathered it
     * and the one that read its row has no node, so no edge may name it and
     * it is not the centre.
     *
     * @param array<string, mixed> $graph
     * @return array<string, mixed>
     */
    private static function drawn(array $graph): array
    {
        $present = array_flip(array_column($graph['nodes'], 'id'));
        $graph['edges'] = array_values(array_filter(
            $graph['edges'],
            static fn (array $edge): bool => isset($present[$edge['from']], $present[$edge['to']]),
        ));
        if ($graph['center'] !== null && !isset($present[$graph['center']])) {
            $graph['center'] = null;
        }

        return $graph;
    }

    /**
     * How many nearest notes each note contributes when the whole collection
     * is asked for its semantic edges. Two, where a single note's map takes
     * four: every note here brings its own, so the same budget drawn across
     * six hundred notes is a sheet of dashes rather than a finding.
     */
    private const MAP_SEMANTIC_NEIGHBOURS = 2;

    /**
     * @return array{center: null, depth: null, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>, truncated: bool, total_notes: int}
     */
    public function map(bool $semantic = false, ?int $cap = null): array
    {
        // The cap is a parameter so truncation can be exercised without six
        // hundred fixture notes. Nothing but a test passes one.
        $cap = max(1, $cap ?? self::MAP_NODE_CAP);
        $conn = $this->em->getConnection();
        $all = array_map('intval', $conn->fetchFirstColumn('SELECT id FROM notes ORDER BY id'));

        $defects = $this->queue->defectsForVault();
        $flagged = $this->flaggedNotes($all);
        $ids = $all;
        $truncated = count($ids) > $cap;
        if ($truncated) {
            // Truncation must never hide rot: a flag the owner wrote first, then
            // what the queue found, so a capped map still answers the question
            // the desk asks of it. The queue ranks flags first for the same
            // reason, and `defectsForVault` cannot see them — an operator flag
            // is not a structural predicate.
            usort($ids, static fn (int $a, int $b) => (in_array($b, $flagged, true) <=> in_array($a, $flagged, true))
                ?: (isset($defects[$b]) <=> isset($defects[$a]))
                ?: ($a <=> $b));
            $ids = array_slice($ids, 0, $cap);
        }

        $edges = $this->linkEdgesAmong($ids);
        if ($semantic) {
            $edges = [...$edges, ...$this->semanticEdgesAmong($ids, $edges)];
        }

        return self::drawn([
            'center' => null,
            'depth' => null,
            'nodes' => $this->nodes($ids, [], $defects, $flagged, forRail: true),
            'edges' => $edges,
            'truncated' => $truncated,
            'total_notes' => count($all),
        ]);
    }

    /**
     * Every note's nearest few, for the collection map's optional "close in
     * meaning, nothing links them" layer.
     *
     * One query over every pair of the notes actually ON the map, not the
     * whole vault: ranking against notes that were truncated away would let
     * a note whose two nearest are off the map contribute no edge at all —
     * including to a note that IS on the map and well inside the distance
     * cutoff. Each pair is measured once, and a pair inside the cutoff ranks
     * among a note's neighbours exactly where it would among all of them,
     * so the cutoff is applied before the ranking. Pairs are undirected here
     * — a suggestion has no direction — so each is kept once.
     *
     * @param int[] $ids
     * @param list<array<string, mixed>> $links edges already drawn, which a suggestion must not duplicate
     * @return list<array<string, mixed>>
     */
    private function semanticEdgesAmong(array $ids, array $links): array
    {
        if (count($ids) < 2) {
            return [];
        }

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'WITH vectors AS MATERIALIZED (
                 SELECT rowid AS note_id, embedding FROM note_embedding_vectors WHERE rowid IN (:ids)
             ), close AS MATERIALIZED (
                 SELECT a_id, b_id, dist FROM (
                     SELECT a.note_id AS a_id, b.note_id AS b_id, vec_distance_cosine(a.embedding, b.embedding) AS dist
                     FROM vectors a JOIN vectors b ON b.note_id > a.note_id
                 ) WHERE dist < '.$this->space->model()->graphDistance().'
             ), ranked AS (
                 SELECT from_id, to_id, dist,
                        ROW_NUMBER() OVER (PARTITION BY from_id ORDER BY dist ASC, to_id ASC) AS nearest
                 FROM (
                     SELECT a_id AS from_id, b_id AS to_id, dist FROM close
                     UNION ALL
                     SELECT b_id, a_id, dist FROM close
                 )
             )
             SELECT from_id, to_id, dist FROM ranked
             WHERE nearest <= '.self::MAP_SEMANTIC_NEIGHBOURS.'
             ORDER BY from_id ASC, nearest ASC',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $seen = [];
        foreach ($links as $link) {
            $seen[min($link['from'], $link['to']).':'.max($link['from'], $link['to'])] = true;
        }

        $out = [];
        foreach ($rows as $row) {
            $from = (int) $row['from_id'];
            $to = (int) $row['to_id'];
            $key = min($from, $to).':'.max($from, $to);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['from' => $from, 'to' => $to, 'kind' => 'semantic', 'distance' => round((float) $row['dist'], 4)];
        }

        return $out;
    }

    /**
     * @param int[] $ids
     * @param array<int, int|null> $hops
     * @return list<array<string, mixed>>
     */
    private function nodes(array $ids, array $hops, ?array $defects = null, ?array $flagged = null, bool $forRail = false): array
    {
        if ($ids === []) {
            return [];
        }

        $defects ??= $this->queue->defectsAmong($ids);
        $flagged ??= $this->flaggedNotes($ids);
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, title, status, summary, updated_at FROM notes WHERE id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );
        $tags = $forRail ? $this->tagsAmong($ids) : [];

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $summary = $row['summary'] === null ? null : trim((string) $row['summary']);
            $out[] = [
                'id' => $id,
                'title' => (string) $row['title'],
                'status' => (string) $row['status'],
                // Enough for a reader to decide whether to open the note,
                // times the node count — the body never travels with a map.
                // Only the collection map has a rail to show these in. The
                // neighbourhood map draws dots and a caption, and it has no
                // node cap — a hub at two hops is hundreds of notes.
                'summary' => $forRail ? self::shorten($summary) : null,
                'tags' => $forRail ? ($tags[$id] ?? []) : [],
                'updated_at' => $forRail
                    ? (new \DateTimeImmutable((string) $row['updated_at']))->format(DATE_ATOM)
                    : null,
                'hop' => array_key_exists($id, $hops) ? $hops[$id] : null,
                'defects' => $defects[$id]['reasons'] ?? [],
                'flagged' => in_array($id, $flagged, true),
            ];
        }

        return $out;
    }

    private const SUMMARY_CHARS = 240;

    /** Cut at a word, and say it was cut — a sentence stopping mid-word reads as a bug. */
    private static function shorten(?string $summary): ?string
    {
        if ($summary === null || $summary === '') {
            return null;
        }
        if (mb_strlen($summary) <= self::SUMMARY_CHARS) {
            return $summary;
        }

        $cut = mb_substr($summary, 0, self::SUMMARY_CHARS);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > self::SUMMARY_CHARS - 40) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " \t\n\r,;:.—-").'…';
    }

    /**
     * @param int[] $ids
     * @return array<int, list<string>>
     */
    private function tagsAmong(array $ids): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT nt.note_id, t.name
             FROM note_tag nt
             JOIN tags t ON t.id = nt.tag_id
             WHERE nt.note_id IN (:ids)
             ORDER BY '.SystemTags::sqlRank('t.name').', t.name',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['note_id']][] = (string) $row['name'];
        }

        return $out;
    }

    /**
     * @param int[] $ids
     * @return int[]
     */
    private function flaggedNotes(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_map('intval', $this->em->getConnection()->fetchFirstColumn(
            'SELECT note_id FROM curation_flags WHERE resolved_at IS NULL AND note_id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        ));
    }

    /**
     * @param int[] $ids
     * @return list<array<string, mixed>>
     */
    private function linkEdgesAmong(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT DISTINCT from_note_id, to_note_id
             FROM note_links
             WHERE from_note_id IN (:ids) AND to_note_id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );

        return array_map(static fn (array $r) => [
            'from' => (int) $r['from_note_id'],
            'to' => (int) $r['to_note_id'],
            'kind' => 'link',
        ], $rows);
    }

    /**
     * The centre's nearest notes outside its neighbourhood, inside the
     * cutoff. The k-NN is exact and asked for as many notes as the
     * neighbourhood holds plus the four, so the four are the four nearest
     * however well connected the centre is — up to the most one k-NN
     * returns.
     *
     * @param int[] $exclude
     * @return list<array{note_id: int, dist: float}>
     */
    private function semanticNeighbours(int $centerId, array $exclude): array
    {
        $conn = $this->em->getConnection();
        $vector = $conn->fetchOne(
            'SELECT embedding FROM note_embedding_vectors WHERE rowid = :center',
            ['center' => $centerId],
            ['center' => ParameterType::INTEGER],
        );
        if (!is_string($vector)) {
            return [];
        }

        // The neighbourhood is excluded in PHP rather than in the query: a hub
        // at depth 2 can reach thousands of notes, one bind parameter each.
        $rows = $conn->fetchAllAssociative(
            'SELECT rowid AS note_id, distance AS dist FROM note_embedding_vectors
             WHERE embedding MATCH :vector AND k = :k
             ORDER BY distance',
            ['vector' => $vector, 'k' => min(self::KNN_MAX, count($exclude) + self::SEMANTIC_NEIGHBOURS)],
            ['vector' => ParameterType::BINARY, 'k' => ParameterType::INTEGER],
        );

        $skip = array_flip($exclude);
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['note_id'];
            if (isset($skip[$id]) || (float) $row['dist'] >= $this->space->model()->graphDistance()) {
                continue;
            }
            $out[] = ['note_id' => $id, 'dist' => (float) $row['dist']];
            if (count($out) === self::SEMANTIC_NEIGHBOURS) {
                break;
            }
        }

        return $out;
    }
}
