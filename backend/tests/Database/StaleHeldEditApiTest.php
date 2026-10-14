<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;

/**
 * What the owner and the connections see of a stale draft (operator,
 * 2026-10-13): the writer that made it stale is told, the card carries what
 * the author read, a batch leaves it waiting, and one approval still decides it.
 */
final class StaleHeldEditApiTest extends ApiTestCase
{
    public function testAStaleDraftIsShownOnItsCardSkippedByABatchAndApprovableAlone(): void
    {
        $note = $this->kb->b->note('Sign-in record', "Apple is pending until the review closes on Friday.\n\nMicrosoft is pending.")->getId();
        $this->request('PUT', '/api/notes/'.$note, $this->kb->b->agentBearer, ['patch' => [['find' => 'Apple is pending until', 'replace' => 'Apple is live until']]]);
        self::assertSame(202, $this->httpStatus(), $this->body());
        $proposal = $this->jsonResponse()['proposal']['id'];

        // The owner changes another word of the sentence the draft holds.
        $this->loginAs($this->kb->b);
        $this->sessionRequest('PUT', '/api/notes/'.$note, ['body_md' => "Apple is pending until the review closes on Monday.\n\nMicrosoft is pending."]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('GET', '/api/proposals/'.$proposal);
        $card = $this->jsonResponse();
        self::assertSame(['body'], $card['stale']);
        self::assertSame("Apple is pending until the review closes on Friday.\n\nMicrosoft is pending.", $card['base']['body_md']);
        $seen = ['expected_revision' => $card['revision'], 'expected_version' => $card['note']['version']];

        $this->sessionRequest('POST', '/api/inbox/batch', ['action' => 'approve', 'items' => [['kind' => 'proposal', 'id' => $proposal] + $seen]]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame(0, $this->jsonResponse()['done']);
        self::assertSame('stale', $this->jsonResponse()['failed'][0]['reason']);

        $this->sessionRequest('POST', '/api/proposals/'.$proposal.'/approve', $seen);
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame("Apple is live until the review closes on Monday.\n\nMicrosoft is pending.", $this->jsonResponse()['note']['body_md']);
    }

    /**
     * Over MCP, where the agent of 2026-10-13 was stuck. A curator can no longer
     * rewrite the sentence an agent's draft holds (operator, 2026-10-14); the
     * owner can, and the author's next edit then replaces its stale draft.
     */
    public function testOverMcpTheCuratorIsRefusedTheHeldSentenceAndTheAuthorHearsItsDraftWasReplaced(): void
    {
        $note = (int) $this->kb->b->note('Factory record', "The first epic has not been filed yet.\n\nThe Designer is not on the squad.")->getId();
        $this->propose($this->kb->b->agentBearer, $note, 'The first epic has not been filed yet.', 'The first epic, TROI-5, is filed.');

        $this->request('POST', '/mcp', $this->kb->b->curatorBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'propose', 'arguments' => ['note_id' => $note, 'patch' => [['find' => 'The first epic has not been filed yet.', 'replace' => 'The first epic, TROI-5, has its first stage merged.']]]],
        ]);
        self::assertTrue($this->jsonResponse()['result']['isError'] ?? false, 'The curator is refused the sentence the agent holds');
        self::assertStringContainsString('Held by', $this->jsonResponse()['result']['content'][0]['text']);

        $this->loginAs($this->kb->b);
        $this->sessionRequest('PUT', '/api/notes/'.$note, ['body_md' => "The first epic, TROI-5, has its first stage merged.\n\nThe Designer is not on the squad."]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $agent = $this->propose($this->kb->b->agentBearer, $note, 'The Designer is not on the squad.', 'The Designer joined the squad.');
        self::assertStringContainsString('replaced it', $agent['review']);
        self::assertSame(
            "The first epic, TROI-5, is filed.\n\nThe Designer is not on the squad.",
            \App\Service\NotePatch::apply("The first epic has not been filed yet.\n\nThe Designer is not on the squad.", $agent['proposal']['replaced_stale_draft']['proposed_patch']),
            'The author is handed back what its stale draft held',
        );
        self::assertSame([], $agent['proposal']['stale']);
    }

    /** @return array<string, mixed> */
    private function propose(string $bearer, int $note, string $find, string $replace): array
    {
        $this->request('POST', '/mcp', $bearer, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'propose', 'arguments' => ['note_id' => $note, 'patch' => [['find' => $find, 'replace' => $replace]]]],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $result = $this->jsonResponse()['result'];
        self::assertFalse($result['isError'] ?? false, $result['content'][0]['text'] ?? '');

        return json_decode($result['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);
    }

    /** Met on 2026-10-13: the note failed in the batch that approved its edit, and went through on a second press. */
    public function testAPendingNoteAndItsOwnHeldEditApproveInOneBatch(): void
    {
        $this->request('POST', '/api/notes', $this->kb->b->agentBearer, ['title' => 'MM3 record', 'body_md' => 'Stage 1 is open.']);
        self::assertSame(201, $this->httpStatus(), $this->body());
        $note = $this->jsonResponse()['note']['id'];
        $this->request('PUT', '/api/notes/'.$note, $this->kb->b->agentBearer, ['patch' => [['find' => 'Stage 1 is open.', 'replace' => 'Stage 1 is open; the Designer joined.']]]);
        self::assertSame(202, $this->httpStatus(), $this->body());
        $proposal = $this->jsonResponse()['proposal']['id'];

        $this->loginAs($this->kb->b);
        $this->sessionRequest('GET', '/api/proposals/'.$proposal);
        $card = $this->jsonResponse();
        $this->sessionRequest('GET', '/api/notes/'.$note);
        $version = $this->jsonResponse()['version'];

        $this->sessionRequest('POST', '/api/inbox/batch', ['action' => 'approve', 'items' => [
            ['kind' => 'proposal', 'id' => $proposal, 'expected_revision' => $card['revision'], 'expected_version' => $card['note']['version']],
            ['kind' => 'note', 'id' => $note, 'expected_version' => $version],
        ]]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame([], $this->jsonResponse()['failed']);
        self::assertSame(2, $this->jsonResponse()['done']);

        $this->in($this->kb->b);
        $approved = $this->findNumbered($note);
        self::assertSame(Note::STATUS_VERIFIED, $approved->getStatus());
        self::assertSame('Stage 1 is open; the Designer joined.', $approved->getBodyMd());
    }

    /** Two rewrites held before drafts recorded a base: approving both in one batch must not let the second undo the first. */
    public function testABatchDoesNotCarryForwardForDraftsWithNoBase(): void
    {
        $note = $this->kb->b->note('Sign-in record', 'Microsoft is pending. Apple is pending.')->getId();
        $this->request('PUT', '/api/notes/'.$note, $this->kb->b->agentBearer, ['body_md' => 'Microsoft is live. Apple is pending.']);
        $first = $this->jsonResponse()['proposal']['id'];
        $this->request('PUT', '/api/notes/'.$note, $this->kb->b->curatorBearer, ['body_md' => 'Microsoft is pending. Apple is live.']);
        self::assertSame(202, $this->httpStatus(), 'A curator\'s whole body is held: '.$this->body());
        $second = $this->jsonResponse()['proposal']['id'];
        $this->in($this->kb->b);
        $this->em->getConnection()->executeStatement('UPDATE edit_proposals SET base = NULL');
        $this->em->clear();

        $this->loginAs($this->kb->b);
        $items = [];
        foreach ([$first, $second] as $id) {
            $this->sessionRequest('GET', '/api/proposals/'.$id);
            $card = $this->jsonResponse();
            $items[] = ['kind' => 'proposal', 'id' => $id, 'expected_revision' => $card['revision'], 'expected_version' => $card['note']['version']];
        }
        $this->sessionRequest('POST', '/api/inbox/batch', ['action' => 'approve', 'items' => $items]);
        self::assertSame(1, $this->jsonResponse()['done']);
        self::assertSame($second, $this->jsonResponse()['failed'][0]['id']);

        $this->in($this->kb->b);
        self::assertSame('Microsoft is live. Apple is pending.', $this->findNumbered($note)->getBodyMd());
    }

    /** The batch's own write is carried forward; a change from anywhere else still stops it. */
    public function testABatchStillRefusesANoteChangedBehindTheOperatorsBack(): void
    {
        $this->request('POST', '/api/notes', $this->kb->b->agentBearer, ['title' => 'MM3 record', 'body_md' => 'Stage 1 is open.']);
        $note = $this->jsonResponse()['note']['id'];
        $this->loginAs($this->kb->b);
        $this->sessionRequest('GET', '/api/notes/'.$note);
        $version = $this->jsonResponse()['version'];

        $this->request('PUT', '/api/notes/'.$note, $this->kb->b->curatorBearer, ['patch' => [['find' => 'Stage 1 is open.', 'replace' => 'Stage 1 is closed.']]]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->loginAs($this->kb->b);
        $this->sessionRequest('POST', '/api/inbox/batch', ['action' => 'approve', 'items' => [['kind' => 'note', 'id' => $note, 'expected_version' => $version]]]);
        self::assertSame(0, $this->jsonResponse()['done']);
        self::assertArrayNotHasKey('reason', $this->jsonResponse()['failed'][0]);
    }
}
