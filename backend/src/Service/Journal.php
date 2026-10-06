<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CuratorLogEntry;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Where the activity journal is written: every change to notes, tags and
 * connections leaves a row, whoever made it (operator, 2026-10-06).
 *
 * Rows are recorded where a change HAPPENS — the one place a note is created,
 * the one place its text is set, the one place it is deleted — rather than in
 * each route that can cause one, so a new way in cannot forget to log. The
 * row is persisted for the caller's own flush, which is the write's own
 * transaction.
 *
 * Some operations are one act to the person reading the log and several
 * writes underneath. An approval applies an edit; an import creates ninety
 * notes. Those run inside {@see self::quietly()} or {@see self::batch()},
 * and the rows the writes underneath would have recorded give way to the one
 * the operation writes itself.
 */
final class Journal
{
    /** @var list<array{batch: bool, notes: list<int>}> */
    private array $open = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** A row for something that just happened, unless an enclosing operation is recording it as part of its own. */
    public function record(CuratorLogEntry $entry): void
    {
        $top = array_key_last($this->open);
        if ($top === null) {
            $this->em->persist($entry);

            return;
        }
        if ($this->open[$top]['batch']) {
            $note = $entry->getNote()?->getId();
            foreach ($entry->getAffectedNoteIds() ?? ($note === null ? [] : [$note]) as $id) {
                $this->open[$top]['notes'][] = (int) $id;
            }
        }
    }

    /**
     * Work the caller records as ONE row of its own, so nothing underneath it
     * writes one.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function quietly(callable $work): mixed
    {
        $this->open[] = ['batch' => false, 'notes' => []];
        try {
            return $work();
        } finally {
            array_pop($this->open);
        }
    }

    /**
     * Work that writes many notes, recorded as one row naming them.
     *
     * The row is written even when the work fails partway: each note an
     * import writes is committed as it goes, and the ones that arrived before
     * the failure are in the vault whether or not the rest did.
     *
     * @template T
     *
     * @param callable(): T                          $work
     * @param callable(list<int>): ?CuratorLogEntry $summarise the notes written, to the row that says so; null for none
     *
     * @return T
     */
    public function batch(callable $work, callable $summarise): mixed
    {
        $this->open[] = ['batch' => true, 'notes' => []];
        try {
            $result = $work();
        } catch (\Throwable $e) {
            $frame = array_pop($this->open);
            try {
                $this->close($frame['notes'], $summarise);
            } catch (\Throwable) {
                // The failure being reported is the one that matters; a row
                // that could not be written over it must not replace it.
            }
            throw $e;
        }
        $frame = array_pop($this->open);
        $this->close($frame['notes'], $summarise);

        return $result;
    }

    /** @param list<int> $notes */
    private function close(array $notes, callable $summarise): void
    {
        $notes = array_values(array_unique($notes));
        if ($notes === []) {
            return;
        }
        $entry = $summarise($notes);
        if ($entry === null) {
            return;
        }
        $entry->withAffectedNotes($notes);
        if ($this->em->isOpen()) {
            $this->record($entry);
            $this->em->flush();

            return;
        }
        // A write refused at its flush closes the entity manager — the memex
        // filling up halfway through an upload — and the notes before it are
        // still in the vault.
        if ($this->open === []) {
            $this->em->getConnection()->insert('curator_log', [
                'token_name' => $entry->getTokenName(),
                'token_id' => $entry->getToken()?->getId(),
                'actor' => $entry->getActor(),
                'action' => $entry->getAction(),
                'description' => $entry->getDescription(),
                'affected_note_ids' => json_encode($entry->getAffectedNoteIds(), JSON_THROW_ON_ERROR),
                'is_precedent' => 0,
                'created_at' => $entry->getCreatedAt()->format('Y-m-d H:i:s'),
            ]);
        }
    }
}
