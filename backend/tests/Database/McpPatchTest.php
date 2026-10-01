<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Tests\Support\Tenant;

/**
 * Anchored edits over MCP, which is the surface that actually matters: the
 * callers this was built for are assistants, and they reach memex here.
 *
 * The schema is product surface (the lesson from the first claude.ai field
 * test, 2026-08-05), so `patch` being advertised and described is part of the
 * feature, not documentation of it. An assistant that cannot see the parameter
 * will keep resending whole bodies.
 */
final class McpPatchTest extends ApiTestCase
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

    private function noteBody(int $id, ?Tenant $tenant = null): string
    {
        $this->in($tenant ?? $this->kb->a);

        return (string) $this->em->getConnection()->fetchOne('SELECT body_md FROM notes WHERE id = ?', [$id]);
    }

    public function testAnAssistantCanCorrectOneSentenceWithoutResendingTheNote(): void
    {
        $note = $this->kb->a->note('Record', "First paragraph.\n\nMicrosoft is not live yet.\n\nLast paragraph.");

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'note_id' => $note->getId(),
            'patch' => [['find' => 'Microsoft is not live yet.', 'replace' => 'Microsoft went live on 2026-08-21.']],
            'comment' => 'It shipped.',
        ]);

        self::assertNotTrue($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('held for review', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame(
            "First paragraph.\n\nMicrosoft is not live yet.\n\nLast paragraph.",
            $this->noteBody((int) $note->getId()),
            'The gate holds a patch like any other edit'
        );
    }

    public function testAnAnchorThatIsNotInTheNoteComesBackAsAnErrorTheCallerCanActOn(): void
    {
        $note = $this->kb->a->note('Record', 'The body as it really is.');

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'note_id' => $note->getId(),
            'patch' => [['find' => 'text that was never there', 'replace' => 'x']],
        ]);

        self::assertTrue($result['isError'] ?? false);
        $text = json_encode($result, JSON_THROW_ON_ERROR);
        // The caller's own anchor, quoted back, with what to do — not "Tool
        // failed", which reads like the server broke.
        self::assertStringContainsString('not in the note as it now stands', $text);
        self::assertStringNotContainsString('Tool failed', $text);
    }

    public function testSendingBothABodyAndAPatchIsRefused(): void
    {
        $note = $this->kb->a->note('Record', 'The body.');

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'note_id' => $note->getId(),
            'body_md' => 'A whole new body.',
            'patch' => [['find' => 'The body.', 'replace' => 'Something else.']],
        ]);

        self::assertTrue($result['isError'] ?? false);
        self::assertStringContainsString('not both', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /**
     * A patch must not become a way around the review gate. It is an edit like
     * any other, and an agent token's edits are held.
     */
    public function testAPatchDoesNotBypassTheReviewGate(): void
    {
        $note = $this->kb->a->note('Record', 'Held like everything else.');

        $this->callTool('propose', $this->kb->a->agentBearer, [
            'note_id' => $note->getId(),
            'patch' => [['find' => 'Held', 'replace' => 'Not held']],
        ]);

        self::assertSame('Held like everything else.', $this->noteBody((int) $note->getId()));
        self::assertSame(
            1,
            (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM edit_proposals WHERE status = 'held'")
        );
    }

    public function testAnotherTeamsNoteCannotBePatched(): void
    {
        $note = $this->kb->b->note('Bravo private note', 'Team B body, distinctive phrase.');

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'note_id' => $note->getId(),
            'patch' => [['find' => 'distinctive phrase', 'replace' => 'vandalised']],
        ]);

        self::assertTrue($result['isError'] ?? false);
        self::assertStringNotContainsString('Team B body', $this->body());
        self::assertSame('Team B body, distinctive phrase.', $this->noteBody((int) $note->getId(), $this->kb->b));
    }

    /**
     * The schema is what tells an assistant this exists. Without the
     * description saying WHEN to prefer it, the parameter is there and unused.
     */
    public function testProposeAdvertisesPatchAndSaysWhenToUseIt(): void
    {
        $this->request('POST', '/mcp', $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $tools = $this->jsonResponse()['result']['tools'];
        $propose = null;
        foreach ($tools as $tool) {
            if ($tool['name'] === 'propose') {
                $propose = $tool;
            }
        }

        self::assertNotNull($propose);
        $patch = $propose['inputSchema']['properties']['patch'] ?? null;
        self::assertNotNull($patch, '`patch` must be advertised or no assistant will send one');
        self::assertStringContainsString('EXACTLY ONCE', $patch['description']);
        self::assertStringContainsString('approval', $patch['description']);
    }

    public function testABase64PatchIsDecodedBeforeItIsAnchored(): void
    {
        $note = $this->kb->a->note('Runbook', "Restart with `systemctl restart memex`.\n\nThen check the log.");

        $result = $this->callTool('propose', $this->kb->a->curatorBearer, [
            'note_id' => $note->getId(),
            'body_encoding' => 'base64',
            'patch' => [[
                'find' => base64_encode('Restart with `systemctl restart memex`.'),
                'replace' => base64_encode('Restart with `sudo systemctl restart memex`.'),
            ]],
            'comment' => base64_encode('The unit needs root.'),
        ]);

        self::assertNotTrue($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame(
            "Restart with `sudo systemctl restart memex`.\n\nThen check the log.",
            $this->noteBody((int) $note->getId()),
            'A curator patch sent base64-encoded must apply as the decoded text'
        );
    }
}
