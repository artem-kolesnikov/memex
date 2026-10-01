<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;
use App\Service\NoteWriter;
use App\Service\ReviewVerdicts;
use App\Tests\Support\KbFixture;

/**
 * Applying a merge — the judgment call most worth being able to walk back, and
 * the apply path with the most moving parts: two notes, their tags, everything
 * that linked to either of them, and a retirement at the end.
 */
final class MergeApplyTest extends DatabaseTestCase
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

    public function testAMergeIsHeldForReviewAtEveryRoleIncludingCurator(): void
    {
        $absorb = $this->kb->note($this->writer, 'Duplicate note', 'Body A.', []);
        $keeper = $this->kb->note($this->writer, 'Kept note', 'Body B.', []);

        $this->writer->proposeMerge($absorb, $keeper, $this->kb->curatorToken, null, 'Same thing twice.');

        self::assertNotNull($this->em->getRepository(Note::class)->find($absorb->getId()));
        self::assertSame('Body B.', $keeper->getBodyMd());
    }

    public function testApprovingAMergeFoldsTagsIntoTheKeeperAndRetiresTheOther(): void
    {
        $absorb = $this->kb->note($this->writer, 'Duplicate note', 'Body A.', ['alpha', 'shared']);
        $keeper = $this->kb->note($this->writer, 'Kept note', 'Body B.', ['beta', 'shared']);
        $absorbId = $absorb->getId();

        $proposal = $this->writer->proposeMerge($absorb, $keeper, $this->kb->agentToken, null, 'Same thing twice.');
        $result = $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));

        self::assertSame($keeper->getId(), $result['note']->getId());
        self::assertSame(['alpha', 'beta', 'shared'], $this->tagNames($keeper), 'The keeper inherits the absorbed note\'s tags');
        self::assertNull($this->em->getRepository(Note::class)->find($absorbId));

        $retired = $this->em->getConnection()->fetchAssociative(
            'SELECT title, body_md, deleted_reason FROM deleted_notes WHERE id = :id',
            ['id' => $absorbId]
        );
        self::assertIsArray($retired, 'A merge retires the absorbed note rather than destroying it');
        self::assertSame('Body A.', $retired['body_md']);
        self::assertStringContainsString('Merged into', (string) $retired['deleted_reason']);
        self::assertStringContainsString('Kept note', (string) $retired['deleted_reason']);
    }

    public function testAMergeWithNoBodyStillHandsTheKeeperToWhoeverProposedIt(): void
    {
        // The keeper is edited by one assistant, then merged into by another.
        // `mergeNotes()` set the actor KIND and left the token alone, and the
        // update() that does the full attribution is skipped when the merge
        // carries no body — so the keeper kept the FIRST assistant's face on a
        // write the second one made. Wrong rather than incomplete, and
        // invisible unless you knew which of two connections had touched it.
        $absorb = $this->kb->note($this->writer, 'Duplicate note', 'Body A.', []);
        $keeper = $this->kb->note($this->writer, 'Kept note', 'Body B.', []);
        $this->writer->update(
            $keeper,
            null,
            'Body B, revised by the curator.',
            null,
            \App\Service\EmbeddingSpend::Metered,
            actor: Note::ACTOR_CURATOR,
            actorToken: $this->kb->curatorToken,
        );
        self::assertSame(
            $this->kb->curatorToken->getId(),
            $keeper->getLastActorToken()?->getId(),
            'Precondition: the curator is the assistant on the keeper'
        );

        $proposal = $this->writer->proposeMerge(
            $absorb,
            $keeper,
            $this->kb->agentToken,
            null,
            'Same thing twice.',
        );
        $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));

        self::assertSame(
            $this->kb->agentToken->getId(),
            $keeper->getLastActorToken()?->getId(),
            'The merge belongs to the assistant that proposed it'
        );
        self::assertFalse($keeper->isLastWrittenByOwner(), 'and an assistant write names no person');
    }

    public function testAMergedBodyReplacesTheKeepersBody(): void
    {
        $absorb = $this->kb->note($this->writer, 'Duplicate note', 'Body A.', []);
        $keeper = $this->kb->note($this->writer, 'Kept note', 'Body B.', []);

        $proposal = $this->writer->proposeMerge(
            $absorb,
            $keeper,
            $this->kb->agentToken,
            "Body A.\n\nBody B.",
            null,
        );
        $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));

        self::assertSame("Body A.\n\nBody B.", $keeper->getBodyMd());
    }

    public function testInboundLinksToTheAbsorbedNoteFollowItToTheKeeper(): void
    {
        // Someone else's note pointed at the duplicate. After the merge it has
        // to point at the surviving note, or the merge quietly breaks a link.
        $absorb = $this->kb->note($this->writer, 'Duplicate note', 'Body A.', []);
        $keeper = $this->kb->note($this->writer, 'Kept note', 'Body B.', []);
        $citing = $this->kb->note($this->writer, 'Citing note', 'See [[Duplicate note]] for detail.', []);

        self::assertSame(
            $absorb->getId(),
            $this->linkTarget($citing->getId()),
            'Precondition: the link resolved to the note about to be absorbed'
        );

        $proposal = $this->writer->proposeMerge($absorb, $keeper, $this->kb->agentToken, null, null);
        $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));

        self::assertSame($keeper->getId(), $this->linkTarget($citing->getId()));
    }

    public function testTheKeepersOwnLinkToTheAbsorbedNoteDoesNotBecomeASelfLink(): void
    {
        $absorb = $this->kb->note($this->writer, 'Duplicate note', 'Body A.', []);
        $keeper = $this->kb->note($this->writer, 'Kept note', 'See [[Duplicate note]] too.', []);

        $proposal = $this->writer->proposeMerge($absorb, $keeper, $this->kb->agentToken, null, null);
        $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));

        // The raw target survives so a later note of that name can re-resolve
        // it; what must not happen is the keeper linking to itself.
        self::assertNull($this->linkTarget($keeper->getId()));
    }

    private function linkTarget(int $fromNoteId): ?int
    {
        $target = $this->em->getConnection()->fetchOne(
            'SELECT to_note_id FROM note_links WHERE from_note_id = :id ORDER BY id LIMIT 1',
            ['id' => $fromNoteId]
        );

        return $target === false || $target === null ? null : (int) $target;
    }

    /** @return string[] */
    private function tagNames(Note $note): array
    {
        $names = array_map(static fn ($tag) => $tag->getName(), $note->getTags()->toArray());
        sort($names);

        return $names;
    }
}
