<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * The owner typing their own version of a note somebody has in review.
 *
 * Their text is the document from that point on, so a held draft cannot be
 * left where a later approval would put the note back. Discarding it silently
 * would be as bad the other way — an agent's work gone with nothing said — so
 * the save is refused once, naming what is waiting, and `discard_proposals` is
 * the answer coming back (operator, 2026-09-08).
 */
final class OwnerSaveOverDraftTest extends ApiTestCase
{
    private function fileDraft(int $noteId, string $body): void
    {
        $this->request('PUT', '/api/notes/'.$noteId, $this->kb->a->agentBearer, [
            'body_md' => $body,
            'comment' => 'What I think it should say.',
        ]);
        self::assertSame(202, $this->httpStatus(), $this->body());
    }

    public function testTheFirstSaveIsRefusedAndSaysWhatIsWaiting(): void
    {
        $note = $this->kb->a->note('Title', 'Original body.');
        $id = (int) $note->getId();
        $this->fileDraft($id, 'The agent’s rewrite.');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$id, ['body_md' => 'What the owner typed.']);

        self::assertSame(409, $this->httpStatus(), $this->body());
        $conflict = $this->jsonResponse();
        self::assertSame('pending_proposals', $conflict['conflict']);
        self::assertCount(1, $conflict['pending_proposals']);
        self::assertSame('The agent’s rewrite.', $conflict['pending_proposals'][0]['proposed_body_md']);

        $this->sessionRequest('GET', '/api/notes/'.$id);
        self::assertSame('Original body.', $this->jsonResponse()['body_md'], 'A refused save writes nothing');
    }

    public function testSavingWithTheAnswerKeepsTheOwnersTextAndClearsTheInbox(): void
    {
        $note = $this->kb->a->note('Title', 'Original body.');
        $id = (int) $note->getId();
        $this->fileDraft($id, 'The agent’s rewrite.');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$id, [
            'body_md' => 'What the owner typed.',
            'discard_proposals' => true,
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('GET', '/api/notes/'.$id);
        $note = $this->jsonResponse();
        self::assertSame('What the owner typed.', $note['body_md']);
        self::assertSame(0, $note['pending_proposals']);

        $this->sessionRequest('GET', '/api/proposals');
        self::assertSame([], $this->jsonResponse()['proposals']);
    }

    /** Every author's draft goes, not only the newest: the owner's text replaces all of them. */
    public function testDraftsFromEveryConnectionAreDiscardedTogether(): void
    {
        $note = $this->kb->a->note('Title', 'Original body.');
        $id = (int) $note->getId();
        $this->fileDraft($id, 'The agent’s rewrite.');
        $this->request('PUT', '/api/notes/'.$id, $this->kb->a->curatorBearer, [
            'body_md' => 'The curator’s rewrite.',
        ]);
        self::assertSame(202, $this->httpStatus(), $this->body());

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$id, ['body_md' => 'What the owner typed.']);
        self::assertCount(2, $this->jsonResponse()['pending_proposals']);

        $this->sessionRequest('PUT', '/api/notes/'.$id, [
            'body_md' => 'What the owner typed.',
            'discard_proposals' => true,
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('GET', '/api/proposals');
        self::assertSame([], $this->jsonResponse()['proposals']);
    }

    /**
     * The discard has to be the LAST thing, after everything that can still
     * refuse the save. Committing it first destroyed an agent's work for a
     * save that then returned 409 and changed nothing (Codex, 2026-09-08).
     */
    public function testARefusedPatchLeavesTheDraftWhereItWas(): void
    {
        $note = $this->kb->a->note('Title', 'Original body.');
        $id = (int) $note->getId();
        $this->fileDraft($id, 'The agent’s rewrite.');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$id, [
            'discard_proposals' => true,
            'patch' => [['find' => 'text that is not in the note', 'replace' => 'anything']],
        ]);

        self::assertSame(409, $this->httpStatus(), $this->body());

        $this->sessionRequest('GET', '/api/notes/'.$id);
        self::assertSame('Original body.', $this->jsonResponse()['body_md'], 'The save did not happen');
        self::assertSame(1, $this->jsonResponse()['pending_proposals'], 'So the draft is still there');
    }

    /** A note nobody is drafting saves the way it always did. */
    public function testAnOrdinarySaveIsUnaffected(): void
    {
        $note = $this->kb->a->note('Title', 'Original body.');
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PUT', '/api/notes/'.$note->getId(), ['body_md' => 'Edited by the owner.']);

        self::assertSame(200, $this->httpStatus(), $this->body());
    }

    /** The discard is on the record, so the author can find out why its work went. */
    public function testTheDiscardIsLoggedAgainstTheNote(): void
    {
        $note = $this->kb->a->note('Title', 'Original body.');
        $id = (int) $note->getId();
        $this->fileDraft($id, 'The agent’s rewrite.');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$id, [
            'body_md' => 'What the owner typed.',
            'discard_proposals' => true,
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->in($this->kb->a);
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT description FROM curator_log WHERE note_id = :id AND action = 'rejected'",
            ['id' => $id],
        );
        self::assertCount(1, $rows);
        self::assertStringContainsString('Owner saved their own version', $rows[0]['description']);
    }
}
