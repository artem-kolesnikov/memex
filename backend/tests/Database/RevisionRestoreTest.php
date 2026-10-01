<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * Restoring a previous version of a note, through the endpoint that does it.
 *
 * Driven over HTTP deliberately. The first version of this test called
 * NoteWriter directly with the arguments the controller passes, which proved
 * that the writer works and nothing about the controller — the same vacuous
 * shape the audit caught in the cross-tenant blast_radius test (M-18), where an
 * assertion passed no matter what the production code did. A test for a
 * controller fix has to go through the controller.
 */
final class RevisionRestoreTest extends ApiTestCase
{
    public function testRestoringARevisionBringsBackItsSummaryNotTheCurrentOne(): void
    {
        $note = $this->kb->a->note('Title', 'The good body.', [], 'Describes the good body.');
        $id = $note->getId();

        $this->loginAs($this->kb->a);

        // The bad edit, described afresh — which is what the editor does
        // whenever a body changes, so the stale description is nearly always
        // the one written ABOUT the edit being undone.
        $this->sessionRequest('PUT', '/api/notes/'.$id, [
            'body_md' => 'The bad body.',
            'summary' => 'Describes the BAD body.',
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('GET', '/api/notes/'.$id.'/revisions');
        self::assertSame(200, $this->httpStatus(), $this->body());
        $revisions = $this->jsonResponse()['revisions'];
        self::assertNotEmpty($revisions, 'Precondition: the edit was snapshotted');
        $revisionId = $revisions[0]['id'];

        $this->sessionRequest('POST', '/api/notes/'.$id.'/revisions/'.$revisionId.'/restore');
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('GET', '/api/notes/'.$id);
        $restored = $this->jsonResponse();
        self::assertSame('The good body.', $restored['body_md']);
        self::assertSame(
            'Describes the good body.',
            $restored['summary'],
            'A restored body must not keep the description of the edit being undone'
        );
    }

    public function testRestoringToAVersionThatHadNoDescriptionClearsTheCurrentOne(): void
    {
        // "This version had no description" is itself a thing to restore. If
        // only non-null summaries came back, a note described after the fact
        // could never be returned to its undescribed state — and because the
        // server only ever writes a summary into a note that HAS none, the
        // stale one would then stay forever.
        $note = $this->kb->a->note('Title', 'First body.');
        $id = $note->getId();

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$id, [
            'body_md' => 'Second body.',
            'summary' => 'Written later.',
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('GET', '/api/notes/'.$id.'/revisions');
        $revisionId = $this->jsonResponse()['revisions'][0]['id'];
        $this->sessionRequest('POST', '/api/notes/'.$id.'/revisions/'.$revisionId.'/restore');
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('GET', '/api/notes/'.$id);
        self::assertNull($this->jsonResponse()['summary']);
    }
}
