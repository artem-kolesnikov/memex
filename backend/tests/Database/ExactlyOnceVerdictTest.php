<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\EditProposal;
use App\Service\AlreadyDecidedException;
use App\Service\NoteWriter;
use App\Service\ReviewVerdicts;
use App\Tests\Support\KbFixture;

/**
 * A verdict does its work once.
 *
 * Approving was check-then-act with nothing in between: the controller looked
 * the item up, saw `applied_at` was null, and applied it. Two overlapping
 * requests both passed that check, doubling revisions and log rows for as long
 * as it took anyone to notice. The UI disables
 * the approve button while a verdict is in flight, which is a real guard and is
 * also only a client: two tabs, a duplicated POST or a retry after a timeout
 * all get past it.
 */
final class ExactlyOnceVerdictTest extends DatabaseTestCase
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

    public function testApprovingTheSameEditProposalTwiceIsRefusedTheSecondTime(): void
    {
        $note = $this->kb->note($this->writer, 'Original title', 'Original body.');
        $proposal = $this->writer->propose(
            $note, $this->kb->agentToken,
            'Proposed title', 'Proposed body.', null, applyTags: false,
        );

        $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));
        self::assertSame('Proposed body.', $note->getBodyMd());

        $this->expectException(AlreadyDecidedException::class);
        $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));
    }

    public function testTheClaimItselfRefusesAnItemAnotherRequestAlreadyHolds(): void
    {
        // The true concurrent shape, which the two tests above cannot reach
        // sequentially: both requests loaded the row while it was held, and one
        // has just claimed it. From the loser's side the entity is perfectly
        // valid — only the database knows.
        $note = $this->kb->note($this->writer, 'Original title', 'Original body.');
        $proposal = $this->writer->propose(
            $note, $this->kb->agentToken,
            'Proposed title', 'Proposed body.', null, applyTags: false,
        );

        // The winner's claim, as the other request's transaction would leave it.
        $this->em->getConnection()->executeStatement(
            'UPDATE edit_proposals SET applied_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $proposal->getId()]
        );

        try {
            $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));
            self::fail('The loser of the race applied the proposal anyway');
        } catch (AlreadyDecidedException) {
            // Expected.
        }

        self::assertSame('Original body.', $note->getBodyMd(), 'The loser must not have applied anything');
    }

    public function testAFailedApplyReleasesTheClaimSoTheItemStaysReviewable(): void
    {
        // The claim must not become a way to lock an item nobody can act on.
        // If the work throws, the conditional UPDATE rolls back with it.
        $note = $this->kb->note($this->writer, 'Original title', 'Original body.');
        $proposal = $this->writer->propose(
            $note, $this->kb->agentToken,
            'Proposed title', 'Proposed body.', null, applyTags: false,
        );
        $proposalId = $proposal->getId();

        // Break the apply from underneath: the note refuses to be written,
        // so applyProposal throws.
        $conn = $this->em->getConnection();
        $conn->executeStatement("CREATE TEMP TRIGGER refuse_apply BEFORE UPDATE ON notes BEGIN SELECT RAISE(ABORT, 'apply refused'); END");
        $this->em->clear();
        $broken = $this->em->getRepository(EditProposal::class)->find($proposalId);
        self::assertNotNull($broken);

        try {
            $this->verdicts->approveProposal($broken, self::VERDICT, $this->reviewSnapshot($broken));
            self::fail('Expected the apply to fail');
        } catch (AlreadyDecidedException $e) {
            self::fail('It was claimed, not applied: '.$e->getMessage());
        } catch (\Throwable) {
            // Expected — the apply itself failed.
        } finally {
            $conn->executeStatement('DROP TRIGGER temp.refuse_apply');
        }

        self::assertNull(
            $this->em->getConnection()->fetchOne('SELECT applied_at FROM edit_proposals WHERE id = :id', ['id' => $proposalId]),
            'A failed apply must leave the item reviewable, not claimed forever'
        );
    }

    private function notesTitled(string $title): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM notes WHERE title = :t', ['t' => $title]);
    }
}
