<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * The owner typing their own version of a note somebody has in review.
 *
 * Their save goes through and the work waiting on the note stays waiting
 * (operator, 2026-10-14): a held edit applies as the words it changes, on top
 * of the owner's text, and one whose sentence the owner rewrote shows as
 * written before the note changed. The owner never discards an assistant's
 * work to fix a typo. This replaces the refusal-and-discard of 2026-09-08.
 */
final class OwnerSaveOverDraftTest extends ApiTestCase
{
    private const BODY = "The first sentence stands. The second sentence stands.\n";

    private function fileDraft(int $noteId, string $body): void
    {
        $this->request('PUT', '/api/notes/'.$noteId, $this->kb->a->agentBearer, [
            'body_md' => $body,
            'comment' => 'What I think it should say.',
        ]);
        self::assertSame(202, $this->httpStatus(), $this->body());
    }

    private function heldProposalId(int $noteId): int
    {
        $this->in($this->kb->a);

        return (int) $this->em->getConnection()->fetchOne("SELECT id FROM edit_proposals WHERE note_id = ? AND status = 'held'", [$noteId]);
    }

    public function testTheOwnersSaveGoesThroughAndTheDraftStaysWaiting(): void
    {
        $note = $this->kb->a->note('Title', self::BODY);
        $id = (int) $note->getId();
        $this->fileDraft($id, str_replace('second sentence stands', 'second sentence moved', self::BODY));

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$id, ['body_md' => str_replace('first sentence stands', 'first sentence moved', self::BODY)]);

        self::assertSame(200, $this->httpStatus(), $this->body());
        $this->sessionRequest('GET', '/api/notes/'.$id);
        self::assertSame(1, $this->jsonResponse()['pending_proposals'], 'The assistant\'s work is still waiting');
        self::assertStringContainsString('first sentence moved', $this->jsonResponse()['body_md']);
    }

    public function testTheWaitingEditLandsOnTopOfTheOwnersText(): void
    {
        $note = $this->kb->a->note('Title', self::BODY);
        $id = (int) $note->getId();
        $this->fileDraft($id, str_replace('second sentence stands', 'second sentence moved', self::BODY));

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$id, ['body_md' => str_replace('first sentence stands', 'first sentence moved', self::BODY)]);
        $proposal = $this->heldProposalId($id);
        $this->sessionRequest('GET', '/api/proposals/'.$proposal);
        $card = $this->jsonResponse();
        $this->sessionRequest('POST', '/api/proposals/'.$proposal.'/approve', ['expected_revision' => $card['revision'], 'expected_version' => $card['note']['version']]);

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame("The first sentence moved. The second sentence moved.\n", $this->jsonResponse()['note']['body_md']);
    }

    public function testADraftWhoseSentenceTheOwnerRewroteIsMarkedAndRefused(): void
    {
        $note = $this->kb->a->note('Title', self::BODY);
        $id = (int) $note->getId();
        $this->fileDraft($id, str_replace('second sentence stands', 'second sentence moved', self::BODY));

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$id, ['body_md' => str_replace('second sentence stands', 'second sentence is gone', self::BODY)]);
        $proposal = $this->heldProposalId($id);
        $this->sessionRequest('GET', '/api/proposals/'.$proposal);
        $card = $this->jsonResponse();

        self::assertSame(['body'], $card['stale']);
        $this->sessionRequest('POST', '/api/proposals/'.$proposal.'/approve', ['expected_revision' => $card['revision'], 'expected_version' => $card['note']['version']]);
        self::assertSame(409, $this->httpStatus(), $this->body());
    }

    /** A note nobody is drafting saves the way it always did. */
    public function testAnOrdinarySaveIsUnaffected(): void
    {
        $note = $this->kb->a->note('Title', 'Original body.');
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PUT', '/api/notes/'.$note->getId(), ['body_md' => 'Edited by the owner.']);

        self::assertSame(200, $this->httpStatus(), $this->body());
    }
}
