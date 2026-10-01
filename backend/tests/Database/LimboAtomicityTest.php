<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;
use App\Service\NoteLimbo;
use App\Service\NoteWriter;
use App\Tests\Support\KbFixture;

/**
 * Retirement and the expiry sweep, as single operations.
 *
 * `retire()` used to commit its two halves separately: the INSERT into
 * `deleted_notes` ran in DBAL autocommit, the removal from `notes` in a
 * separate flush. A failure in between left the note in BOTH tables — live and
 * searchable, and listed in limbo as restorable — from which it could neither
 * be deleted again (the deleted_notes primary key collides) nor restored (the
 * notes primary key collides). Three audit lenses found it independently, and
 * `restore()` ten lines below was already transactional, which is what made it
 * an oversight rather than a design.
 *
 * WHAT THESE TESTS DO NOT COVER, stated plainly because the audit's whole
 * lesson was about assertions that look stronger than they are: the concurrent
 * interleavings themselves. What is covered is the property that makes those
 * interleavings impossible: the work is one transaction rather than several
 * that can disagree, and a transaction write-locks the whole vault from BEGIN,
 * so a restore cannot commit between the sweep's SELECT and its DELETE.
 */
final class LimboAtomicityTest extends DatabaseTestCase
{
    private NoteWriter $writer;
    private NoteLimbo $limbo;
    private KbFixture $kb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = self::getContainer()->get(NoteWriter::class);
        $this->limbo = self::getContainer()->get(NoteLimbo::class);
        $this->kb = new KbFixture(self::getContainer());
    }

    public function testAFailureDuringRetirementLeavesTheNoteWhollyAliveNotInBothTables(): void
    {
        $note = $this->kb->note($this->writer, 'A note', 'Body.');
        $id = $note->getId();

        // A second entity that the SAME flush will try to write and the vault
        // will refuse: its body is over the note size limit. This is the
        // realistic shape of the failure — something else in the unit of work
        // goes wrong between the tombstone INSERT and the removal — without
        // needing to stage a lock timeout or kill a connection.
        $poison = new Note(
            null,
            'Poison',
            str_repeat('x', self::getContainer()->get(\App\Service\StorageLimits::class)->current()['max_note_bytes'] + 1),
            Note::SOURCE_MANUAL,
            null,
            Note::STATUS_VERIFIED,
        );
        $this->em->persist($poison);

        try {
            $this->limbo->retire($note, Note::ACTOR_HUMAN, 'Should not survive this.');
            self::fail('Precondition: the flush was expected to fail');
        } catch (\Throwable) {
            // Expected. What matters is what the tables look like now.
        }

        self::assertSame(
            0,
            (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM deleted_notes WHERE id = :id', ['id' => $id]),
            'A tombstone was committed for a retirement that did not happen'
        );
        self::assertSame(
            1,
            (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM notes WHERE id = :id', ['id' => $id]),
            'The note must still be exactly where it was'
        );
    }

    public function testTheSweepDestroysRevisionsOnlyForRowsItActuallyPurged(): void
    {
        // The defect this replaces: the sweep read its ids in one statement and
        // purged in another, so the DELETE could act on a row the UPDATE had
        // skipped. Now one transaction decides and reports the same set —
        // asserted here by giving it a mixed batch and checking it touched
        // exactly half.
        $expired = $this->retiredNoteWithHistory('Past its thirty days');
        $fresh = $this->retiredNoteWithHistory('Retired yesterday');
        $this->em->getConnection()->executeStatement(
            'UPDATE deleted_notes SET purge_after = :past WHERE id = :id',
            ['past' => self::yesterday(), 'id' => $expired]
        );

        self::assertSame(1, $this->limbo->purgeExpired(), 'Only the expired one');

        self::assertSame(0, $this->revisionCount($expired), 'Its history went with its body');
        self::assertGreaterThan(0, $this->revisionCount($fresh), 'The other note lost nothing');
        self::assertNotNull($this->purgedAt($expired));
        self::assertNull($this->purgedAt($fresh));
    }

    public function testASecondSweepIsANoOp(): void
    {
        // If the count and the id list could ever disagree, running twice is
        // where it shows: the second pass would report work it did not do, or
        // delete revisions belonging to rows already purged.
        $id = $this->retiredNoteWithHistory('Past its thirty days');
        $this->em->getConnection()->executeStatement(
            'UPDATE deleted_notes SET purge_after = :past WHERE id = :id',
            ['past' => self::yesterday(), 'id' => $id]
        );
        self::assertSame(1, $this->limbo->purgeExpired());

        self::assertSame(0, $this->limbo->purgeExpired(), 'Nothing is left to purge');
    }

    public function testANoteRestoredBeforeTheSweepIsNotSweptAndKeepsItsHistory(): void
    {
        // The sequential form of the race: at exactly day 30 a note is still
        // shown as restorable, and an operator who restores it must not then
        // watch a scheduled sweep delete the history of a live note.
        $id = $this->retiredNoteWithHistory('Rescued at the last moment');
        $this->em->getConnection()->executeStatement(
            'UPDATE deleted_notes SET purge_after = :past WHERE id = :id',
            ['past' => self::yesterday(), 'id' => $id]
        );
        $before = $this->revisionCount($id);

        self::assertNotNull($this->limbo->restore($id));

        self::assertSame(0, $this->limbo->purgeExpired(), 'It is not in limbo any more');
        self::assertSame($before, $this->revisionCount($id), 'A live note keeps its history');
        self::assertSame(
            1,
            (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM notes WHERE id = :id', ['id' => $id])
        );
    }

    private static function yesterday(): string
    {
        return (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');
    }

    private function retiredNoteWithHistory(string $title): int
    {
        $note = $this->kb->note($this->writer, $title, 'First body.');
        $id = $note->getId();
        $this->writer->update($note, null, 'Second body.', null, \App\Service\EmbeddingSpend::Metered, applyTags: false);
        self::assertGreaterThan(0, $this->revisionCount($id), 'Precondition: it has history');
        $this->limbo->retire($note, Note::ACTOR_HUMAN, null);

        return $id;
    }

    private function revisionCount(int $noteId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM note_revisions WHERE note_id = :id',
            ['id' => $noteId]
        );
    }

    private function purgedAt(int $noteId): ?string
    {
        $value = $this->em->getConnection()->fetchOne(
            'SELECT purged_at FROM deleted_notes WHERE id = :id',
            ['id' => $noteId]
        );

        return $value === false || $value === null ? null : (string) $value;
    }
}
