<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * Two assistants on one note, over the wire (operator, 2026-10-14): the second
 * reads what the first has waiting, is refused on the sentence it holds and
 * heard as a comment instead, and approving both names both.
 */
final class WaitingChangesApiTest extends ApiTestCase
{
    private const BODY = "Status: on track. Stage 1 is merged.\n\n- Mail goes through Postmark.\n";

    /** @return array{0: array<string, mixed>, 1: bool} the tool's payload, and whether it is an error */
    private function tool(string $bearer, string $name, array $arguments): array
    {
        $this->request('POST', '/mcp', $bearer, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $result = $this->jsonResponse()['result'];

        return [json_decode($result['content'][0]['text'], true, 64, JSON_THROW_ON_ERROR), (bool) ($result['isError'] ?? false)];
    }

    public function testTheSecondAssistantSeesWhatWaitsIsRefusedOnItAndIsHeardAsAComment(): void
    {
        $note = (int) $this->kb->b->note('MM3 record', self::BODY)->getId();
        $second = 'mxt_agent_second_b';
        $this->kb->b->connection('Orchestrator', $second);

        [, $failed] = $this->tool($this->kb->b->agentBearer, 'propose', ['note_id' => $note, 'change_title' => 'Postmark sender',
            'patch' => [['find' => 'Mail goes through Postmark.', 'replace' => 'Mail goes through Postmark, from hello@.']]]);
        self::assertFalse($failed);

        [$read] = $this->tool($second, 'get', ['id' => $note]);
        self::assertCount(1, $read['waiting_changes']);
        self::assertSame('Postmark sender', $read['waiting_changes'][0]['change_title']);
        self::assertSame(['- Mail goes through Postmark.'], $read['waiting_changes'][0]['held_sentences']);
        self::assertFalse($read['waiting_changes'][0]['yours']);

        [$refused, $failed] = $this->tool($second, 'propose', ['note_id' => $note,
            'patch' => [['find' => 'Mail goes through Postmark.', 'replace' => 'Mail goes through Resend.']]]);
        self::assertTrue($failed, 'A sentence the first assistant holds is refused');
        self::assertStringContainsString('Postmark sender', $refused['error']);

        [, $failed] = $this->tool($second, 'propose', ['note_id' => $note, 'comment' => 'Mail moves to Resend next week.']);
        self::assertFalse($failed, 'Said as a comment instead, it is heard');
    }

    public function testApprovingBothNamesBothAsTheNotesEditors(): void
    {
        $note = (int) $this->kb->b->note('MM3 record', self::BODY)->getId();
        $second = 'mxt_agent_second_b';
        $this->kb->b->connection('Orchestrator', $second);
        $this->tool($this->kb->b->agentBearer, 'propose', ['note_id' => $note,
            'patch' => [['find' => 'Mail goes through Postmark.', 'replace' => 'Mail goes through Postmark, from hello@.']]]);
        $this->tool($second, 'propose', ['note_id' => $note,
            'patch' => [['find' => 'Status: on track.', 'replace' => 'Status: blocked.']]]);

        $this->loginAs($this->kb->b);
        $this->sessionRequest('GET', '/api/proposals');
        $items = [];
        foreach ($this->jsonResponse()['proposals'] as $row) {
            $this->sessionRequest('GET', '/api/proposals/'.$row['id']);
            $card = $this->jsonResponse();
            $items[] = ['kind' => 'proposal', 'id' => $row['id'], 'expected_revision' => $card['revision'], 'expected_version' => $card['note']['version']];
        }
        $this->sessionRequest('POST', '/api/inbox/batch', ['action' => 'approve', 'items' => $items]);
        self::assertSame(2, $this->jsonResponse()['done'], $this->body());

        $this->sessionRequest('GET', '/api/notes/'.$note);
        $shown = $this->jsonResponse();
        $names = [$shown['edited_by']['name'], ...array_map(static fn (array $actor): string => $actor['name'], $shown['edited_with'])];
        sort($names);
        self::assertSame(['Orchestrator', 'agent-b'], $names);
        self::assertSame("Status: blocked. Stage 1 is merged.\n\n- Mail goes through Postmark, from hello@.\n", $shown['body_md']);
    }
}
