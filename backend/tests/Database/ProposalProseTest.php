<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * A reason the operator can read, whichever door the agent came through.
 *
 * An agent that JSON-escapes its own string leaves the escape sequences in the
 * field: the inbox served `\n` and `\"` as those characters on 2026-09-16. The
 * repair went into McpController first, and a Codex review pointed out what
 * that leaves open — `DELETE /api/notes/7` with the same body is the website's
 * own route and never touched it. So the assertions below file the same
 * escaped comment over MCP and over REST, and what they pin is that BOTH
 * arrive readable.
 */
final class ProposalProseTest extends ApiTestCase
{
    private function callTool(string $tool, string $bearer, array $arguments = []): void
    {
        $this->request('POST', '/mcp', $bearer, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
        self::assertSame(200, $this->httpStatus(), "$tool did not answer: ".$this->body());
    }

    /** @return list<array<string, mixed>> */
    private function inbox(): array
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/proposals');
        self::assertSame(200, $this->httpStatus(), $this->body());

        return $this->jsonResponse()['proposals'];
    }

    public function testAnEscapedReasonFiledOverMcpReachesTheInboxReadable(): void
    {
        $note = $this->kb->a->note('The runbook', 'Deploys go to the old box.');

        $this->callTool('propose_delete', $this->kb->a->agentBearer, [
            'note_id' => (int) $note->getId(),
            'reason' => 'Superseded by \"The deploy\".\nNothing links here.',
        ]);

        self::assertSame(
            "Superseded by \"The deploy\".\nNothing links here.",
            $this->inbox()[0]['comment']
        );
    }

    public function testAnEscapedCommentFiledOverRestReachesTheInboxReadable(): void
    {
        // The website's own delete route. With a token it proposes rather than
        // retires, which makes it a second front door onto the review inbox.
        $note = $this->kb->a->note('The runbook', 'Deploys go to the old box.');

        $this->request('DELETE', '/api/notes/'.$note->getId(), $this->kb->a->agentBearer, [
            'comment' => 'Superseded by \"The deploy\".\nNothing links here.',
        ]);
        self::assertSame(202, $this->httpStatus(), $this->body());

        self::assertSame(
            "Superseded by \"The deploy\".\nNothing links here.",
            $this->inbox()[0]['comment']
        );
    }

    public function testTheRepairTouchesTheProseAndNothingElse(): void
    {
        // The escapes are in the BODY as well, and a body is not prose about a
        // change: an agent writing a runbook line means the two characters.
        // Asserting this against a body with no backslash in it would pass
        // whether the body were repaired or not.
        $note = $this->kb->a->note('The importer', 'It writes records.');

        $this->callTool('propose', $this->kb->a->agentBearer, [
            'note_id' => (int) $note->getId(),
            'body_md' => 'It writes `\n` between records.',
            'comment' => 'Says which separator it writes.\nEvidence: note '.$note->getId().'.',
        ]);

        $proposal = $this->inbox()[0];
        self::assertSame('It writes `\n` between records.', $proposal['proposed_body_md']);
        self::assertSame(
            "Says which separator it writes.\nEvidence: note ".$note->getId().'.',
            $proposal['comment']
        );
    }

    public function testAReportIsRepairedOnceRatherThanTwice(): void
    {
        // A comment with nothing to change files a REPORT, which used to go
        // through the repair a second time on its way there: the `\n` the
        // first pass makes out of an escaped backslash is a line break to the
        // second, so the note ends up saying something the agent did not.
        $note = $this->kb->a->note('The importer', 'It writes records.');

        $this->callTool('propose', $this->kb->a->agentBearer, [
            'note_id' => (int) $note->getId(),
            'comment' => 'The separator it writes is \\\\n, two characters.',
        ]);

        self::assertSame(
            'The separator it writes is \n, two characters.',
            $this->inbox()[0]['comment']
        );
    }
}
