<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\EditProposal;
use App\Service\NoteWriter;
use App\Service\ReviewVerdicts;
use App\Tests\Support\KbFixture;

/**
 * A held draft records the note it was written against, and is stale once
 * somebody else changes text it changes (operator, 2026-10-13).
 *
 * Met on 2026-10-13: an agent's held patch quoted a sentence a curator then
 * rewrote. The operator could not approve the patch, and every later edit the
 * agent sent was folded into it and refused on the old anchor, so the agent
 * could say nothing about the note until the operator rejected its draft.
 */
final class StaleHeldEditTest extends DatabaseTestCase
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

    /** @return EditProposal[] */
    private function held(int $noteId): array
    {
        return $this->em->getRepository(EditProposal::class)->findBy(
            ['note' => $noteId, 'status' => EditProposal::STATUS_HELD],
            ['id' => 'ASC'],
        );
    }

    private function patch(\App\Entity\Note $note, \App\Entity\ApiToken $token, string $find, string $replace): EditProposal
    {
        return $this->writer->propose($note, $token, null, null, null, applyTags: false, patch: [['find' => $find, 'replace' => $replace]]);
    }

    /** The incident, at its own scale. */
    public function testAnAuthorsNextEditReplacesItsStaleDraftInsteadOfBeingRefused(): void
    {
        $note = $this->kb->note($this->writer, 'Factory record', "The first epic has not been filed yet.\n\nThe Designer is not on the squad.", []);
        $this->patch($note, $this->kb->agentToken, 'The first epic has not been filed yet.', 'The first epic, TROI-5, is filed.');
        $this->patch($note, $this->kb->curatorToken, 'The first epic has not been filed yet.', 'The first epic, TROI-5, has its first stage merged.');

        [$draft] = $this->held((int) $note->getId());
        self::assertSame(['body'], $draft->staleFields());

        $next = $this->patch($note, $this->kb->agentToken, 'The Designer is not on the squad.', 'The Designer joined the squad.');

        $held = $this->held((int) $note->getId());
        self::assertCount(1, $held, 'Still one document from this author');
        self::assertSame($next, $held[0]);
        self::assertSame(
            "The first epic, TROI-5, has its first stage merged.\n\nThe Designer joined the squad.",
            \App\Service\NotePatch::apply($note->getBodyMd(), $next->getProposedPatch() ?? []),
        );
        self::assertSame([], $next->staleFields());
        self::assertSame(['body'], $next->getReplacedStale()['stale']);
        self::assertSame(
            "The first epic, TROI-5, is filed.\n\nThe Designer is not on the squad.",
            \App\Service\NotePatch::apply("The first epic has not been filed yet.\n\nThe Designer is not on the squad.", $next->getReplacedStale()['proposed_patch']),
        );

        $this->verdicts->approveProposal($next, self::VERDICT, $this->reviewSnapshot($next));
        self::assertSame("The first epic, TROI-5, has its first stage merged.\n\nThe Designer joined the squad.", $note->getBodyMd());
    }

    public function testACurrentDraftStillFoldsTheAuthorsNextEdit(): void
    {
        $note = $this->kb->note($this->writer, 'Record', "Microsoft is pending.\n\nApple is pending.", []);
        $first = $this->patch($note, $this->kb->agentToken, 'Microsoft is pending.', 'Microsoft is live.');
        $second = $this->patch($note, $this->kb->agentToken, 'Apple is pending.', 'Apple is live.');

        self::assertSame($first, $second);
        self::assertCount(2, $second->getProposedPatch());
        self::assertNull($second->getReplacedStale());
    }

    public function testAStalePatchThatStillFitsCanStillBeApproved(): void
    {
        $note = $this->kb->note($this->writer, 'Record', "Microsoft is pending.\n\nApple is pending.", []);
        $draft = $this->patch($note, $this->kb->agentToken, 'Apple is pending.', 'Apple is live.');
        $this->patch($note, $this->kb->curatorToken, 'Microsoft is pending.', 'Microsoft is live.');

        self::assertSame(['body'], $draft->staleFields());
        $this->verdicts->approveProposal($draft, self::VERDICT, $this->reviewSnapshot($draft));
        self::assertSame("Microsoft is live.\n\nApple is live.", $note->getBodyMd());
    }

    public function testAChangeToAFieldTheDraftLeavesAloneDoesNotMakeItStale(): void
    {
        $note = $this->kb->note($this->writer, 'Record', 'Microsoft is pending.', ['alpha']);
        $draft = $this->patch($note, $this->kb->agentToken, 'Microsoft is pending.', 'Microsoft is live.');
        $this->writer->propose($note, $this->kb->curatorToken, null, null, ['alpha', 'bravo'], applyTags: false, summary: 'Where each sign-in stands.');

        self::assertSame([], $draft->staleFields());
    }

    public function testAWholeBodyDraftIsStaleOnceTheBodyMovesAndKeepsWhatItWasWrittenAgainst(): void
    {
        $note = $this->kb->note($this->writer, 'Record', 'Version zero.', ['alpha']);
        $draft = $this->writer->propose($note, $this->kb->agentToken, null, 'Version one.', ['alpha', 'bravo'], applyTags: false);
        $this->patch($note, $this->kb->curatorToken, 'Version zero.', 'Version zero, corrected.');

        self::assertSame(['body'], $draft->staleFields());
        self::assertSame('Version zero.', $draft->getBase()['body_md']);
        self::assertSame(['alpha'], $draft->getBase()['tags']);
    }

    public function testACuratorsWriteNamesTheOtherDraftItLeftStaleOnce(): void
    {
        $note = $this->kb->note($this->writer, 'Record', "Microsoft is pending.\n\nApple is pending.", []);
        $draft = $this->patch($note, $this->kb->agentToken, 'Apple is pending.', 'Apple is live.');

        $first = $this->patch($note, $this->kb->curatorToken, 'Microsoft is pending.', 'Microsoft is live.');
        self::assertSame([[
            'proposal_id' => $draft->getId(),
            'proposed_by' => $draft->authorName(),
            'change_title' => null,
            'stale' => ['body'],
        ]], $first->getLeftStale());

        $second = $this->patch($note, $this->kb->curatorToken, 'Microsoft is live.', 'Microsoft is live since 2026-09-30.');
        self::assertSame([], $second->getLeftStale(), 'Already stale: nothing new to say');
    }

    public function testADraftWithNoRecordedBaseIsNeverCalledStale(): void
    {
        $note = $this->kb->note($this->writer, 'Record', 'Microsoft is pending.', []);
        $draft = $this->patch($note, $this->kb->agentToken, 'Microsoft is pending.', 'Microsoft is live.');
        $this->em->getConnection()->executeStatement('UPDATE edit_proposals SET base = NULL WHERE id = :id', ['id' => $draft->getId()]);
        $this->em->clear();
        $note = $this->em->find(\App\Entity\Note::class, $note->getId());
        $this->writer->update($note, null, 'Rewritten by the owner.', null, null);

        $draft = $this->em->find(EditProposal::class, $draft->getId());
        self::assertSame([], $draft->staleFields());
    }

    /** A draft held when this shipped has no base; its broken anchors must not trap its author either. */
    public function testADraftWithNoBaseWhoseAnchorsNoLongerFitIsReplacedToo(): void
    {
        $note = $this->kb->note($this->writer, 'Factory record', "The first epic has not been filed yet.\n\nThe Designer is not on the squad.", []);
        $draft = $this->patch($note, $this->kb->agentToken, 'The first epic has not been filed yet.', 'The first epic, TROI-5, is filed.');
        $this->em->getConnection()->executeStatement('UPDATE edit_proposals SET base = NULL WHERE id = :id', ['id' => $draft->getId()]);
        $this->em->clear();
        $note = $this->em->find(\App\Entity\Note::class, $note->getId());
        $this->kb->refresh();
        $this->patch($note, $this->kb->curatorToken, 'The first epic has not been filed yet.', 'The first epic, TROI-5, has its first stage merged.');

        $next = $this->patch($note, $this->kb->agentToken, 'The Designer is not on the squad.', 'The Designer joined the squad.');

        self::assertCount(1, $this->held((int) $note->getId()));
        self::assertSame(['body'], $next->getReplacedStale()['stale']);
        self::assertCount(1, $next->getProposedPatch());
    }

    public function testAMergeBringingItsOwnTextIsStaleOnceEitherNoteMoves(): void
    {
        $keeper = $this->kb->note($this->writer, 'Keeper', 'Kept text.', []);
        $absorbed = $this->kb->note($this->writer, 'Absorbed', 'Absorbed text.', []);
        $merge = $this->writer->proposeMerge($absorbed, $keeper, $this->kb->agentToken, "Kept text.\n\nAbsorbed text.", 'Same subject.');
        self::assertSame([], $merge->staleFields());

        $this->patch($absorbed, $this->kb->curatorToken, 'Absorbed text.', 'Absorbed text, corrected.');
        self::assertSame(['absorbed_body'], $merge->staleFields());

        $this->patch($keeper, $this->kb->curatorToken, 'Kept text.', 'Kept text, corrected.');
        self::assertSame(['body', 'absorbed_body'], $merge->staleFields());
    }
}
