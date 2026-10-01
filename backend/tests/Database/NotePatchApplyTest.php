<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\NotePatch;
use App\Service\NotePatchException;
use App\Service\NoteWriter;
use App\Service\ReviewKinds;
use App\Service\ReviewVerdicts;
use App\Tests\Support\KbFixture;

/**
 * Anchored edits through the review gate.
 *
 * The unit tests establish that a patch applies and what it refuses. These
 * establish the thing the feature was actually built for: **a patch is stored
 * unresolved and applied to the note as it stands when the operator approves
 * it**, which is what stops two held edits from destroying each other.
 *
 * That hazard was filed on 2026-08-21 and closed by this. It
 * is not a hypothetical — it was found by filing two proposals on one note in
 * one session and watching the second silently revert the first.
 */
final class NotePatchApplyTest extends DatabaseTestCase
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

    public function testAPatchIsHeldLikeAnyOtherEditAndChangesNothingUntilApproved(): void
    {
        $note = $this->kb->note($this->writer, 'Record', "Alpha stands.\n\nBravo stands.", []);

        $proposal = $this->writer->propose(
            $note,
            $this->kb->agentToken,
            null,
            null,
            null,
            applyTags: false,
            patch: [['find' => 'Alpha stands.', 'replace' => 'Alpha has moved.']],
        );

        self::assertSame("Alpha stands.\n\nBravo stands.", $note->getBodyMd(), 'The gate holds a patch like any other edit');
        self::assertNull($proposal->getProposedBodyMd(), 'A patch is stored as operations, not resolved into a body');
        self::assertSame([['find' => 'Alpha stands.', 'replace' => 'Alpha has moved.']], $proposal->getProposedPatch());

        $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));

        self::assertSame("Alpha has moved.\n\nBravo stands.", $note->getBodyMd());
    }

    /**
     * The hazard, closed.
     *
     * Two proposals held at once, each touching a different sentence. As
     * full-body edits the second would carry a snapshot taken before the first
     * applied, so approving both in order would silently revert the first —
     * with nothing in the inbox saying the two overlapped.
     *
     * TWO CONNECTIONS, because that is the case this protects. One connection's
     * second edit revises the draft it already has in review rather than
     * queueing a second card ({@see \App\Tests\Database\HeldDraftCollapseTest}).
     */
    public function testTwoHeldPatchesOnOneNoteBothLandInsteadOfOverwriting(): void
    {
        $note = $this->kb->note($this->writer, 'Record', "Microsoft is pending.\n\nApple is pending.", []);

        $first = $this->writer->propose(
            $note, $this->kb->agentToken, null, null, null,
            applyTags: false,
            patch: [['find' => 'Microsoft is pending.', 'replace' => 'Microsoft is live.']],
        );
        $second = $this->writer->propose(
            $note, $this->kb->otherAgentToken, null, null, null,
            applyTags: false,
            patch: [['find' => 'Apple is pending.', 'replace' => 'Apple is live.']],
        );

        $this->verdicts->approveProposal($first, self::VERDICT, $this->reviewSnapshot($first));
        $this->verdicts->approveProposal($second, self::VERDICT, $this->reviewSnapshot($second));

        self::assertSame("Microsoft is live.\n\nApple is live.", $note->getBodyMd());
    }

    /**
     * And when they DO overlap, the second is refused rather than winning
     * silently. A visible failure is the whole improvement: the operator can
     * ask the author to rewrite it, which was never possible before because
     * nothing announced the collision.
     */
    public function testAPatchWhoseTextHasMovedIsRefusedAtApprovalAndStaysHeld(): void
    {
        $note = $this->kb->note($this->writer, 'Record', 'Microsoft is pending.', []);

        $first = $this->writer->propose(
            $note, $this->kb->agentToken, null, null, null,
            applyTags: false,
            patch: [['find' => 'Microsoft is pending.', 'replace' => 'Microsoft is live.']],
        );
        $second = $this->writer->propose(
            $note, $this->kb->otherAgentToken, null, null, null,
            applyTags: false,
            patch: [['find' => 'Microsoft is pending.', 'replace' => 'Microsoft was deferred.']],
        );

        $this->verdicts->approveProposal($first, self::VERDICT, $this->reviewSnapshot($first));

        try {
            $this->verdicts->approveProposal($second, self::VERDICT, $this->reviewSnapshot($second));
            self::fail('The second patch should not have applied to text that is no longer there');
        } catch (NotePatchException $e) {
            self::assertStringContainsString('not in the note as it now stands', $e->getMessage());
        }

        self::assertSame('Microsoft is live.', $note->getBodyMd(), "The first author's work survives");
        self::assertSame(1, $this->heldProposalCount(), 'The refused proposal is still held for the operator to decide');
    }

    /** A mistyped anchor is refused when it is FILED, not left to fail on somebody else's screen. */
    public function testAPatchThatDoesNotFitIsRefusedWhenItIsProposed(): void
    {
        $note = $this->kb->note($this->writer, 'Record', 'The body as written.', []);

        $this->expectException(NotePatchException::class);

        $this->writer->propose(
            $note, $this->kb->agentToken, null, null, null,
            applyTags: false,
            patch: [['find' => 'text that was never there', 'replace' => 'x']],
        );
    }

    public function testABodyAndAPatchTogetherAreRefused(): void
    {
        $note = $this->kb->note($this->writer, 'Record', 'The body.', []);

        $this->expectException(\InvalidArgumentException::class);

        $this->writer->propose(
            $note, $this->kb->agentToken, null, 'A whole new body.', null,
            applyTags: false,
            patch: [['find' => 'The body.', 'replace' => 'Something else.']],
        );
    }

    /**
     * The inbox filter exists so a rewrite is not approved among twenty
     * summaries. A patch that did not answer to `content` would slip through
     * exactly that guard.
     */
    public function testAPatchIsFiledAsAContentChange(): void
    {
        $note = $this->kb->note($this->writer, 'Record', 'The body as written.', []);

        $proposal = $this->writer->propose(
            $note, $this->kb->agentToken, null, null, null,
            applyTags: false,
            patch: [['find' => 'as written', 'replace' => 'as amended']],
        );

        self::assertContains(ReviewKinds::CONTENT, ReviewKinds::ofProposal($proposal));
    }

    /** A curator's patch applies immediately, like any other curator edit. */
    public function testACuratorsPatchAppliesImmediately(): void
    {
        $note = $this->kb->note($this->writer, 'Record', 'Curators edit directly.', []);

        $proposal = $this->writer->propose(
            $note, $this->kb->curatorToken, null, null, null,
            applyTags: false,
            patch: [['find' => 'directly', 'replace' => 'immediately']],
        );

        self::assertTrue($proposal->isApplied());
        self::assertSame('Curators edit immediately.', $note->getBodyMd());
    }

    /**
     * A revision is what makes a patch undoable. Without one, the smaller the
     * edit the harder it would be to reverse, which is backwards.
     */
    public function testAnAppliedPatchLeavesARevisionToUndo(): void
    {
        $note = $this->kb->note($this->writer, 'Record', 'Before the patch.', []);
        $proposal = $this->writer->propose(
            $note, $this->kb->agentToken, null, null, null,
            applyTags: false,
            patch: [['find' => 'Before', 'replace' => 'After']],
        );
        $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));

        $revisions = $this->em->getConnection()->fetchAllAssociative(
            'SELECT body_md FROM note_revisions WHERE note_id = ?',
            [$note->getId()]
        );

        self::assertNotEmpty($revisions);
        self::assertSame('Before the patch.', $revisions[array_key_last($revisions)]['body_md']);
    }

    /**
     * A long note is the case this exists for, so it is worth one test that
     * actually is one: the patch names 60 characters of a 40,000-character
     * body and everything else is byte-identical afterwards.
     */
    public function testOnlyTheAnchoredTextChangesInALongNote(): void
    {
        $filler = str_repeat("A paragraph that must survive untouched.\n\n", 1000);
        $body = $filler.'The one sentence that is wrong.'.$filler;
        $note = $this->kb->note($this->writer, 'Long record', $body, []);

        $proposal = $this->writer->propose(
            $note, $this->kb->agentToken, null, null, null,
            applyTags: false,
            patch: [['find' => 'The one sentence that is wrong.', 'replace' => 'The one sentence, corrected.']],
        );
        $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));

        self::assertSame($filler.'The one sentence, corrected.'.$filler, $note->getBodyMd());
        self::assertSame(
            NotePatch::apply($body, [['find' => 'The one sentence that is wrong.', 'replace' => 'The one sentence, corrected.']]),
            $note->getBodyMd()
        );
    }

    private function heldProposalCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM edit_proposals WHERE status = 'held'"
        );
    }
}
