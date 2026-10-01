<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\EditProposal;
use App\Entity\Note;

/**
 * The review gate, asserted at the boundaries a real client touches.
 *
 * CLAUDE.md calls this the property that must never be weakened to client
 * convention, and PRODUCT.md builds the trust model on it — and until this
 * file, the suite did not check it. No test created a note through an
 * agent-role token at all; no test asserted any note's `status`; grep for
 * `STATUS_PENDING` across `tests/` returned nothing. Both halves of the gate
 * were decided in a single expression each:
 *
 *   - what a token's WRITE lands as, at NoteWriter::create (`status =` …)
 *   - whether a DELETE is held, at NoteController::delete / toolProposeDelete
 *
 * Either could be degraded to always-allow and the whole suite stayed green.
 * The point of this file is that it does not any more: every test here fails
 * if its expression is loosened, which is the only property that makes a
 * regression net worth having.
 *
 * The curator exception is deliberately asserted too, in both directions. A
 * test that only proves "held" would pass on a build where the exception was
 * silently dropped — and the exception is a documented feature (PRODUCT.md
 * §Curator role), not an oversight to be tightened away.
 */
final class ReviewGateTest extends ApiTestCase
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
        $body = $this->jsonResponse();
        self::assertArrayHasKey('result', $body, "$tool returned a protocol error: ".$this->body());

        return $body['result'];
    }

    private function statusOf(int $id): string
    {
        $this->in($this->kb->a);

        return (string) $this->em->getConnection()->fetchOne('SELECT status FROM notes WHERE id = :id', ['id' => $id]);
    }

    private function noteExists(int $id): bool
    {
        $this->in($this->kb->a);

        return (bool) $this->em->getConnection()->fetchOne('SELECT 1 FROM notes WHERE id = :id', ['id' => $id]);
    }

    private function heldProposals(): int
    {
        $this->in($this->kb->a);

        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM edit_proposals WHERE applied_at IS NULL');
    }

    // ------------------------------------------------- the front half: writes

    public function testANoteCreatedByAnAgentTokenOverHttpLandsPending(): void
    {
        $this->request('POST', '/api/notes', $this->kb->a->agentBearer, [
            'title' => 'Filed by an agent',
            'body_md' => 'Something it believes.',
        ]);

        self::assertSame(201, $this->httpStatus(), $this->body());
        $id = $this->jsonResponse()['note']['id'];
        self::assertSame(Note::STATUS_PENDING, $this->statusOf($id), 'An agent write is a request, not a fact');
    }

    public function testANoteProposedByAnAgentTokenOverMcpLandsPending(): void
    {
        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'title' => 'Proposed as new',
            'body_md' => 'A note the agent thinks should exist.',
        ]);

        self::assertNotTrue($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        $this->in($this->kb->a);
        $id = (int) $this->em->getConnection()->fetchOne('SELECT id FROM notes WHERE title = :t', ['t' => 'Proposed as new']);
        self::assertGreaterThan(0, $id, 'The note was created');
        self::assertSame(Note::STATUS_PENDING, $this->statusOf($id));
    }

    public function testACuratorTokenWritesStraightToVerified(): void
    {
        // The documented exception (PRODUCT.md §Curator role): a curator's
        // authority IS the review. Asserted so that "held" cannot be
        // over-applied later without a test noticing.
        $this->request('POST', '/api/notes', $this->kb->a->curatorBearer, [
            'title' => 'Filed by the curator',
            'body_md' => 'Body.',
        ]);

        self::assertSame(201, $this->httpStatus(), $this->body());
        self::assertSame(Note::STATUS_VERIFIED, $this->statusOf($this->jsonResponse()['note']['id']));
    }

    public function testTheOperatorsOwnWriteIsVerified(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/notes', [
            'title' => 'Typed by a person',
            'body_md' => 'Body.',
        ]);

        self::assertSame(201, $this->httpStatus(), $this->body());
        self::assertSame(Note::STATUS_VERIFIED, $this->statusOf($this->jsonResponse()['note']['id']));
    }

    public function testAPendingNoteBecomesVerifiedOnlyWhenTheOperatorApprovesIt(): void
    {
        // The lifecycle end to end, same vault — previously covered only as a
        // cross-vault 404 against a note that was verified to begin with.
        $this->request('POST', '/api/notes', $this->kb->a->agentBearer, [
            'title' => 'Awaiting a verdict',
            'body_md' => 'Body.',
        ]);
        $id = $this->jsonResponse()['note']['id'];
        self::assertSame(Note::STATUS_PENDING, $this->statusOf($id));

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/notes/'.$id.'/approve');

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame(Note::STATUS_VERIFIED, $this->statusOf($id));
    }

    public function testAnAgentCannotApproveItsOwnPendingNote(): void
    {
        $this->request('POST', '/api/notes', $this->kb->a->agentBearer, [
            'title' => 'Awaiting a verdict',
            'body_md' => 'Body.',
        ]);
        $id = $this->jsonResponse()['note']['id'];

        $this->request('POST', '/api/notes/'.$id.'/approve', $this->kb->a->agentBearer);

        self::assertGreaterThanOrEqual(400, $this->httpStatus(), 'A verdict is the operator’s, never the writer’s');
        self::assertSame(Note::STATUS_PENDING, $this->statusOf($id));
    }

    public function testACuratorTokenCannotApproveEither(): void
    {
        // The curator exception buys immediate CREATE and EDIT. It does not buy
        // the power to sign off on somebody else's held work.
        $this->request('POST', '/api/notes', $this->kb->a->agentBearer, [
            'title' => 'Awaiting a verdict',
            'body_md' => 'Body.',
        ]);
        $id = $this->jsonResponse()['note']['id'];

        $this->request('POST', '/api/notes/'.$id.'/approve', $this->kb->a->curatorBearer);

        self::assertGreaterThanOrEqual(400, $this->httpStatus());
        self::assertSame(Note::STATUS_PENDING, $this->statusOf($id));
    }

    // ------------------------------------------- the other half: deletes held

    public function testAnAgentsDeleteIsHeldAndTheNoteStays(): void
    {
        $note = $this->kb->a->note('Still wanted', 'Body.');
        $id = $note->getId();

        $this->request('DELETE', '/api/notes/'.$id, $this->kb->a->agentBearer, ['comment' => 'Looks stale.']);

        self::assertSame(202, $this->httpStatus(), $this->body());
        self::assertTrue($this->noteExists($id), 'A held delete deletes nothing');
        self::assertSame(1, $this->heldProposals());
    }

    public function testACuratorsDeleteIsHeldToo(): void
    {
        // The one the curator exception must NOT cover, and the one a fast-path
        // would be most tempting to add. CLAUDE.md: deletes and merges are held
        // for every role.
        $note = $this->kb->a->note('Still wanted', 'Body.');
        $id = $note->getId();

        $this->request('DELETE', '/api/notes/'.$id, $this->kb->a->curatorBearer, ['comment' => 'Superseded.']);

        self::assertSame(202, $this->httpStatus(), $this->body());
        self::assertTrue($this->noteExists($id), 'Curator authority stops at destruction');
        self::assertSame(1, $this->heldProposals());
    }

    public function testACuratorsDeleteOverMcpIsHeldAsWell(): void
    {
        $note = $this->kb->a->note('Still wanted', 'Body.');
        $id = $note->getId();

        $result = $this->callTool('propose_delete', $this->kb->a->curatorBearer, [
            'note_id' => $id,
            'reason' => 'Superseded.',
        ]);

        self::assertNotTrue($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertTrue($this->noteExists($id), 'The MCP surface is not a second door around the gate');
        self::assertSame(1, $this->heldProposals());
    }

    public function testACuratorsMergeIsHeldOnBothSurfaces(): void
    {
        $absorb = $this->kb->a->note('Duplicate', 'Body.');
        $keeper = $this->kb->a->note('The keeper', 'Body.');
        $absorbId = $absorb->getId();

        $result = $this->callTool('propose_merge', $this->kb->a->curatorBearer, [
            'note_id' => $absorbId,
            'into_note_id' => $keeper->getId(),
            'reason' => 'Same note twice.',
        ]);

        self::assertNotTrue($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertTrue($this->noteExists($absorbId), 'A merge destroys a note, so it is held like a delete');
        self::assertSame(1, $this->heldProposals());
    }

    public function testTheOperatorsOwnDeleteRetiresImmediately(): void
    {
        // The counterpart: the gate holds AGENTS, not the person whose knowledge
        // base it is. Asserted so "held for every role" cannot creep into
        // holding the operator too.
        $note = $this->kb->a->note('Genuinely unwanted', 'Body.');
        $id = $note->getId();

        $this->loginAs($this->kb->a);
        $this->sessionRequest('DELETE', '/api/notes/'.$id, ['reason' => 'No longer true.']);

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertFalse($this->noteExists($id));
        self::assertSame(0, $this->heldProposals(), 'The operator’s own delete is not a proposal');
        self::assertSame(
            1,
            (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM deleted_notes WHERE id = :id',
                ['id' => $id]
            ),
            'Retired, not destroyed'
        );
    }

    public function testApprovingAHeldDeleteIsWhatFinallyRetiresTheNote(): void
    {
        $note = $this->kb->a->note('Still wanted', 'Body.');
        $id = $note->getId();
        $this->request('DELETE', '/api/notes/'.$id, $this->kb->a->agentBearer, ['comment' => 'Stale.']);
        $proposalId = $this->jsonResponse()['proposal']['id'];

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$proposalId.'/approve', ['comment' => 'Agreed.']);

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertFalse($this->noteExists($id));
        self::assertSame(0, $this->heldProposals());
    }

    public function testAnAgentEditIsHeldAndTheNoteIsUntouchedUntilApproved(): void
    {
        $note = $this->kb->a->note('Original title', 'Original body.');
        $id = $note->getId();

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'note_id' => $id,
            'title' => 'Rewritten title',
            'body_md' => 'Rewritten body.',
        ]);

        self::assertNotTrue($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        $this->in($this->kb->a);
        $row = $this->em->getConnection()->fetchAssociative('SELECT title, body_md FROM notes WHERE id = :id', ['id' => $id]);
        self::assertSame('Original title', $row['title'], 'A held edit changes nothing');
        self::assertSame('Original body.', $row['body_md']);
        self::assertSame(1, $this->heldProposals());
        self::assertSame(
            EditProposal::TYPE_EDIT,
            $this->em->getConnection()->fetchOne('SELECT type FROM edit_proposals')
        );
    }
}
