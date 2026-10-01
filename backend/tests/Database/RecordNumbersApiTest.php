<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\CuratorLogEntry;
use App\Entity\EditProposal;
use App\Tests\Support\Tenant;

/**
 * Every surface that names a proposal or a journal row names it by its number
 * within the vault: the inbox, the journal and its exports, the curation
 * digest, run links and MCP (operator, 2026-09-22 — a new account's first held
 * edit showed as #1438).
 *
 * Vault A writes first in every test, so a number drawn from anything the two
 * vaults share would put B's records past 1.
 */
final class RecordNumbersApiTest extends ApiTestCase
{
    /** @return array<string, mixed> */
    private function tool(string $name, string $bearer, array $arguments = []): array
    {
        $this->request('POST', '/mcp', $bearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $result = $this->jsonResponse()['result'];
        self::assertNotTrue($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));

        return json_decode($result['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);
    }

    private function held(Tenant $t, string $title): EditProposal
    {
        $note = $t->note($title);
        $this->request('PUT', '/api/notes/'.$note->getId(), $t->agentBearer, ['title' => $title.' (proposed)']);
        self::assertSame(202, $this->httpStatus(), $this->body());
        $this->in($t);

        return $this->em->getRepository(EditProposal::class)->findOneBy(['note' => $note->getId()], ['id' => 'DESC']);
    }

    private function aheadInTeamA(): void
    {
        foreach (['One', 'Two', 'Three'] as $title) {
            $this->held($this->kb->a, 'A '.$title);
            $this->tool('log', $this->kb->a->curatorBearer, ['action' => 'observation', 'description' => 'A noticed '.$title.'.']);
        }
    }

    public function testTheInboxNumbersAFreshTeamsProposalsFromOne(): void
    {
        $this->aheadInTeamA();
        $proposal = $this->held($this->kb->b, 'B note');
        $noteId = $proposal->getNote()->getId();
        self::assertSame(1, $proposal->getId(), 'B numbers from 1 whatever A holds');

        $this->loginAs($this->kb->b);
        $this->sessionRequest('GET', '/api/proposals');
        self::assertSame([1], array_column($this->jsonResponse()['proposals'], 'id'));

        $this->sessionRequest('GET', '/api/proposals/1');
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame('B note (proposed)', $this->jsonResponse()['proposed_title']);

        $this->reviewedRequest('POST', '/api/proposals/1/approve');
        self::assertSame(200, $this->httpStatus(), $this->body());
        $this->in($this->kb->b);
        self::assertSame('B note (proposed)', $this->em->getConnection()->fetchOne(
            'SELECT title FROM notes WHERE id = :id', ['id' => $noteId]
        ));
    }

    public function testAProposalsGlobalIdNoLongerAddressesIt(): void
    {
        $this->aheadInTeamA();
        $this->held($this->kb->b, 'B note');

        // A number A holds and B does not.
        $this->loginAs($this->kb->b);
        $this->sessionRequest('GET', '/api/proposals/3');
        self::assertSame(404, $this->httpStatus());
        self::assertStringNotContainsString('A Three', $this->body());
    }

    public function testABatchDecisionTakesProposalNumbers(): void
    {
        $this->aheadInTeamA();
        $this->held($this->kb->b, 'B note');

        $this->loginAs($this->kb->b);
        $this->reviewedRequest('POST', '/api/inbox/batch', [
            'action' => 'reject',
            'items' => [['kind' => 'proposal', 'id' => 1]],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $this->in($this->kb->b);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM edit_proposals'));
    }

    public function testMcpReportsTheProposalNumber(): void
    {
        $this->aheadInTeamA();
        $note = $this->kb->b->note('B note');

        $held = $this->tool('propose', $this->kb->b->agentBearer, ['note_id' => $note->getId(), 'title' => 'Renamed']);

        self::assertSame(1, $held['proposal']['id']);
    }

    public function testTheJournalNumbersAFreshTeamsRowsFromOne(): void
    {
        $this->aheadInTeamA();
        $first = $this->tool('log', $this->kb->b->curatorBearer, ['action' => 'observation', 'description' => 'B first.']);
        $second = $this->tool('log', $this->kb->b->curatorBearer, ['action' => 'observation', 'description' => 'B second.']);

        self::assertSame([1, 2], [$first['id'], $second['id']]);

        $recent = $this->tool('log_recent', $this->kb->b->curatorBearer);
        self::assertSame([2, 1], array_column($recent['entries'], 'id'));

        $this->loginAs($this->kb->b);
        $this->sessionRequest('GET', '/api/curator-log');
        self::assertSame([2, 1], array_column($this->jsonResponse()['entries'], 'id'));
    }

    public function testARunIsLinkedAndScopedByItsNumber(): void
    {
        $this->aheadInTeamA();
        $note = $this->kb->b->note('B read');
        $this->tool('log', $this->kb->b->curatorBearer, ['action' => 'observation', 'description' => 'Before the pass.']);
        $summary = $this->tool('log', $this->kb->b->curatorBearer, [
            'action' => 'run-summary',
            'description' => 'B ran a pass.',
            'examined' => [$note->getId()],
            'started_at' => (new \DateTimeImmutable('-1 minute'))->format(DATE_ATOM),
        ]);
        self::assertSame(2, $summary['id']);

        $this->in($this->kb->b);
        $examined = $this->em->getRepository(CuratorLogEntry::class)->findOneBy(['action' => 'examined']);
        self::assertStringContainsString('(log entry 2)', $examined->getDescription());

        $this->loginAs($this->kb->b);
        $this->sessionRequest('GET', '/api/curation/digest');
        self::assertSame(2, $this->jsonResponse()['runs'][0]['log_id']);

        $this->sessionRequest('GET', '/api/curator-log?run=2');
        self::assertSame(200, $this->httpStatus(), $this->body());
        $page = $this->jsonResponse();
        self::assertSame(2, $page['run']['log_id']);
        foreach ($page['entries'] as $entry) {
            self::assertSame(2, $entry['curation_run'], json_encode($entry, JSON_THROW_ON_ERROR));
        }
        self::assertContains(3, array_column($page['entries'], 'id'), 'The examined row, B\'s third, comes in by its link to the run');
    }

    public function testTheExportsCiteNumbers(): void
    {
        $this->aheadInTeamA();
        $this->tool('log', $this->kb->b->curatorBearer, ['action' => 'observation', 'description' => 'B first.']);

        $this->loginAs($this->kb->b);
        $this->sessionRequest('GET', '/api/curator-log/export?format=md');
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('## #1 · observation', $this->client->getInternalResponse()->getContent());

        $this->sessionRequest('GET', '/api/curator-log/export?format=csv');
        $lines = array_values(array_filter(explode("\n", $this->client->getInternalResponse()->getContent())));
        self::assertStringStartsWith('1,', $lines[1]);
    }
}
