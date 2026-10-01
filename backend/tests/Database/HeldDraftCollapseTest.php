<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\EditProposal;
use App\Service\NoteWriter;
use App\Service\ReviewVerdicts;
use App\Tests\Support\KbFixture;

/**
 * One held item per note per connection.
 *
 * Found on 2026-09-08 on one note: five full-body proposals from one
 * connection accumulated over four hours, each carrying the note as it stood
 * when it was written. Approving the newest left four cards offering to revert
 * it, and nothing on any card said the five were versions of one change. The
 * operator's reading is the fix — "until user approves the document, it belongs
 * to the author... I don't want to see multiple versions of it in inbox" — so a
 * further edit revises the draft already in review.
 *
 * What that must NOT do is lose work. A second connection keeps its own draft
 * (its author cannot see the first), and an author's own follow-up folds field
 * by field rather than replacing what it does not mention.
 */
final class HeldDraftCollapseTest extends DatabaseTestCase
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

    private function held(int $noteId): array
    {
        return $this->em->getRepository(EditProposal::class)->findBy(
            ['note' => $noteId, 'status' => EditProposal::STATUS_HELD],
            ['id' => 'ASC'],
        );
    }

    /** The reported bug, at its own scale. */
    public function testFourSuccessiveBodiesFromOneConnectionAreOneItemCarryingTheNewest(): void
    {
        $note = $this->kb->note($this->writer, 'Record', 'Version zero.', []);

        for ($i = 1; $i <= 4; ++$i) {
            $this->writer->propose(
                $note, $this->kb->agentToken, null, "Version $i.", null,
                applyTags: false,
            );
        }

        $held = $this->held((int) $note->getId());
        self::assertCount(1, $held, 'Four rewrites of one note are one document awaiting review');
        self::assertSame('Version 4.', $held[0]->getProposedBodyMd());

        $this->verdicts->approveProposal($held[0], self::VERDICT, $this->reviewSnapshot($held[0]));

        self::assertSame('Version 4.', $note->getBodyMd());
        self::assertSame([], $this->held((int) $note->getId()), 'Nothing is left to revert it');
    }

    /**
     * The revert itself, which is what made the duplicates worth fixing rather
     * than merely untidy: without the collapse, approving the newest and then
     * an older one puts the note back.
     */
    public function testAnOlderBodyCannotBeApprovedAfterANewerOne(): void
    {
        $note = $this->kb->note($this->writer, 'Record', 'Version zero.', []);

        $this->writer->propose($note, $this->kb->agentToken, null, 'Early draft.', null, applyTags: false);
        $this->writer->propose($note, $this->kb->agentToken, null, 'What was decided.', null, applyTags: false);

        foreach ($this->held((int) $note->getId()) as $proposal) {
            $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));
        }

        self::assertSame('What was decided.', $note->getBodyMd());
    }

    public function testAFollowUpFoldsFieldByFieldInsteadOfDiscardingWhatItDoesNotMention(): void
    {
        $note = $this->kb->note($this->writer, 'Record', 'Version zero.', []);

        $this->writer->propose(
            $note, $this->kb->agentToken, null, 'The rewritten body.', null,
            applyTags: false, summary: 'A description.',
        );
        $this->writer->propose(
            $note, $this->kb->agentToken, null, null, ['alpha', 'bravo'],
            applyTags: false,
        );

        $held = $this->held((int) $note->getId());
        self::assertCount(1, $held);
        self::assertSame('The rewritten body.', $held[0]->getProposedBodyMd(), 'A tag change does not drop the rewrite');
        self::assertSame('A description.', $held[0]->getProposedSummary());
        self::assertSame(['alpha', 'bravo'], $held[0]->getProposedTags());
        self::assertNotNull($held[0]->getRevisedAt());
    }

    public function testAnchorsFromOneConnectionAccumulateInOrderOnTheOneDraft(): void
    {
        $note = $this->kb->note($this->writer, 'Record', "Microsoft is pending.\n\nApple is pending.", []);

        $this->writer->propose(
            $note, $this->kb->agentToken, null, null, null,
            applyTags: false,
            patch: [['find' => 'Microsoft is pending.', 'replace' => 'Microsoft is live.']],
        );
        $this->writer->propose(
            $note, $this->kb->agentToken, null, null, null,
            applyTags: false,
            patch: [['find' => 'Apple is pending.', 'replace' => 'Apple is live.']],
        );

        $held = $this->held((int) $note->getId());
        self::assertCount(1, $held);
        self::assertCount(2, $held[0]->getProposedPatch());

        $this->verdicts->approveProposal($held[0], self::VERDICT, $this->reviewSnapshot($held[0]));

        self::assertSame("Microsoft is live.\n\nApple is live.", $note->getBodyMd());
    }

    /**
     * The composed patch is checked at FILE time, so an author whose second
     * anchor collides with its own first one is told while it can still fix it
     * — not at the operator's approval, where a refusal is somebody else's
     * problem.
     */
    public function testAnAnchorThatCollidesWithTheDraftsOwnIsRefusedWhenItIsFiled(): void
    {
        $note = $this->kb->note($this->writer, 'Record', 'Microsoft is pending.', []);

        $this->writer->propose(
            $note, $this->kb->agentToken, null, null, null,
            applyTags: false,
            patch: [['find' => 'Microsoft is pending.', 'replace' => 'Microsoft is live.']],
        );

        $this->expectException(\App\Service\NotePatchException::class);
        $this->writer->propose(
            $note, $this->kb->agentToken, null, null, null,
            applyTags: false,
            patch: [['find' => 'Microsoft is pending.', 'replace' => 'Microsoft was deferred.']],
        );
    }

    public function testASecondConnectionKeepsItsOwnDraft(): void
    {
        $note = $this->kb->note($this->writer, 'Record', 'Version zero.', []);

        $this->writer->propose($note, $this->kb->agentToken, null, 'From the first agent.', null, applyTags: false);
        $this->writer->propose($note, $this->kb->otherAgentToken, null, 'From the second agent.', null, applyTags: false);

        self::assertCount(2, $this->held((int) $note->getId()), 'Folding across authors would discard work its author cannot see');
    }

    public function testADeleteFromTheSameConnectionSupersedesItsHeldEdit(): void
    {
        $note = $this->kb->note($this->writer, 'Record', 'Version zero.', []);

        $this->writer->propose($note, $this->kb->agentToken, null, 'A rewrite.', null, applyTags: false);
        $this->writer->proposeDelete($note, $this->kb->agentToken, 'Superseded by note 12.');

        $held = $this->held((int) $note->getId());
        self::assertCount(1, $held);
        self::assertSame(EditProposal::TYPE_DELETE, $held[0]->getType());
    }

    /**
     * A comment on its own is a staleness report — except from an author who is
     * mid-draft on that note, for whom it is the rationale of the edit they are
     * writing. Reading it as a report would supersede the rewrite.
     */
    public function testACommentFromAnAuthorMidDraftRewritesTheDraftsRationale(): void
    {
        $note = $this->kb->note($this->writer, 'Record', 'Version zero.', []);

        $this->writer->propose(
            $note, $this->kb->agentToken, null, 'A rewrite.', null, 'First reason.',
            applyTags: false,
        );
        $this->writer->propose(
            $note, $this->kb->agentToken, null, null, null, 'The reason, restated.',
            applyTags: false,
        );

        $held = $this->held((int) $note->getId());
        self::assertCount(1, $held);
        self::assertSame(EditProposal::TYPE_EDIT, $held[0]->getType());
        self::assertSame('A rewrite.', $held[0]->getProposedBodyMd());
        self::assertSame('The reason, restated.', $held[0]->getComment());
    }

    /** With no draft to attach to, a comment is still a report. */
    public function testACommentWithNoDraftIsStillAReport(): void
    {
        $note = $this->kb->note($this->writer, 'Record', 'Version zero.', []);

        $this->writer->propose(
            $note, $this->kb->agentToken, null, null, null, 'This note is out of date.',
            applyTags: false,
        );

        $held = $this->held((int) $note->getId());
        self::assertCount(1, $held);
        self::assertSame(EditProposal::TYPE_REPORT, $held[0]->getType());
    }

    /**
     * A merge carries the keeper's body, so reading a follow-up comment as a
     * REPORT replaced a drafted rewrite with nothing at all (Codex,
     * 2026-09-08). Any held kind takes the rationale; only a note with nothing
     * in review gets a report.
     */
    public function testACommentDoesNotTurnAHeldMergeIntoABodilessReport(): void
    {
        $absorb = $this->kb->note($this->writer, 'The twin', 'Twin body.', []);
        $keeper = $this->kb->note($this->writer, 'The keeper', 'Keeper body.', []);

        $this->writer->proposeMerge(
            $absorb, $keeper, $this->kb->agentToken,
            'The merged rewrite, written once.', 'These are one note.',
        );
        $this->writer->propose(
            $absorb, $this->kb->agentToken, null, null, null,
            'Why they are the same document.',
            applyTags: false,
        );

        $held = $this->held((int) $absorb->getId());
        self::assertCount(1, $held);
        self::assertSame(EditProposal::TYPE_MERGE, $held[0]->getType());
        self::assertSame('The merged rewrite, written once.', $held[0]->getProposedBodyMd());
        self::assertSame('Why they are the same document.', $held[0]->getComment());
    }

    /**
     * Rows filed before the one-item rule are not swept by its migration, so
     * the author's next write has to clear all of them. Leaving the older ones
     * is what the whole change exists to stop (Codex, 2026-09-08).
     */
    public function testOlderRowsFiledBeforeTheRuleAreClearedByTheAuthorsNextWrite(): void
    {
        $note = $this->kb->note($this->writer, 'Record', 'Version zero.', []);

        // Two rows on one note, as only a pre-rule filing could leave them.
        foreach (['First attempt.', 'Second attempt.'] as $body) {
            $legacy = new EditProposal($note, $this->kb->agentToken, null, $body, null, null);
            $this->em->persist($legacy);
        }
        $this->em->flush();
        self::assertCount(2, $this->held((int) $note->getId()));

        $this->writer->propose($note, $this->kb->agentToken, null, 'What it should say.', null, applyTags: false);

        $held = $this->held((int) $note->getId());
        self::assertCount(1, $held, 'Nothing older is left to revert the one the operator decides on');
        self::assertSame('What it should say.', $held[0]->getProposedBodyMd());
    }

    /**
     * The same, on the path that applies rather than holds: a curator patch
     * must not leave a second stale body behind it.
     */
    public function testACuratorsImmediateEditClearsEveryOneOfItsOwnHeldRows(): void
    {
        $note = $this->kb->note($this->writer, 'Record', 'Microsoft is pending.', []);

        foreach (['First attempt.', 'Second attempt.'] as $body) {
            $legacy = new EditProposal($note, $this->kb->curatorToken, null, $body, null, null);
            $this->em->persist($legacy);
        }
        $this->em->flush();

        $this->writer->propose(
            $note, $this->kb->curatorToken, null, null, null,
            applyTags: false,
            patch: [['find' => 'Microsoft is pending.', 'replace' => 'Microsoft is live.']],
        );

        self::assertSame('Microsoft is live.', $note->getBodyMd());
        self::assertSame([], $this->held((int) $note->getId()));
    }

    /**
     * A curator's report leaves a journal row pointing at the proposal. Removing
     * that proposal without unhooking the row killed the next flush with "a new
     * entity was found" (Codex, 2026-09-08).
     */
    public function testSupersedingAProposalTheJournalPointsAtDoesNotBreakTheNextFlush(): void
    {
        $note = $this->kb->note($this->writer, 'Record', 'Version zero.', []);

        $report = $this->writer->report($note, $this->kb->curatorToken, 'This is stale.');
        $entries = $this->em->getRepository(\App\Entity\CuratorLogEntry::class)->findBy(['proposal' => $report]);
        self::assertNotSame([], $entries, 'The fixture only works while a report writes a linked journal row');

        $this->writer->proposeDelete($note, $this->kb->curatorToken, 'Superseded by note 12.');
        $this->em->flush();

        self::assertNull($entries[0]->getProposal());
        $held = $this->held((int) $note->getId());
        self::assertCount(1, $held);
        self::assertSame(EditProposal::TYPE_DELETE, $held[0]->getType());
    }

    /**
     * A curator's anchored edit applies on the spot. Leaving its own earlier
     * full-body draft held would leave a card offering to revert the change its
     * author had just made.
     */
    public function testACuratorsImmediateEditClearsItsOwnHeldDraft(): void
    {
        $note = $this->kb->note($this->writer, 'Record', 'Microsoft is pending.', []);

        // Held, because a whole body from a curator is (C-9).
        $this->writer->propose($note, $this->kb->curatorToken, null, 'A whole new body.', null, applyTags: false);
        self::assertCount(1, $this->held((int) $note->getId()));

        $this->writer->propose(
            $note, $this->kb->curatorToken, null, null, null,
            applyTags: false,
            patch: [['find' => 'Microsoft is pending.', 'replace' => 'Microsoft is live.']],
        );

        self::assertSame('Microsoft is live.', $note->getBodyMd());
        self::assertSame([], $this->held((int) $note->getId()));
    }
}
