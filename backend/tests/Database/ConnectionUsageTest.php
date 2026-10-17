<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\ConnectionUsage;

/**
 * What each connection did, counted by day: how often it opened memex and
 * which tools it called, each answered, refused or failed. The handshake a
 * client runs when a chat starts is counted but is not use, and nothing an
 * assistant sent is kept, only the tool's name.
 */
final class ConnectionUsageTest extends ApiTestCase
{
    private function rpc(string $method, array $params = [], ?string $bearer = null): void
    {
        $this->request('POST', '/mcp', $bearer ?? $this->kb->a->agentBearer, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        self::assertSame(200, $this->httpStatus(), $method.': '.$this->body());
    }

    private function call(string $tool, array $arguments = []): void
    {
        $this->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments]);
    }

    /** @return array<string, array{calls: int, refused: int, failed: int}> today's rows for A's agent, by kind */
    private function counted(): array
    {
        $this->in($this->kb->a);
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT kind, calls, refused, failed FROM connection_usage WHERE token_id = ? ORDER BY kind',
            [$this->kb->a->agentTokenId],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[$row['kind']] = ['calls' => (int) $row['calls'], 'refused' => (int) $row['refused'], 'failed' => (int) $row['failed']];
        }

        return $out;
    }

    private function lastCalled(): ?string
    {
        $this->in($this->kb->a);
        $at = $this->em->getConnection()->fetchOne('SELECT last_called_at FROM api_tokens WHERE id = ?', [$this->kb->a->agentTokenId]);

        return $at === null || $at === false ? null : (string) $at;
    }

    public function testOpeningMemexIsCountedButIsNotUse(): void
    {
        $this->rpc('initialize', ['protocolVersion' => '2025-06-18']);
        $this->rpc('tools/list');
        $this->rpc('prompts/list');
        $this->rpc('resources/list');

        self::assertSame([
            'initialize' => ['calls' => 1, 'refused' => 0, 'failed' => 0],
            'tools/list' => ['calls' => 1, 'refused' => 0, 'failed' => 0],
        ], $this->counted());
        self::assertNull($this->lastCalled(), 'The handshake Claude runs at the start of every chat read as the assistant using memex');
    }

    public function testEachCallIsCountedUnderItsToolWithWhatBecameOfIt(): void
    {
        $this->kb->a->note('Zebra ledger', 'The zebra ledger body.');

        $this->call('search', ['query' => 'zebra ledger']);
        $this->call('search', ['query' => 'zebra']);
        $this->call('get', ['id' => 999999]);
        $this->call('no_such_tool');
        $this->rpc('prompts/get', ['name' => 'no-such-skill']);

        self::assertSame([
            '(unknown tool)' => ['calls' => 1, 'refused' => 1, 'failed' => 0],
            'get' => ['calls' => 1, 'refused' => 1, 'failed' => 0],
            'prompts/get' => ['calls' => 1, 'refused' => 1, 'failed' => 0],
            'search' => ['calls' => 2, 'refused' => 0, 'failed' => 0],
        ], $this->counted());
        self::assertNotNull($this->lastCalled(), 'A tool call did not stamp the assistant\'s last use');

        $this->in($this->kb->a);
        $kept = json_encode($this->em->getConnection()->fetchAllAssociative('SELECT * FROM connection_usage'), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsStringIgnoringCase('zebra', $kept, 'What the assistant searched for was kept');
        self::assertStringNotContainsString('no-such-skill', $kept);
        self::assertStringNotContainsString('no_such_tool', $kept, 'A name the client made up became a row of its own');
    }

    public function testAFailureOnMemexSideIsCountedAsFailed(): void
    {
        $this->in($this->kb->a);
        $this->em->getConnection()->executeStatement('ALTER TABLE tags RENAME TO tags_unreachable');

        $this->request('POST', '/mcp', $this->kb->a->agentBearer, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_tags']]);

        self::assertSame(['list_tags' => ['calls' => 1, 'refused' => 0, 'failed' => 1]], $this->counted());
    }

    public function testOnlyFourteenDaysAreKept(): void
    {
        $this->in($this->kb->a);
        $db = $this->em->getConnection();
        $first = ConnectionUsage::firstDay();
        $gone = (new \DateTimeImmutable($first, new \DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
        foreach ([$first, $gone] as $day) {
            $db->executeStatement("INSERT INTO connection_usage (token_id, day, kind, calls) VALUES (?, ?, 'search', 3)", [$this->kb->a->agentTokenId, $day]);
        }

        $this->rpc('initialize', ['protocolVersion' => '2025-06-18']);

        $this->in($this->kb->a);
        self::assertSame(
            [$first, gmdate('Y-m-d')],
            array_map('strval', $db->fetchFirstColumn('SELECT DISTINCT day FROM connection_usage ORDER BY day')),
        );
        self::assertSame(3, static::getContainer()->get(ConnectionUsage::class)->totals()['calls']);
    }

    public function testAnApiRequestWithATokenIsUse(): void
    {
        $this->request('GET', '/api/me', $this->kb->a->agentBearer);
        self::assertSame(200, $this->httpStatus());

        self::assertNotNull($this->lastCalled());
        self::assertSame([], $this->counted(), 'Only MCP is counted by tool');
    }
}
