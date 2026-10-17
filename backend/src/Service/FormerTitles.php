<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Note;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The titles a note has stopped answering to, so a link written under one
 * still reaches it (operator, 2026-10-17: a rename must not kill the links to
 * a note, accurately and as little intrusive as possible).
 *
 * Nothing else changes on a rename: no linking note's text is rewritten and
 * nothing new waits for review. {@see NoteEnricher} tries these titles last,
 * after every title and path a note answers to now, so a note that takes an
 * old title later takes the links that name it too, and the newest rename wins
 * between two notes that both once held it.
 *
 * The rows carry no foreign key, on purpose: a note in Deleted notes keeps its
 * former titles for when it is restored, and resolution only ever joins the
 * notes that exist. They go when the note's content is destroyed
 * ({@see NoteLimbo}) or its owner forgets its history ({@see NoteRevisions::forget()}).
 */
final class FormerTitles
{
    /** As many as a note's export carries; past it, the oldest are dropped. */
    public const MAX_PER_NOTE = 50;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * After a rename: the old title joins the note's former titles, and the
     * new one leaves them, so renaming a note back to an earlier title leaves
     * no row naming what it is called now.
     */
    public function renamed(Note $note, string $previousTitle): void
    {
        $this->add($note, [$previousTitle]);
    }

    /**
     * Titles a note also answers to: an export's `aliases`, or the title of a
     * note merged into it. One equal to the note's current title, ignoring
     * case, is no former title and is skipped.
     *
     * @param list<string> $titles
     */
    public function add(Note $note, array $titles): void
    {
        $conn = $this->em->getConnection();
        $id = (int) $note->getId();
        $current = mb_strtolower($note->getTitle());
        $conn->executeStatement(
            'DELETE FROM note_former_titles WHERE note_id = :id AND LOWER(title) = LOWER(:current)',
            ['id' => $id, 'current' => $note->getTitle()]
        );
        $seen = [$current => true];
        foreach ($titles as $title) {
            $title = mb_substr(trim($title), 0, 500);
            $key = mb_strtolower($title);
            if ($title === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            // Delete then insert, so the title moves to the newest position
            // rather than keeping the date it was first given up.
            $conn->executeStatement(
                'DELETE FROM note_former_titles WHERE note_id = :id AND LOWER(title) = LOWER(:title)',
                ['id' => $id, 'title' => $title]
            );
            $conn->executeStatement(
                'INSERT INTO note_former_titles (note_id, title, renamed_at) VALUES (:id, :title, :at)',
                ['id' => $id, 'title' => $title, 'at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')]
            );
        }
        $conn->executeStatement(
            'DELETE FROM note_former_titles WHERE note_id = :id AND id NOT IN (
                 SELECT id FROM note_former_titles WHERE note_id = :id ORDER BY id DESC LIMIT '.self::MAX_PER_NOTE.'
             )',
            ['id' => $id]
        );
    }

    /**
     * A note's former titles, the most recent first.
     *
     * @return list<string>
     */
    public function of(int $noteId): array
    {
        return array_map('strval', $this->em->getConnection()->fetchFirstColumn(
            'SELECT title FROM note_former_titles WHERE note_id = :id ORDER BY id DESC',
            ['id' => $noteId]
        ));
    }

    /** Whether any note once held this title, ignoring case. */
    public function anyNamed(string $title): bool
    {
        return $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM note_former_titles f JOIN notes n ON n.id = f.note_id WHERE LOWER(f.title) = LOWER(:title) LIMIT 1',
            ['title' => $title]
        ) !== false;
    }
}
