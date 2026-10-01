<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\EditProposal;
use App\Service\NoteWriter;
use App\Service\ProposalRevisedException;
use App\Service\ReviewVerdicts;
use App\Storage\DataDir;
use App\Storage\VaultContext;
use App\Tests\Support\KbFixture;
use Doctrine\DBAL\Connection;

/**
 * The two races left open when one item per note per connection shipped
 * (PR #196, 2026-09-08), both knowingly.
 *
 * The author's side is read-then-write: `propose()` looked up the held draft
 * and folded into it with nothing in between, so two writes from one
 * connection could each find no draft and create a row, or each fold onto a
 * copy the other had replaced.
 *
 * The operator's side is the sharper one. `approve` loaded a proposal, then
 * claimed it on `applied_at IS NULL` alone — so a revise landing in that
 * window was applied as the OLDER text and its row deleted. It is the one way
 * this design could still lose an author's work silently.
 */
final class DraftRaceTest extends DatabaseTestCase
{
    private NoteWriter $writer;
    private ReviewVerdicts $verdicts;
    private KbFixture $kb;

    private const VERDICT = ['comment' => null, 'precedent' => false];

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = self::getContainer()->get(NoteWriter::class);
        $this->verdicts = self::getContainer()->get(ReviewVerdicts::class);
        $this->kb = new KbFixture(self::getContainer());
    }

    public function testAReviseLandingBetweenTheLoadAndTheClaimIsRefusedNotOverwritten(): void
    {
        $note = $this->kb->note($this->writer, 'Original title', 'Original body.');
        $proposal = $this->writer->propose(
            $note, $this->kb->agentToken,
            null, 'The text the operator read.', null, applyTags: false,
        );
        $id = $proposal->getId();

        $other = new \Doctrine\ORM\EntityManager($this->em->getConnection(), $this->em->getConfiguration());
        $newer = $other->find(EditProposal::class, $id);
        $newer->revise(null, 'The text they meant to send.', null, null, null, null, null);
        $other->flush();

        try {
            $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));
            self::fail('The stale text was applied over a revision nobody read');
        } catch (ProposalRevisedException) {
            // Expected.
        }

        self::assertSame('Original body.', $note->getBodyMd(), 'Nothing may have been applied');
        self::assertSame(
            'The text they meant to send.',
            $this->em->getConnection()->fetchOne('SELECT proposed_body_md FROM edit_proposals WHERE id = :id', ['id' => $id]),
            'The revision has to still be there to review',
        );
        self::assertNull(
            $this->em->getConnection()->fetchOne('SELECT applied_at FROM edit_proposals WHERE id = :id', ['id' => $id]) ?: null,
            'A refused verdict must leave the item reviewable',
        );
    }

    public function testTwoRevisionsInTheSameSecondCannotApproveStaleText(): void
    {
        $note = $this->kb->note($this->writer, 'Same second', 'Original body.');
        $proposal = $this->writer->propose(
            $note, $this->kb->agentToken,
            null, 'First thought.', null, applyTags: false,
        );
        $proposal->revise(null, 'The text the operator read.', null, null, null, null, null);
        self::assertSame(1, $proposal->getRevision());
        $stamp = new \DateTimeImmutable('2026-09-11 12:00:00');
        $clock = new \ReflectionProperty(EditProposal::class, 'revisedAt');
        $clock->setValue($proposal, $stamp);
        $this->em->flush();
        $this->em->refresh($proposal);
        $id = $proposal->getId();

        $other = new \Doctrine\ORM\EntityManager($this->em->getConnection(), $this->em->getConfiguration());
        $newer = $other->find(EditProposal::class, $id);
        $newer->revise(null, 'Newer text in the same second.', null, null, null, null, null);
        self::assertSame(2, $newer->getRevision());
        $clock->setValue($newer, $stamp);
        $other->flush();
        self::assertEquals($proposal->getRevisedAt(), $newer->getRevisedAt());

        try {
            $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));
            self::fail('Approval applied an older revision with an identical timestamp');
        } catch (ProposalRevisedException) {
        }
        self::assertSame('Original body.', $note->getBodyMd());
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT proposed_body_md, applied_at FROM edit_proposals WHERE id = :id',
            ['id' => $id],
        );
        self::assertIsArray($row);
        self::assertSame('Newer text in the same second.', $row['proposed_body_md']);
        self::assertNull($row['applied_at']);
    }

    public function testWritesUsingOldMetadataStillAdvanceTheCounter(): void
    {
        $note = $this->kb->note($this->writer, 'Old metadata', 'Original body.');
        $proposal = $this->writer->propose(
            $note, $this->kb->agentToken,
            null, 'First text.', null, applyTags: false,
        );
        $this->em->getConnection()->executeStatement(
            'UPDATE edit_proposals SET proposed_body_md = :body WHERE id = :id',
            ['body' => 'Old metadata wrote this.', 'id' => $proposal->getId()],
        );
        $this->expectException(ProposalRevisedException::class);
        $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));
    }

    public function testADraftNobodyTouchedStillApprovesNormally(): void
    {
        // The guard is `IS`, so the ordinary case — a draft
        // whose revised_at is NULL on both sides — must not be caught by it.
        $note = $this->kb->note($this->writer, 'Original title', 'Original body.');
        $proposal = $this->writer->propose(
            $note, $this->kb->agentToken,
            null, 'Proposed body.', null, applyTags: false,
        );

        $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));

        self::assertSame('Proposed body.', $note->getBodyMd());
    }

    public function testADraftAlreadyRevisedOnceApprovesAtTheVersionThatWasLoaded(): void
    {
        // revised_at is not null here, so the guard has to compare values
        // rather than merely test for null.
        $note = $this->kb->note($this->writer, 'Original title', 'Original body.');
        $this->writer->propose(
            $note, $this->kb->agentToken,
            null, 'First thought.', null, applyTags: false,
        );
        $proposal = $this->writer->propose(
            $note, $this->kb->agentToken,
            null, 'Second thought.', null, applyTags: false,
        );
        self::assertNotNull($proposal->getRevisedAt(), 'The fold has to have happened for this test to mean anything');

        $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));

        self::assertSame('Second thought.', $note->getBodyMd());
    }

    public function testCommentOnlyLookupAndReportShareOneTransaction(): void
    {
        $note = $this->kb->note($this->writer, 'Comment race', 'Original body.');
        $vault = self::getContainer()->get(DataDir::class)->vaultPath(self::getContainer()->get(VaultContext::class)->current()->key);
        $lookupTransaction = null;
        $reportTransaction = null;
        $reads = 0;
        $inserts = 0;
        \App\Tests\Support\SqlObservation::during($this->em->getConnection(), function (string $sql, array $params, ?int $transaction) use ($vault, &$lookupTransaction, &$reportTransaction, &$reads, &$inserts): void {
            if (str_starts_with($sql, 'SELECT') && str_contains($sql, 'FROM edit_proposals')) {
                ++$reads;
                $lookupTransaction ??= $transaction;
                self::assertTrue(\App\Tests\Support\SqlObservation::isWriteLocked($vault));
            }
            if (str_starts_with($sql, 'INSERT INTO edit_proposals')) {
                ++$inserts;
                $reportTransaction = $transaction;
            }
        }, function () use ($note): void {
            $report = $this->writer->propose(
                $note, $this->kb->agentToken,
                null, null, null, 'Needs investigation.', applyTags: false,
            );
            self::assertSame(EditProposal::TYPE_REPORT, $report->getType());
        });
        self::assertGreaterThanOrEqual(1, $reads);
        self::assertSame(1, $inserts);
        self::assertNotNull($reportTransaction);
        self::assertSame($lookupTransaction, $reportTransaction, 'The empty lookup must stay locked through report insertion');
    }

    public function testTwoConnectionsStillKeepTheirOwnDraft(): void
    {
        // The lock is on the note, so it serialises two authors as well —
        // what it must not do is collapse them into one item.
        $note = $this->kb->note($this->writer, 'Two authors', 'Original body.');
        $this->writer->propose(
            $note, $this->kb->agentToken,
            null, 'Mine.', null, applyTags: false,
        );
        $this->writer->propose(
            $note, $this->kb->otherAgentToken,
            null, 'Also mine.', null, applyTags: false,
        );

        self::assertCount(
            2,
            $this->em->getRepository(EditProposal::class)->findBy(
                ['note' => $note, 'status' => EditProposal::STATUS_HELD],
            ),
        );
    }
}
