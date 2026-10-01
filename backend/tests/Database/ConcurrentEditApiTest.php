<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * The staleness conflict as a client sees it.
 *
 * Two layers, tested here because a caller only ever meets them through HTTP:
 *
 *  - `expected_version` on PUT is the LONG window — an editor open for minutes
 *    while a curator token rewrites the same note. Nothing inside a single
 *    request can see that; only the client knows what it was looking at.
 *  - The global OptimisticLockListener is the NARROW one, on any path that
 *    never sent a precondition. Without it a lost update surfaced as a 500,
 *    which is a refusal that tells the caller nothing and reads like a bug in
 *    memex rather than a fact about their note.
 */
final class ConcurrentEditApiTest extends ApiTestCase
{
    public function testTheNoteResponseCarriesTheVersionAClientMustSendBack(): void
    {
        $note = $this->kb->a->note('Title', 'Body.');
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/notes/'.$note->getId());

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertArrayHasKey('version', $this->jsonResponse());
    }

    public function testAStaleEditIsRefusedWithAConflictAndChangesNothing(): void
    {
        $note = $this->kb->a->note('Title', 'Original body.');
        $id = $note->getId();
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/notes/'.$id);
        $staleVersion = $this->jsonResponse()['version'];

        // Somebody else edits while our editor sits open.
        $this->sessionRequest('PUT', '/api/notes/'.$id, ['body_md' => 'Their newer text.']);
        self::assertSame(200, $this->httpStatus(), $this->body());

        // We save what we were looking at.
        $this->sessionRequest('PUT', '/api/notes/'.$id, [
            'body_md' => 'Our text, written against the old version.',
            'expected_version' => $staleVersion,
        ]);

        self::assertSame(409, $this->httpStatus(), $this->body());
        $conflict = $this->jsonResponse();
        self::assertSame($staleVersion, $conflict['expected_version']);
        self::assertGreaterThan($staleVersion, $conflict['current_version']);

        $this->sessionRequest('GET', '/api/notes/'.$id);
        self::assertSame(
            'Their newer text.',
            $this->jsonResponse()['body_md'],
            'A refused save must not have overwritten the edit it never saw'
        );
    }

    public function testAnUpToDateEditWithAPreconditionStillGoesThrough(): void
    {
        // The guard has to let ordinary work past it, or it is just an outage.
        $note = $this->kb->a->note('Title', 'Original body.');
        $id = $note->getId();
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/notes/'.$id);
        $version = $this->jsonResponse()['version'];

        $this->sessionRequest('PUT', '/api/notes/'.$id, [
            'body_md' => 'Our text.',
            'expected_version' => $version,
        ]);

        self::assertSame(200, $this->httpStatus(), $this->body());
        $this->sessionRequest('GET', '/api/notes/'.$id);
        self::assertSame('Our text.', $this->jsonResponse()['body_md']);
    }

    public function testEditsWithNoPreconditionKeepWorking(): void
    {
        // Optional on purpose: MCP, curl and anything written before this
        // existed must not start failing. They are still covered by the ORM's
        // own version check, which needs no cooperation from the caller.
        $note = $this->kb->a->note('Title', 'Original body.');
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PUT', '/api/notes/'.$note->getId(), ['body_md' => 'No precondition sent.']);

        self::assertSame(200, $this->httpStatus(), $this->body());
    }

    public function testANonIntegerPreconditionIsRefusedRatherThanIgnored(): void
    {
        // Silently ignoring "5" would be the worst outcome: the client believes
        // it is protected and is not.
        $note = $this->kb->a->note('Title', 'Body.');
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PUT', '/api/notes/'.$note->getId(), [
            'body_md' => 'Text.',
            'expected_version' => '1',
        ]);

        self::assertSame(400, $this->httpStatus(), $this->body());
    }
}
