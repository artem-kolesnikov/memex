<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\HeldElsewhereException;
use App\Service\NoteWriter;
use App\Service\ReviewVerdicts;
use App\Tests\Support\KbFixture;

/**
 * A sentence another connection's waiting edit changes is refused to everyone
 * else until the owner decides (operator, 2026-10-14), so every waiting edit
 * on a note can be approved or rejected on its own, in any order.
 */
final class SentenceLockTest extends DatabaseTestCase
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

    private function patch(\App\Entity\Note $note, \App\Entity\ApiToken $token, string $find, string $replace): \App\Entity\EditProposal
    {
        return $this->writer->propose($note, $token, null, null, null, applyTags: false, patch: [['find' => $find, 'replace' => $replace]]);
    }

    public function testASentenceAnotherAgentHasWaitingIsRefusedAndTheRefusalSaysWhose(): void
    {
        $note = $this->kb->note($this->writer, 'Record', self::RECORD, []);
        $this->patch($note, $this->kb->agentToken, 'Mail goes through Postmark.', 'Mail goes through Postmark, from hello@.');

        try {
            $this->patch($note, $this->kb->otherAgentToken, 'Mail goes through Postmark.', 'Mail goes through Resend.');
            self::fail('A sentence another agent has waiting was changed');
        } catch (HeldElsewhereException $e) {
            self::assertStringContainsString('Mail goes through Postmark.', $e->getMessage());
            self::assertStringContainsString($this->kb->agentToken->getName(), $e->getMessage());
            self::assertStringContainsString('comment', $e->getMessage());
        }

        $other = $this->patch($note, $this->kb->otherAgentToken, 'Status: on track.', 'Status: blocked.');
        self::assertFalse($other->isApplied(), 'A different sentence of the same note is free');
    }

    public function testTextAddedAfterASentenceHoldsThatSentence(): void
    {
        $note = $this->kb->note($this->writer, 'Record', self::RECORD, []);
        $this->writer->propose($note, $this->kb->agentToken, null,
            str_replace('in the design folder.', 'in the design folder. The palette lives in one tokens file.', self::RECORD), null, applyTags: false);

        $this->expectException(HeldElsewhereException::class);
        $this->patch($note, $this->kb->otherAgentToken, 'in the design folder.', 'in the docs folder.');
    }

    public function testARewriteHoldsTheWholeBody(): void
    {
        $note = $this->kb->note($this->writer, 'Record', self::RECORD, []);
        $this->writer->propose($note, $this->kb->agentToken, null, "Everything here moved to the new tracker on 2026-10-14.\n", null, applyTags: false);

        $this->expectException(HeldElsewhereException::class);
        $this->patch($note, $this->kb->otherAgentToken, 'Mail goes through Postmark.', 'Mail goes through Resend.');
    }

    public function testTheTitleAndTheDescriptionAreHeldLikeSentences(): void
    {
        $note = $this->kb->note($this->writer, 'Record', self::RECORD, []);
        $this->writer->propose($note, $this->kb->agentToken, 'Record — MM3', null, null, applyTags: false, summary: 'The live record.');

        foreach ([['Record (MM3)', null], [null, 'Another description.']] as [$title, $summary]) {
            try {
                $this->writer->propose($note, $this->kb->otherAgentToken, $title, null, null, applyTags: false, summary: $summary);
                self::fail('A field another agent has waiting was changed');
            } catch (HeldElsewhereException $e) {
                self::assertStringContainsString($title !== null ? 'the title' : 'the description', $e->getMessage());
            }
        }
    }

    public function testAnAgentRevisesItsOwnWaitingSentence(): void
    {
        $note = $this->kb->note($this->writer, 'Record', self::RECORD, []);
        $this->patch($note, $this->kb->agentToken, 'Mail goes through Postmark.', 'Mail goes through Postmark, from hello@.');

        $revised = $this->patch($note, $this->kb->agentToken, 'Status: on track.', 'Status: blocked.');

        $this->verdicts->approveProposal($revised, self::VERDICT, $this->reviewSnapshot($revised));
        self::assertStringContainsString('Status: blocked.', $note->getBodyMd());
        self::assertStringContainsString('from hello@.', $note->getBodyMd());
    }

    public function testCuratorsRespectTheLockToo(): void
    {
        $note = $this->kb->note($this->writer, 'Record', self::RECORD, []);
        $this->patch($note, $this->kb->agentToken, 'Mail goes through Postmark.', 'Mail goes through Postmark, from hello@.');

        try {
            $this->patch($note, $this->kb->curatorToken, 'Mail goes through Postmark.', 'Mail goes through Resend.');
            self::fail('A curator changed a sentence an agent has waiting');
        } catch (HeldElsewhereException) {
        }
        self::assertStringContainsString('Mail goes through Postmark.', $note->getBodyMd());

        $applied = $this->patch($note, $this->kb->curatorToken, 'Status: on track.', 'Status: blocked.');
        self::assertTrue($applied->isApplied(), "A curator's edit to a free sentence still applies at once");
    }

    public function testWaitingEditsOnDifferentSentencesApproveInEitherOrder(): void
    {
        foreach ([true, false] as $firstFirst) {
            $note = $this->kb->note($this->writer, 'Record '.($firstFirst ? 'A' : 'B'), self::RECORD, []);
            $first = $this->patch($note, $this->kb->agentToken, 'Status: on track. Stage 1 is merged.', 'Status: on track. Stage 1 is merged and deployed.');
            $second = $this->patch($note, $this->kb->otherAgentToken, 'Mail goes through Postmark.', 'Mail goes through Resend.');

            foreach ($firstFirst ? [$first, $second] : [$second, $first] as $proposal) {
                $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));
            }

            self::assertStringContainsString('Stage 1 is merged and deployed.', $note->getBodyMd());
            self::assertStringContainsString('Mail goes through Resend.', $note->getBodyMd());
        }
    }

    public function testTwoAgentsTagChangesBothLand(): void
    {
        $note = $this->kb->note($this->writer, 'Record', self::RECORD, ['mm3']);
        $first = $this->writer->propose($note, $this->kb->agentToken, null, null, ['mm3', 'design'], applyTags: false);
        $second = $this->writer->propose($note, $this->kb->otherAgentToken, null, null, ['mm3', 'auth'], applyTags: false);

        $this->verdicts->approveProposal($first, self::VERDICT, $this->reviewSnapshot($first));
        $this->verdicts->approveProposal($second, self::VERDICT, $this->reviewSnapshot($second));

        $tags = array_map(static fn ($tag) => $tag->getName(), $note->getTags()->toArray());
        sort($tags);
        self::assertSame(['auth', 'design', 'mm3'], $tags, "The second agent's list did not drop the first agent's tag");
    }

    public function testADraftThatNoLongerFitsHoldsNothing(): void
    {
        $note = $this->kb->note($this->writer, 'Record', self::RECORD, []);
        $this->patch($note, $this->kb->agentToken, 'Mail goes through Postmark.', 'Mail goes through Postmark, from hello@.');
        // The owner rewrites that sentence; the agent's waiting edit no longer fits.
        $this->writer->update($note, null, str_replace('Mail goes through Postmark.', 'Mail goes out through Postmark.', self::RECORD), null, null);

        $other = $this->patch($note, $this->kb->otherAgentToken, 'Mail goes out through Postmark.', 'Mail goes out through Resend.');
        self::assertFalse($other->isApplied());
    }
}
