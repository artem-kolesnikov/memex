<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * OR, NOT, grouping and phrases in the search box and the MCP `search` tool,
 * end to end against FTS5. The parser's own cases are in
 * {@see \App\Tests\Service\KeywordQueryTest}; this file proves the expression
 * it emits does what the guide says, and that an expression never buys an
 * embedding — a NOT has no vector, and the nearest notes to it would be the
 * ones it excludes.
 */
final class BooleanSearchTest extends ApiTestCase
{
    private function seed(): void
    {
        $this->kb->a->note('Memex design', 'How memex keeps notes.');
        $this->kb->a->note('Memory systems', 'Memory and ai, side by side.');
        $this->kb->a->note('Agents at work', 'Where ai meets memex.');
        $this->kb->a->note('The review gate', 'Agent writes land pending until the gate opens.');
    }

    /** @return list<string> */
    private function titles(string $query): array
    {
        $this->request('GET', '/api/notes?q='.urlencode($query), $this->kb->a->agentBearer);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $query);

        return array_column($this->jsonResponse()['items'], 'title');
    }

    public function testOrNotAndGrouping(): void
    {
        $this->seed();

        self::assertEqualsCanonicalizing(['Memex design'], $this->titles('(memex OR memory) NOT ai'));
        self::assertEqualsCanonicalizing(['Memex design', 'Memory systems', 'Agents at work'], $this->titles('memex OR memory'));
        self::assertEqualsCanonicalizing(['Memex design'], $this->titles('memex -ai'));
        self::assertEqualsCanonicalizing(['The review gate'], $this->titles('"review gate"'));
        self::assertEqualsCanonicalizing([], $this->titles('"gate review"'), 'A phrase is the words in that order');
        self::assertEqualsCanonicalizing(['Memex design', 'The review gate'], $this->titles('NOT ai'));
    }

    public function testAnExpressionThatMatchesNothingIsEmptyAndBuysNoEmbedding(): void
    {
        $this->seed();
        $before = $this->embedCalls();

        $this->request('GET', '/api/notes?q='.urlencode('(zqxjvw OR zzqq) NOT ai'), $this->kb->a->agentBearer);
        $body = $this->jsonResponse();

        self::assertSame(0, $body['total']);
        self::assertTrue($body['keyword_only']);
        self::assertFalse($body['semantic_unavailable']);
        self::assertSame($before, $this->embedCalls(), 'An expression fell back to meaning-search');
    }

    /**
     * Codex, 2026-09-21: `AND` alone parsed to an empty tsquery with no
     * operator flag, so it embedded, and with the semantic tier unavailable
     * the missing keyword clause returned the whole listing.
     */
    public function testOperatorsWithNoWordsAreEmptyNotTheListing(): void
    {
        $this->seed();
        $before = $this->embedCalls();

        // Not `!` or `()`: a query with no letter or digit has always meant
        // "list everything" (HybridSearch nulls it before parsing).
        foreach (['AND', 'AND AND', 'NOT OR'] as $query) {
            $this->request('GET', '/api/notes?q='.urlencode($query), $this->kb->a->agentBearer);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $query);
            self::assertSame(0, $this->jsonResponse()['total'], $query.' returned rows');
        }
        self::assertSame($before, $this->embedCalls(), 'An operator-only query was embedded');
    }

    /**
     * Codex, 2026-09-21: the canary probes a title with the literal ANDed
     * words and then searched it through the parser, so a title carrying an
     * operator excluded itself. `keywordOnly` is the literal path.
     */
    public function testTheCanaryPathReadsATitleLiterally(): void
    {
        $note = $this->kb->a->note('Memex NOT ai', 'A title with an operator in it.');
        $search = static::getContainer()->get(\App\Service\HybridSearch::class);

        $literal = $search->search('Memex NOT ai', [], null, null, 1, 10, keywordOnly: true);
        self::assertSame([$note->getId()], array_map(static fn (array $row): int => (int) $row['id'], $literal['items']));

        $parsed = $search->search('Memex NOT ai', [], null, null, 1, 10);
        self::assertSame(0, $parsed['total'], 'The same words as an expression exclude the note, by design');
    }

    public function testAPlainQueryStillFallsBackToMeaning(): void
    {
        $this->seed();
        $before = $this->embedCalls();

        $this->request('GET', '/api/notes?q=zqxjvw', $this->kb->a->agentBearer);

        self::assertFalse($this->jsonResponse()['keyword_only']);
        self::assertSame($before + 1, $this->embedCalls());
    }

    public function testTheMcpToolSaysWhenItMatchedAsWritten(): void
    {
        $this->seed();

        $this->request('POST', '/mcp', $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'search', 'arguments' => ['query' => '(memex OR memory) NOT ai']],
        ]);
        $result = json_decode($this->jsonResponse()['result']['content'][0]['text'], true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(['Memex design'], array_column($result['items'], 'title'));
        self::assertArrayHasKey('exact_match', $result);

        $this->request('POST', '/mcp', $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'search', 'arguments' => ['query' => 'memex']],
        ]);
        $plain = json_decode($this->jsonResponse()['result']['content'][0]['text'], true, 16, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('exact_match', $plain);
    }

    private function embedCalls(): int
    {
        return count(array_filter($this->ml->calls, static fn (array $c): bool => str_contains($c['url'], '/api/v1/create-embeddings')));
    }
}
