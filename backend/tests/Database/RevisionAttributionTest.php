<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * What a history row says an edit WAS, and who made it.
 *
 * `replaced_by` records a role, and a role is not a process: every edit made by
 * a token holding curator rights read "replaced by curator", including the ones
 * made from a chat window with no curation pass in sight — *"this is not very
 * correct, as those notes updated by an agent with curator rights, but not
 * during the curation process"* (operator, 2026-08-27).
 *
 * The property under test is that each of the five write paths REACHES THE
 * SCREEN saying which one it was, with the connection named. Driven over MCP
 * and HTTP rather than through `NoteWriter`, because the defect this replaces
 * was not in the writer — the writer had the token in hand all along — but in
 * what the revision row kept of it.
 */
final class RevisionAttributionTest extends ApiTestCase
{
    /** @return array<string, mixed> the tool result payload */
    private function callTool(string $tool, string $bearer, array $arguments = []): array
    {
        $this->request('POST', '/mcp', $bearer, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
        self::assertSame(200, $this->httpStatus(), "$tool did not answer: ".$this->body());

        return $this->jsonResponse()['result'];
    }

    /** @return array<int, array<string, mixed>> */
    private function revisions(int $noteId): array
    {
        $this->sessionRequest('GET', '/api/notes/'.$noteId.'/revisions');
        self::assertSame(200, $this->httpStatus(), $this->body());

        return $this->jsonResponse()['revisions'];
    }

    public function testACuratorTokensOwnEditIsAnEditAndNotACuration(): void
    {
        $note = $this->kb->a->note('The runbook', 'Deploys go to the old box.');
        $id = (int) $note->getId();

        // A patch, not a whole body: a curator's full-body write is held for
        // review, and this is the path that applies on the spot — the one that
        // produced 231 of the operator's 268 "replaced by curator" rows.
        $this->callTool('propose', $this->kb->a->curatorBearer, [
            'note_id' => $id,
            'patch' => [['find' => 'the old box', 'replace' => 'the new box']],
        ]);

        $this->loginAs($this->kb->a);
        $revisions = $this->revisions($id);
        self::assertNotEmpty($revisions, 'Precondition: the edit snapshotted what it replaced');
        self::assertSame('edit', $revisions[0]['operation']);
        self::assertSame('curator-a', $revisions[0]['replaced_by_actor']['name']);
        self::assertSame('assistant', $revisions[0]['replaced_by_actor']['kind']);
    }

    public function testAnApprovedProposalNamesItsAuthorAndNotTheApprover(): void
    {
        $note = $this->kb->a->note('The runbook', 'Deploys go to the old box.');
        $id = (int) $note->getId();

        $this->callTool('propose', $this->kb->a->agentBearer, [
            'note_id' => $id,
            'body_md' => 'Deploys go to the new box.',
        ]);

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/proposals');
        $proposals = $this->jsonResponse()['proposals'];
        self::assertCount(1, $proposals);
        $this->reviewedRequest('POST', '/api/proposals/'.$proposals[0]['id'].'/approve');
        self::assertSame(200, $this->httpStatus(), $this->body());

        $revisions = $this->revisions($id);
        self::assertSame('proposal', $revisions[0]['operation']);
        // Approval is a gate, not authorship — the same rule the note's own
        // actor line follows.
        self::assertSame('agent-a', $revisions[0]['replaced_by_actor']['name']);
    }

    public function testASaveInTheEditorIsNamedAsAPerson(): void
    {
        $note = $this->kb->a->note('The runbook', 'Deploys go to the old box.');
        $id = (int) $note->getId();

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$id, ['body_md' => 'Deploys go to the new box.']);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $revisions = $this->revisions($id);
        self::assertSame('edit', $revisions[0]['operation']);
        self::assertSame('person', $revisions[0]['replaced_by_actor']['kind']);
        self::assertSame('Owner A', $revisions[0]['replaced_by_actor']['name']);
    }

    public function testPuttingAVersionBackIsARestoreRatherThanAnEdit(): void
    {
        $note = $this->kb->a->note('The runbook', 'Deploys go to the old box.');
        $id = (int) $note->getId();

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$id, ['body_md' => 'Deploys go to the new box.']);
        $revisions = $this->revisions($id);
        self::assertNotEmpty($revisions);

        $this->sessionRequest('POST', '/api/notes/'.$id.'/revisions/'.$revisions[0]['id'].'/restore');
        self::assertSame(200, $this->httpStatus(), $this->body());

        // The restore snapshotted the text it replaced — the newest row is the
        // undo, not the edit that was undone.
        $after = $this->revisions($id);
        self::assertSame('restore', $after[0]['operation']);
        self::assertSame('person', $after[0]['replaced_by_actor']['kind']);
    }

    public function testAMergeSaysMergedRatherThanEdited(): void
    {
        $keeper = $this->kb->a->note('The runbook', 'Deploys go to the old box.');
        $absorb = $this->kb->a->note('Deploy notes', 'Also about deploys.');
        $keeperId = (int) $keeper->getId();

        // Held for every role, so the merge only happens at the approval below.
        $this->callTool('propose_merge', $this->kb->a->curatorBearer, [
            'note_id' => $absorb->getId(),
            'into_note_id' => $keeperId,
            'merged_body_md' => 'Deploys go to the new box, and here is why.',
        ]);

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/proposals');
        $proposals = $this->jsonResponse()['proposals'];
        self::assertCount(1, $proposals);
        $this->reviewedRequest('POST', '/api/proposals/'.$proposals[0]['id'].'/approve');
        self::assertSame(200, $this->httpStatus(), $this->body());

        $revisions = $this->revisions($keeperId);
        self::assertNotEmpty($revisions, 'Precondition: the keeper took on a new body');
        self::assertSame('merge', $revisions[0]['operation']);
        self::assertSame('curator-a', $revisions[0]['replaced_by_actor']['name']);
    }

    public function testAnUnknownOperationIsRefusedRatherThanStoredAsNothing(): void
    {
        // Null means "written before this existed, and unmatched by the
        // backfill". A typo at a call site nulled to exactly that, so a live
        // bug would render as an old row and nothing would say so.
        $note = $this->kb->a->note('The runbook', 'Deploys go to the old box.');

        $this->expectException(\InvalidArgumentException::class);
        \App\Entity\NoteRevision::capture($note, 'human', null, 'proprosal');
    }

    public function testARowWithNoRecordedActorFallsBackToTheRoleWordItAlwaysHad(): void
    {
        // The 90 rows the backfill could not match. Nothing may invent a verb
        // for them: the client shows `replaced_by` exactly as before, and this
        // is what says the API still carries it.
        $note = $this->kb->a->note('The runbook', 'Deploys go to the old box.');
        $id = (int) $note->getId();

        $this->callTool('propose', $this->kb->a->curatorBearer, [
            'note_id' => $id,
            'patch' => [['find' => 'the old box', 'replace' => 'the new box']],
        ]);

        $this->in($this->kb->a);
        $this->em->getConnection()->executeStatement(
            'UPDATE note_revisions SET operation = NULL, replaced_by_token_id = NULL'
        );

        $this->loginAs($this->kb->a);
        $revisions = $this->revisions($id);
        self::assertNull($revisions[0]['operation']);
        self::assertNull($revisions[0]['replaced_by_actor']);
        self::assertSame('curator', $revisions[0]['replaced_by']);
    }
}
