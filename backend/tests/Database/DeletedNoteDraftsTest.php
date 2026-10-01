<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\CuratorLogEntry;
use App\Entity\EditProposal;
use App\Service\NoteLimbo;
use App\Service\NoteWriter;
use App\Service\ReviewVerdicts;
use App\Tests\Support\KbFixture;

/**
 * Deleting a note discards the work held against it — and says so.
 *
 * The note is restorable for 30 days; the drafts are not, and until now they
 * went out with the foreign key and left nothing behind. A deletion the owner
 * reverses still cost an agent everything it had written, and no screen in
 * memex could say what had been there.
 */
final class DeletedNoteDraftsTest extends DatabaseTestCase
{
    private NoteWriter $writer;
    private NoteLimbo $limbo;
    private ReviewVerdicts $verdicts;
    private KbFixture $kb;

    private const VERDICT = ['comment' => null, 'precedent' => false];

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = self::getContainer()->get(NoteWriter::class);
        $this->limbo = self::getContainer()->get(NoteLimbo::class);
        $this->verdicts = self::getContainer()->get(ReviewVerdicts::class);
        $this->kb = new KbFixture(self::getContainer());
    }

    /** @return string[] the journal lines written about this note */
    private function journal(int $noteId): array
    {
        return $this->em->getConnection()->fetchFirstColumn(
            'SELECT description FROM curator_log WHERE note_id = :id OR retired_note_id = :id ORDER BY id',
            ['id' => $noteId]
        );
    }

    /** How many lines about this note announce a discarded draft. */
    private function discardLines(int $noteId): int
    {
        return count(array_filter(
            $this->journal($noteId),
            static fn (string $d): bool => str_contains($d, 'discarded the held'),
        ));
    }

    public function testRetiringANoteRecordsEveryDraftItDiscards(): void
    {
        $note = $this->kb->note($this->writer, 'Has work waiting', 'Original body.');
        $this->writer->propose(
            $note, $this->kb->agentToken,
            null, 'One connection had this.', null, applyTags: false,
        );
        $this->writer->propose(
            $note, $this->kb->otherAgentToken,
            null, 'Another connection had this.', null, applyTags: false,
        );
        $noteId = $note->getId();

        $this->limbo->retire($note, 'operator', 'Testing.');

        $lines = array_values(array_filter(
            $this->journal($noteId),
            static fn (string $d): bool => str_contains($d, 'discarded the held'),
        ));
        self::assertCount(2, $lines, 'Each discarded draft needs its own line: '.implode(' // ', $this->journal($noteId)));
        self::assertStringContainsString($this->kb->agentToken->getName(), implode(' ', $lines));
        self::assertStringContainsString($this->kb->otherAgentToken->getName(), implode(' ', $lines));
    }

    public function testRetiringTheKeeperRecordsTheIncomingMergeDraft(): void
    {
        $source = $this->kb->note($this->writer, 'Merge source', 'Source text.');
        $keeper = $this->kb->note($this->writer, 'Merge keeper', 'Keeper text.');
        $draft = $this->writer->proposeMerge($source, $keeper, $this->kb->agentToken, 'Combined draft.', 'Related.');
        $draftId = $draft->getId();
        $keeperId = $keeper->getId();

        $this->limbo->retire($keeper, 'operator');

        $lines = $this->journal($keeperId);
        self::assertCount(1, $lines);
        self::assertStringContainsString('keeper', $lines[0]);
        self::assertStringContainsString('Merge source', $lines[0]);
        self::assertStringContainsString('Merge keeper', $lines[0]);
        self::assertStringContainsString($this->kb->agentToken->getName(), $lines[0]);
        self::assertFalse($this->em->getConnection()->fetchOne(
            'SELECT id FROM edit_proposals WHERE id = :id',
            ['id' => $draftId],
        ));
        self::assertSame('Source text.', $source->getBodyMd());
    }

    public function testRetirementLocksBeforeSnapshotAndDraftCollection(): void
    {
        $note = $this->kb->note($this->writer, 'Retirement race', 'Original body.');
        $conn = $this->em->getConnection();
        $snapshots = 0;
        $reads = 0;
        self::assertTrue(!\App\Tests\Support\SqlObservation::isWriteLocked($this->kb->tenant->vaultPath()), 'Precondition: the probe sees an idle vault');
        \App\Tests\Support\SqlObservation::during($conn, function (string $sql) use (&$snapshots, &$reads): void {
            if (str_starts_with($sql, 'INSERT INTO deleted_notes')) {
                ++$snapshots;
                self::assertFalse(!\App\Tests\Support\SqlObservation::isWriteLocked($this->kb->tenant->vaultPath()), 'Retirement must lock before copying the note');
            }
            if (str_starts_with($sql, 'SELECT') && str_contains($sql, 'FROM edit_proposals')) {
                ++$reads;
                self::assertFalse(!\App\Tests\Support\SqlObservation::isWriteLocked($this->kb->tenant->vaultPath()));
            }
        }, fn () => $this->limbo->retire($note, 'operator'));
        self::assertSame(1, $snapshots);
        self::assertSame(1, $reads);
    }

    public function testANoteWithNothingHeldWritesNoDiscardLine(): void
    {
        $note = $this->kb->note($this->writer, 'Nothing waiting', 'Original body.');
        $noteId = $note->getId();

        $this->limbo->retire($note, 'operator', 'Testing.');

        self::assertSame(0, $this->discardLines($noteId));
    }

    public function testApprovingADeleteProposalAlsoRecordsWhatItTookWithIt(): void
    {
        // The other door: the operator approves an agent's delete from the
        // inbox, and a DIFFERENT connection's edit is waiting on that note.
        $note = $this->kb->note($this->writer, 'Two agents disagree', 'Original body.');
        $edit = $this->writer->propose(
            $note, $this->kb->otherAgentToken,
            null, 'I would rather fix it.', null, applyTags: false,
        );
        $editId = $edit->getId();
        $deletion = $this->writer->proposeDelete($note, $this->kb->agentToken, 'Stale.');
        $noteId = $note->getId();

        $this->verdicts->approveProposal($deletion, self::VERDICT, $this->reviewSnapshot($deletion));

        self::assertNull(
            $this->em->getConnection()->fetchOne('SELECT id FROM edit_proposals WHERE id = :id', ['id' => $editId]) ?: null,
            'The held edit is gone with the note',
        );
        self::assertNotEmpty(array_filter(
            $this->journal($noteId),
            static fn (string $d): bool => str_contains($d, 'discarded the held'),
        ), 'Nothing in the journal said the edit was discarded');
    }

    public function testRestoringANoteDoesNotBringTheDraftsBack(): void
    {
        // Stated as a test because it is the asymmetry the warning exists for:
        // if a restore ever did return them, the copy would be a lie.
        $note = $this->kb->note($this->writer, 'Restorable', 'Original body.');
        $this->writer->propose(
            $note, $this->kb->agentToken,
            null, 'Held against it.', null, applyTags: false,
        );
        $noteId = $note->getId();

        $this->limbo->retire($note, 'operator', 'Testing.');
        $restored = $this->limbo->restore($noteId);

        self::assertNotNull($restored);
        self::assertSame(
            0,
            (int) $this->em->getConnection()->fetchOne(
                'SELECT count(*) FROM edit_proposals WHERE note_id = :id',
                ['id' => $noteId]
            ),
        );
    }

    public function testPurgingKeepsTheJournalMetadataAndErasesItsNarrative(): void
    {
        $note = $this->kb->note($this->writer, 'SYNTHETIC-PURGE-TITLE', 'Original body.');
        $this->writer->propose(
            $note, $this->kb->agentToken,
            null, 'Held against it.', null, applyTags: false,
        );
        $noteId = $note->getId();
        $this->limbo->retire($note, 'operator', 'Testing.');
        $conn = $this->em->getConnection();
        $before = $conn->fetchAllAssociative(
            'SELECT id, action, token_name, token_id, created_at FROM curator_log
             WHERE retired_note_id = :note ORDER BY id',
            ['note' => $noteId],
        );
        self::assertNotEmpty($before);
        self::assertContains(CuratorLogEntry::ACTION_REJECTED, array_column($before, 'action'));
        $ids = array_column($before, 'id');
        $conn->executeStatement(
            'UPDATE curator_log SET operator_comment = :marker WHERE id IN (:ids)',
            ['marker' => 'SYNTHETIC-PURGE-COMMENT', 'ids' => $ids],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
        self::assertNotEmpty(array_filter(
            $this->journal($noteId),
            static fn (string $description): bool => str_contains($description, 'discarded the held'),
        ));

        self::assertTrue($this->limbo->purge($noteId));
        $this->em->clear();

        $after = $conn->fetchAllAssociative(
            'SELECT id, action, token_name, token_id, created_at FROM curator_log
             WHERE id IN (:ids) ORDER BY id',
            ['ids' => $ids],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
        self::assertSame($before, $after, 'Erasure retains each audit event and its attribution.');
        $content = $conn->fetchAllAssociative(
            'SELECT description, operator_comment, note_title FROM curator_log WHERE id IN (:ids)',
            ['ids' => $ids],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
        foreach ($content as $row) {
            self::assertSame('Content removed when note history was forgotten.', $row['description']);
            self::assertNull($row['operator_comment']);
            self::assertNull($row['note_title']);
        }
    }

    public function testPurgeTakesTheTeamLockBeforeTheTombstone(): void
    {
        $note = $this->kb->note($this->writer, 'Purge lock order', 'Body.');
        $id = $note->getId();
        $this->limbo->retire($note, 'operator');
        $conn = $this->em->getConnection();
        $tombstoneReads = 0;
        self::assertTrue(!\App\Tests\Support\SqlObservation::isWriteLocked($this->kb->tenant->vaultPath()), 'Precondition: the probe sees an idle vault');
        \App\Tests\Support\SqlObservation::during($conn, function (string $sql) use (&$tombstoneReads): void {
            if (str_starts_with(ltrim($sql), 'SELECT') && str_contains($sql, 'FROM deleted_notes')) {
                ++$tombstoneReads;
                self::assertFalse(!\App\Tests\Support\SqlObservation::isWriteLocked($this->kb->tenant->vaultPath()), 'Purge must read the tombstone under the write lock.');
            }
        }, fn () => self::assertTrue($this->limbo->purge($id)));
        self::assertSame(1, $tombstoneReads);
        self::assertNull($conn->fetchOne('SELECT body_md FROM deleted_notes WHERE id = ?', [$id]));
    }

    public function testRestoreTakesTheTeamLockBeforeTheTombstone(): void
    {
        $note = $this->kb->note($this->writer, 'Restore lock order', 'Body.');
        $id = $note->getId();
        $this->limbo->retire($note, 'operator');
        $conn = $this->em->getConnection();
        $tombstoneReads = 0;
        self::assertTrue(!\App\Tests\Support\SqlObservation::isWriteLocked($this->kb->tenant->vaultPath()), 'Precondition: the probe sees an idle vault');
        \App\Tests\Support\SqlObservation::during($conn, function (string $sql) use (&$tombstoneReads): void {
            if (str_starts_with(ltrim($sql), 'SELECT') && str_contains($sql, 'FROM deleted_notes')) {
                ++$tombstoneReads;
                self::assertFalse(!\App\Tests\Support\SqlObservation::isWriteLocked($this->kb->tenant->vaultPath()), 'Restore must read the tombstone under the write lock.');
            }
        }, fn () => self::assertNotNull($this->limbo->restore($id)));
        self::assertSame(1, $tombstoneReads);
        self::assertSame('Body.', $conn->fetchOne('SELECT body_md FROM notes WHERE id = ?', [$id]));
        self::assertFalse($conn->fetchOne('SELECT id FROM deleted_notes WHERE id = ?', [$id]));
    }
}
