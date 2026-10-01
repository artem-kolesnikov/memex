<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * `change_title` — six or seven words saying what an edit did and why.
 *
 * The property is not "a column stores a string". It is that the headline
 * SURVIVES THE WHOLE JOURNEY: an agent sends it with a proposal, the operator
 * reads it in the review inbox to decide whether to look, approves, and it is
 * still there months later in the note's history, next to the version that
 * edit replaced. Four hops, three of them across a boundary where the proposal
 * row itself is destroyed — `applyEditProposal()` removes it — so a title that
 * lived only on the proposal would vanish at exactly the moment it became
 * historical.
 *
 * Driven over MCP and HTTP for that reason. Calling NoteWriter directly would
 * prove the writer copies a field and nothing about whether either screen can
 * ever show it.
 */
final class ChangeTitleTest extends ApiTestCase
{
    private const HEADLINE = 'Correct the deploy host after the move';

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

    public function testAnAgentsHeadlineReachesTheInboxAndThenTheHistory(): void
    {
        $note = $this->kb->a->note('The runbook', 'Deploys go to the old box.');
        $id = (int) $note->getId();

        $this->callTool('propose', $this->kb->a->agentBearer, [
            'note_id' => $id,
            'body_md' => 'Deploys go to the new box.',
            'change_title' => self::HEADLINE,
            'comment' => 'The old host was decommissioned last week and the runbook still names it.',
        ]);

        // Hop one: the review inbox, where it decides whether the row is opened.
        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/proposals');
        self::assertSame(200, $this->httpStatus(), $this->body());
        $proposals = $this->jsonResponse()['proposals'];
        self::assertCount(1, $proposals);
        self::assertSame(self::HEADLINE, $proposals[0]['change_title']);
        // Both fields, round-tripped exactly. Asserting only that they DIFFER
        // would have passed while `comment` was accidentally unmapped and came
        // back null — which is a bug this change actually introduced for a few
        // minutes, by splicing a property in between an attribute and the
        // property it belonged to.
        self::assertSame(
            'The old host was decommissioned last week and the runbook still names it.',
            $proposals[0]['comment'],
            'The headline and the prose are two stored fields, not one shown twice'
        );

        // Hop two: approval, which DESTROYS the proposal row the title arrived on.
        $this->reviewedRequest('POST', '/api/proposals/'.$proposals[0]['id'].'/approve');
        self::assertSame(200, $this->httpStatus(), $this->body());

        // Hop three: the history, months later, beside the version it replaced.
        $this->sessionRequest('GET', '/api/notes/'.$id.'/revisions');
        self::assertSame(200, $this->httpStatus(), $this->body());
        $revisions = $this->jsonResponse()['revisions'];
        self::assertNotEmpty($revisions, 'Precondition: approving the edit snapshotted the old body');
        self::assertSame(self::HEADLINE, $revisions[0]['change_title']);
        self::assertSame(
            'Deploys go to the old box.',
            $revisions[0]['body_md'],
            'and it is attached to the state that edit replaced, not to the new one'
        );
    }

    public function testACuratorsImmediateEditCarriesItsHeadlineToo(): void
    {
        // A curator's safe write never becomes a proposal — it applies on the
        // spot — so it reaches the revision by a different route entirely, and
        // that route was the one it would be natural to forget.
        $note = $this->kb->a->note('The runbook', 'Deploys go to the old box.');
        $id = (int) $note->getId();

        // A patch and not a body: a curator's whole-body edit is held for
        // review since C-9, and this test is about the edit that APPLIES.
        $this->callTool('propose', $this->kb->a->curatorBearer, [
            'note_id' => $id,
            'patch' => [['find' => 'the old box', 'replace' => 'the new box']],
            'change_title' => self::HEADLINE,
        ]);

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/notes/'.$id.'/revisions');
        $revisions = $this->jsonResponse()['revisions'];
        self::assertNotEmpty($revisions);
        self::assertSame(self::HEADLINE, $revisions[0]['change_title']);
    }

    public function testAnEditWithNoHeadlineIsRecordedWithoutOneRatherThanRefused(): void
    {
        // Null is the ordinary case, not a degraded one: every revision from
        // before 2026-08-23 has none, and so does every edit made by a person
        // in the web editor. Both screens fall back to what they showed before.
        $note = $this->kb->a->note('The runbook', 'Deploys go to the old box.');
        $id = (int) $note->getId();

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$id, ['body_md' => 'Deploys go to the new box.']);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('GET', '/api/notes/'.$id.'/revisions');
        $revisions = $this->jsonResponse()['revisions'];
        self::assertNotEmpty($revisions);
        self::assertNull($revisions[0]['change_title']);
    }

    public function testAHeadlineTooLongForTheColumnIsCutRatherThanLost(): void
    {
        // 120 characters is a subject line with room to spare. Refusing a
        // longer one would fail a write for a formatting opinion; silently
        // storing 400 would break the one-line layout both screens rely on.
        $note = $this->kb->a->note('The runbook', 'Deploys go to the old box.');
        $id = (int) $note->getId();

        $this->callTool('propose', $this->kb->a->agentBearer, [
            'note_id' => $id,
            'body_md' => 'Deploys go to the new box.',
            'change_title' => str_repeat('word ', 60),
        ]);

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/proposals');
        $title = $this->jsonResponse()['proposals'][0]['change_title'];
        self::assertNotNull($title);
        self::assertSame(120, mb_strlen($title));
    }

    public function testABlankHeadlineIsNullRatherThanAnEmptyLine(): void
    {
        // An empty string would render as a bold nothing above the note title
        // on both screens, which is worse than the fallback it displaces.
        $note = $this->kb->a->note('The runbook', 'Deploys go to the old box.');
        $id = (int) $note->getId();

        $this->callTool('propose', $this->kb->a->agentBearer, [
            'note_id' => $id,
            'body_md' => 'Deploys go to the new box.',
            'change_title' => '   ',
        ]);

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/proposals');
        self::assertNull($this->jsonResponse()['proposals'][0]['change_title']);
    }
}
