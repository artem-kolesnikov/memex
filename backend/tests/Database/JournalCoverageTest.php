<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\CuratorLogEntry;
use App\Entity\Note;
use App\Entity\NoteRevision;
use App\Service\BearerTokens;
use App\Service\EmbeddingSpend;
use App\Service\Journal;
use App\Service\NoteLimbo;
use App\Service\NoteWriter;
use App\Service\ReviewVerdicts;
use App\Tests\Support\KbFixture;

/**
 * The journal is the vault's whole log (operator, 2026-10-06: "activity log is
 * supposed to be full log of all meaningful actions"). His vault held one row
 * after nine days of daily use, because rows were written only for a curator
 * connection's work and for verdicts that carried a comment.
 */
final class JournalCoverageTest extends DatabaseTestCase
{
    private const VERDICT = ['comment' => null, 'precedent' => false];

    private NoteWriter $writer;
    private ReviewVerdicts $verdicts;
    private NoteLimbo $limbo;
    private KbFixture $kb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = self::getContainer()->get(NoteWriter::class);
        $this->verdicts = self::getContainer()->get(ReviewVerdicts::class);
        $this->limbo = self::getContainer()->get(NoteLimbo::class);
        $this->kb = new KbFixture(self::getContainer());
    }

    /** @return list<array{action: string, actor: string, description: string, note_id: ?int}> */
    private function rows(): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT action, actor, description, note_id FROM curator_log ORDER BY id',
        );
    }

    public function testTheOwnersOwnWritingIsLogged(): void
    {
        $note = $this->kb->note($this->writer, 'Groceries', 'Milk.');
        $this->writer->update($note, null, "Milk.\nBread.", ['shopping'], null, actor: Note::ACTOR_HUMAN, operation: NoteRevision::OP_EDIT);

        self::assertSame([
            ['action' => 'create', 'actor' => 'human', 'description' => 'Created “Groceries”', 'note_id' => $note->getId()],
            ['action' => 'edit', 'actor' => 'human', 'description' => 'Edited body + tags of “Groceries”', 'note_id' => $note->getId()],
        ], $this->rows());
    }

    public function testASaveThatChangesNothingIsNotARow(): void
    {
        $note = $this->kb->note($this->writer, 'Unchanged', 'Same.');
        $this->writer->update($note, 'Unchanged', 'Same.', null, null, actor: Note::ACTOR_HUMAN, operation: NoteRevision::OP_EDIT);

        self::assertSame(['create'], array_column($this->rows(), 'action'));
    }

    public function testAnAgentsProposalAndAPlainApprovalAreOneRowEach(): void
    {
        $note = $this->kb->note($this->writer, 'Plan', 'Old plan.');
        $proposal = $this->writer->propose($note, $this->kb->agentToken, null, 'New plan.', null, applyTags: false);
        $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));

        $rows = $this->rows();
        self::assertSame(['create', 'edit-proposed', 'approved'], array_column($rows, 'action'));
        self::assertSame(['human', 'agent', 'human'], array_column($rows, 'actor'));
        self::assertStringContainsString($this->kb->agentToken->displayName().'’s held edit', $rows[2]['description']);
    }

    public function testAnAgentsNewNoteAndItsRejectionAreLogged(): void
    {
        $note = $this->writer->create($this->kb->agentToken, 'Draft idea', 'Body.', Note::SOURCE_AGENT, null, [], enrich: null)['note'];
        $this->verdicts->rejectNote($note, self::VERDICT);

        $rows = $this->rows();
        self::assertSame(['create-proposed', 'rejected'], array_column($rows, 'action'), 'A rejection is one row, not a rejection and a deletion');
        self::assertSame(['agent', 'human'], array_column($rows, 'actor'));
    }

    public function testDeletingRestoringAndPurgingAreLogged(): void
    {
        $note = $this->kb->note($this->writer, 'Old address', 'Somewhere.');
        $id = (int) $note->getId();
        $this->limbo->retire($note, 'operator', 'it has the address in it');
        $restored = $this->limbo->restore($id);
        self::assertNotNull($restored);

        $this->kb->refresh();
        $this->limbo->retire($this->em->find(Note::class, $id), 'operator', 'gone for good this time');
        $this->limbo->purge($id);

        $rows = $this->rows();
        self::assertSame(['create', 'delete', 'restore', 'delete', 'purge'], array_column($rows, 'action'));
        self::assertSame('Deleted “Old address” for good', $rows[4]['description']);
        self::assertStringNotContainsString(
            'address in it',
            implode("\n", array_column($rows, 'description')),
            'Purging destroys the reason the owner gave, so the row that copied it cannot keep it',
        );
    }

    public function testARestoredNoteGetsItsRowsBack(): void
    {
        $note = $this->kb->note($this->writer, 'Comes back', 'Body.');
        $id = (int) $note->getId();
        $this->limbo->retire($note, 'operator');
        $this->limbo->restore($id);

        self::assertSame(
            [$id, $id, $id],
            array_map('intval', array_column($this->rows(), 'note_id')),
            'Created, deleted, restored: all three about the note that is back',
        );
    }

    public function testManyNotesAtOnceAreOneRow(): void
    {
        $journal = self::getContainer()->get(Journal::class);
        $ids = $journal->batch(
            fn (): array => array_map(
                fn (string $title): int => (int) $this->writer->create(null, $title, 'Body.', Note::SOURCE_UPLOAD, null, [], enrich: null)['note']->getId(),
                ['One', 'Two', 'Three'],
            ),
            static fn (array $notes): CuratorLogEntry => new CuratorLogEntry('operator', CuratorLogEntry::ACTION_IMPORT, 'Imported '.count($notes).' notes'),
        );

        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT action, affected_note_ids FROM curator_log');
        self::assertCount(1, $rows);
        self::assertSame('import', $rows[0]['action']);
        self::assertSame($ids, json_decode($rows[0]['affected_note_ids'], true));
    }

    public function testConnectingAndDisconnectingAreLogged(): void
    {
        $tokens = self::getContainer()->get(BearerTokens::class);
        [$token] = $tokens->issue($this->kb->account, 'Laptop assistant');
        $tokens->revoke($this->kb->account, $token);

        self::assertSame(
            [['connected', 'Connected “Laptop assistant”'], ['disconnected', 'Disconnected “Laptop assistant”']],
            array_map(static fn (array $r): array => [$r['action'], $r['description']], $this->rows()),
        );
    }

    public function testOnlyACuratorsRowsCountAsCuratingTheNote(): void
    {
        $note = $this->kb->note($this->writer, 'Reading list', 'Books.');
        $this->writer->update($note, null, 'More books.', null, null, actor: Note::ACTOR_HUMAN, operation: NoteRevision::OP_EDIT);
        $this->writer->propose($note, $this->kb->agentToken, null, 'Fewer books.', null, applyTags: false);

        $curated = fn (): int => (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM curator_log cl WHERE cl.note_id = :id AND '.CuratorLogEntry::curationWorkOnly('cl'),
            ['id' => $note->getId()],
        );
        self::assertSame(0, $curated(), 'The owner saving a note is not the curator having read it');

        $this->writer->propose($note, $this->kb->curatorToken, null, null, null, patch: [['find' => 'More', 'replace' => 'Many more']], applyTags: false);
        self::assertSame(1, $curated());
    }

    public function testAVerdictOnACuratorsWorkSettlesTheNoteTheDayItIsGiven(): void
    {
        $note = $this->kb->note($this->writer, 'Contested', 'Body.');
        $proposal = $this->writer->proposeDelete($note, $this->kb->curatorToken, 'Looks redundant.');
        $this->em->getConnection()->executeStatement("UPDATE curator_log SET created_at = datetime('now', '-10 days')");
        $this->verdicts->rejectProposal($proposal, self::VERDICT);

        $last = $this->em->getConnection()->fetchOne(
            'SELECT MAX(created_at) FROM curator_log cl WHERE cl.note_id = :id AND '.CuratorLogEntry::curationWorkOnly('cl'),
            ['id' => $note->getId()],
        );
        self::assertGreaterThan((new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'), $last, 'Rejected today: the note rests from today');

        $other = $this->kb->note($this->writer, 'Agent work', 'Body.');
        $agents = $this->writer->proposeDelete($other, $this->kb->agentToken, 'Looks redundant.');
        $this->verdicts->rejectProposal($agents, self::VERDICT);
        self::assertFalse($this->em->getConnection()->fetchOne(
            'SELECT 1 FROM curator_log cl WHERE cl.note_id = :id AND '.CuratorLogEntry::curationWorkOnly('cl'),
            ['id' => $other->getId()],
        ), 'A verdict on another assistant\'s proposal is not curation');
    }

    public function testMergeReasoningIsForgottenWithEitherNote(): void
    {
        $absorb = $this->kb->note($this->writer, 'Absorbed', 'One.');
        $keeper = $this->kb->note($this->writer, 'Keeper', 'Two.');
        $merge = $this->writer->proposeMerge($absorb, $keeper, $this->kb->agentToken, null, 'Same topic.');
        $this->writer->propose($absorb, $this->kb->agentToken, null, null, null, comment: 'Keeper holds PRIVATEMARKER.');
        $this->verdicts->rejectProposal($merge, self::VERDICT);

        self::getContainer()->get(\App\Service\NoteRevisions::class)->forget((int) $keeper->getId());

        self::assertStringNotContainsString('PRIVATEMARKER', implode("\n", array_column($this->rows(), 'description')));
    }

    public function testACuratorsAppliedEditIsStillOneRow(): void
    {
        $note = $this->kb->note($this->writer, 'Recipe', 'Two eggs.');
        $this->writer->propose($note, $this->kb->curatorToken, null, null, null, comment: 'fixed the count', patch: [['find' => 'Two', 'replace' => 'Three']], applyTags: false);

        $rows = $this->rows();
        self::assertSame(['create', 'edit'], array_column($rows, 'action'));
        self::assertSame('curator', $rows[1]['actor']);
        self::assertSame('Edited body of “Recipe” — fixed the count', $rows[1]['description']);
    }

    public function testAnAmendedApprovalIsOneRow(): void
    {
        $note = $this->writer->create($this->kb->agentToken, 'Trip', 'Rome.', Note::SOURCE_AGENT, null, [], enrich: null)['note'];
        $this->verdicts->approveNote($note, self::VERDICT, $note->getVersion(), amend: function () use ($note): bool {
            $this->writer->update($note, null, 'Rome and Naples.', null, null, actor: Note::ACTOR_HUMAN, operation: NoteRevision::OP_EDIT);

            return true;
        });

        self::assertSame(['create-proposed', 'approved'], array_column($this->rows(), 'action'));
        self::assertStringEndsWith('with their own edits.', $this->rows()[1]['description']);
    }

    public function testAnUploadRefusedHalfwayStillRecordsWhatArrived(): void
    {
        $journal = self::getContainer()->get(Journal::class);
        try {
            $journal->batch(
                function (): void {
                    $this->writer->create(null, 'Arrived', 'Body.', Note::SOURCE_UPLOAD, null, [], enrich: null);
                    throw new \RuntimeException('the memex is full');
                },
                static fn (array $notes): CuratorLogEntry => new CuratorLogEntry('operator', CuratorLogEntry::ACTION_IMPORT, 'Uploaded '.count($notes).' notes'),
            );
            self::fail('The failure must reach the caller');
        } catch (\RuntimeException $e) {
            self::assertSame('the memex is full', $e->getMessage());
        }

        self::assertSame(['import'], array_column($this->rows(), 'action'));
    }

    public function testEnrichmentOnSaveIsNotItsOwnRow(): void
    {
        $this->writer->create(null, 'Described', 'Body.', Note::SOURCE_MANUAL, null, [], enrich: EmbeddingSpend::Metered);

        self::assertSame(['create'], array_column($this->rows(), 'action'));
    }
}
