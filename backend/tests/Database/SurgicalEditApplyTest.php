<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\NoteWriter;
use App\Service\ReviewVerdicts;
use App\Tests\Support\KbFixture;

/**
 * Held edits are kept as the words they change, however they were sent.
 *
 * On 2026-10-13 two agents worked on one project record before the owner
 * reviewed either: one whole body or paragraph-sized patch from each, and
 * approving one either undid the other or stopped it fitting. Narrowed, each
 * edit carries only its own words, so both land.
 */
final class SurgicalEditApplyTest extends DatabaseTestCase
{
    private NoteWriter $writer;
    private ReviewVerdicts $verdicts;
    private KbFixture $kb;

    private const VERDICT = ['comment' => null, 'precedent' => false];

    private const RECORD = "Status: on track. Stage 1 is merged.\n\n"
        ."- The concept and the tokens are in the design folder.\n"
        ."- Previews sign in with Google, once the client exists.\n"
        ."- Mail goes through Postmark.\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = self::getContainer()->get(NoteWriter::class);
        $this->verdicts = self::getContainer()->get(ReviewVerdicts::class);
        $this->kb = new KbFixture(self::getContainer());
    }

    public function testTwoWholeBodiesFromTwoAgentsBothLandInsteadOfTheSecondUndoingTheFirst(): void
    {
        $note = $this->kb->note($this->writer, 'Record', self::RECORD, []);
        $designer = $this->writer->propose($note, $this->kb->agentToken, null,
            str_replace('in the design folder.', 'in the design folder. The palette lives in one tokens file.', self::RECORD), null, applyTags: false);
        $orchestrator = $this->writer->propose($note, $this->kb->otherAgentToken, null,
            str_replace('with Google, once the client exists.', 'through a mock provider; real Google is checked after merge.', self::RECORD), null, applyTags: false);

        self::assertNotNull($designer->getProposedPatch(), 'A whole body is applied as the words it changes');
        self::assertStringContainsString('The palette lives in one tokens file.', (string) $designer->getProposedBodyMd(), 'and kept as sent, for when they no longer fit');

        $this->verdicts->approveProposal($orchestrator, self::VERDICT, $this->reviewSnapshot($orchestrator));
        $this->verdicts->approveProposal($designer, self::VERDICT, $this->reviewSnapshot($designer));

        self::assertSame(
            "Status: on track. Stage 1 is merged.\n\n"
            ."- The concept and the tokens are in the design folder. The palette lives in one tokens file.\n"
            ."- Previews sign in through a mock provider; real Google is checked after merge.\n"
            ."- Mail goes through Postmark.\n",
            $note->getBodyMd(),
            "Approving the designer's body after the orchestrator's kept the orchestrator's change",
        );
    }

    public function testAParagraphSizedPatchStillAppliesAfterSomeoneChangedAnotherWordOfThatParagraph(): void
    {
        $note = $this->kb->note($this->writer, 'Record', self::RECORD, []);
        $line = 'Status: on track. Stage 1 is merged.';
        $first = $this->writer->propose($note, $this->kb->agentToken, null, null, null, applyTags: false,
            patch: [['find' => $line, 'replace' => 'Status: blocked. Stage 1 is merged.']]);
        $second = $this->writer->propose($note, $this->kb->otherAgentToken, null, null, null, applyTags: false,
            patch: [['find' => $line, 'replace' => 'Status: on track. Stage 1 is merged and deployed.']]);

        $this->verdicts->approveProposal($first, self::VERDICT, $this->reviewSnapshot($first));
        $this->verdicts->approveProposal($second, self::VERDICT, $this->reviewSnapshot($second));

        self::assertStringStartsWith("Status: blocked. Stage 1 is merged and deployed.\n", $note->getBodyMd());
    }

    public function testWhatAHeldEditDoesToTheNoteItWasWrittenAgainstIsExactlyWhatWasSent(): void
    {
        $note = $this->kb->note($this->writer, 'Record', self::RECORD, []);
        $sent = str_replace(['on track', "- Mail goes through Postmark.\n"], ['at risk', "- Mail goes through Postmark.\n- Invites expire after a week.\n"], self::RECORD);

        $proposal = $this->writer->propose($note, $this->kb->agentToken, null, $sent, null, applyTags: false);
        $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));

        self::assertSame($sent, $note->getBodyMd());
    }

    /**
     * A body's revision rule holds when the body is narrowed: anchors sent
     * next were written against the note, not against the draft, so they
     * replace it (Codex, 2026-10-14).
     */
    public function testAnAuthorsPatchAfterItsOwnHeldBodyReplacesThatBody(): void
    {
        $note = $this->kb->note($this->writer, 'Record', self::RECORD, []);
        $this->writer->propose($note, $this->kb->agentToken, null, str_replace('on track', 'at risk', self::RECORD), null, applyTags: false);

        $revised = $this->writer->propose($note, $this->kb->agentToken, null, null, null, applyTags: false,
            patch: [['find' => 'Status: on track.', 'replace' => 'Status: blocked.']]);
        $this->verdicts->approveProposal($revised, self::VERDICT, $this->reviewSnapshot($revised));

        self::assertStringStartsWith("Status: blocked. Stage 1 is merged.\n", $note->getBodyMd());
    }

    public function testARewriteIsKeptAsTheBodyItWasSentAs(): void
    {
        $note = $this->kb->note($this->writer, 'Record', self::RECORD, []);
        $rewrite = "Everything here moved to the new tracker on 2026-10-14.\n";

        $proposal = $this->writer->propose($note, $this->kb->agentToken, null, $rewrite, null, applyTags: false);

        self::assertSame($rewrite, $proposal->getProposedBodyMd());
        self::assertNull($proposal->getProposedPatch());
    }

    public function testACuratorsWholeBodyIsStillHeldForReview(): void
    {
        $note = $this->kb->note($this->writer, 'Record', self::RECORD, []);

        $proposal = $this->writer->propose($note, $this->kb->curatorToken, null, str_replace('on track', 'at risk', self::RECORD), null, applyTags: false);

        self::assertFalse($proposal->isApplied(), 'A whole body from a curator waits for the owner, as before');
        self::assertSame(self::RECORD, $note->getBodyMd());
    }
}
