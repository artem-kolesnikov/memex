<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\EditProposal;
use App\Entity\Note;
use App\Service\NoteWriter;
use App\Service\ReviewVerdicts;
use App\Tests\Support\KbFixture;

/**
 * Applying a held edit proposal — the commonest thing the operator does in the
 * review inbox, and until now verified only by doing it in prod.
 */
final class EditProposalApplyTest extends DatabaseTestCase
{
    private NoteWriter $writer;
    private ReviewVerdicts $verdicts;
    private KbFixture $kb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = self::getContainer()->get(NoteWriter::class);
        $this->verdicts = self::getContainer()->get(ReviewVerdicts::class);
        $this->kb = new KbFixture(self::getContainer());
    }

    private const VERDICT = ['comment' => null, 'precedent' => false];

    public function testAgentProposalIsHeldAndChangesNothingUntilApproved(): void
    {
        $note = $this->kb->note($this->writer, 'Original title', 'Original body.', ['alpha']);

        $this->writer->propose(
            $note,
            $this->kb->agentToken,
            'Proposed title',
            'Proposed body.',
            ['alpha', 'beta'],
            applyTags: false,
        );

        // The review gate is the release-blocker property: a held proposal
        // must not have touched the note.
        self::assertSame('Original title', $note->getTitle());
        self::assertSame('Original body.', $note->getBodyMd());
        self::assertSame(1, $this->heldProposalCount());
    }

    public function testApprovingAnEditWritesTitleBodyAndTagsAndDiscardsTheProposal(): void
    {
        $note = $this->kb->note($this->writer, 'Original title', 'Original body.', ['alpha']);
        $proposal = $this->writer->propose(
            $note,
            $this->kb->agentToken,
            'Proposed title',
            'Proposed body.',
            ['alpha', 'beta'],
            applyTags: false,
        );

        $result = $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));

        self::assertInstanceOf(Note::class, $result['note']);
        self::assertSame('Proposed title', $note->getTitle());
        self::assertSame('Proposed body.', $note->getBodyMd());
        self::assertSame(['alpha', 'beta'], $this->tagNames($note));
        self::assertSame(0, $this->heldProposalCount(), 'An applied proposal is discarded, not left in the inbox');
    }

    public function testApprovalCreditsTheProposerNotTheApprover(): void
    {
        // Approval is a gate, not authorship — the note's actor and the
        // description's byline both have to name the token that did the work.
        $note = $this->kb->note($this->writer, 'Undescribed note', 'Body.', []);
        $proposal = $this->writer->propose(
            $note,
            $this->kb->agentToken,
            null,
            null,
            null,
            summary: 'A description written by the assistant.',
        );

        $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));

        self::assertSame('A description written by the assistant.', $note->getSummary());
        self::assertSame($this->kb->agentToken->getName(), $note->getSummaryBy());
        self::assertSame(Note::ACTOR_AGENT, $note->getLastActor());
    }

    public function testApprovingASummaryOnlyEditBuysNoSummarizeCall(): void
    {
        // The whole point of "no AI lives inside memex": when the assistant
        // supplied the description, approving it must not turn round and pay
        // for another one.
        $note = $this->kb->note($this->writer, 'Undescribed note', 'Body.', []);
        $proposal = $this->writer->propose(
            $note,
            $this->kb->agentToken,
            null,
            null,
            null,
            summary: 'The assistant wrote this.',
        );
        $before = $this->ml->callCount('/summarize');

        $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));

        self::assertSame($before, $this->ml->callCount('/summarize'));
        self::assertSame('The assistant wrote this.', $note->getSummary());
    }

    public function testRejectingAnEditDiscardsItAndLeavesTheNoteAlone(): void
    {
        $note = $this->kb->note($this->writer, 'Original title', 'Original body.', []);
        $proposal = $this->writer->propose(
            $note,
            $this->kb->agentToken,
            'Rejected title',
            null,
            null,
            applyTags: false,
        );

        $this->verdicts->rejectProposal($proposal, self::VERDICT);

        self::assertSame('Original title', $note->getTitle());
        self::assertSame(0, $this->heldProposalCount());
    }

    private function heldProposalCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM edit_proposals WHERE status = :held',
            ['held' => EditProposal::STATUS_HELD]
        );
    }

    /** @return string[] */
    private function tagNames(Note $note): array
    {
        $names = array_map(static fn ($tag) => $tag->getName(), $note->getTags()->toArray());
        sort($names);

        return $names;
    }
}
