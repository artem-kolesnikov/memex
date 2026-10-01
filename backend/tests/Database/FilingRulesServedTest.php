<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\FilingRules;

/**
 * The filing rule is served twice and has to say the same thing twice.
 *
 * An agent that loads the curation skill reads the charter; an assistant that
 * never loads a skill reads the tool schema and has nothing else. Both are
 * memex telling somebody how to write a held item, and on 2026-09-16 they
 * disagreed for a day — the charter gained two exceptions and the schema went
 * on saying "nothing else", so which rule an agent followed depended on which
 * door it came through.
 *
 * Asserted against what is SERVED rather than against the files: the charter
 * is composed with a vault's brief before it goes out, and a schema is built
 * per request.
 */
final class FilingRulesServedTest extends ApiTestCase
{
    private function served(string $method, array $params): array
    {
        $this->client->getCookieJar()->clear();
        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params,
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        return $this->jsonResponse()['result'];
    }

    private function charter(): string
    {
        $result = $this->served('tools/call', ['name' => 'get_skill', 'arguments' => ['slug' => 'memex-curation']]);

        return json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR)['instructions'];
    }

    public function testTheCharterSaysEveryClauseTheSchemaDoes(): void
    {
        $charter = $this->charter();

        foreach (FilingRules::clauses() as $clause) {
            self::assertTrue(
                FilingRules::says($charter, $clause),
                "the charter no longer says “{$clause}”, which the tool schema still promises"
            );
        }
    }

    public function testEveryToolFieldCarriesTheClausesItIsResponsibleFor(): void
    {
        $tools = [];
        foreach ($this->served('tools/list', [])['tools'] as $tool) {
            $tools[$tool['name']] = $tool['inputSchema']['properties'];
        }

        foreach (FilingRules::SERVED as $tool => $fields) {
            self::assertArrayHasKey($tool, $tools, "$tool is no longer served");
            foreach ($fields as $field => $clauses) {
                self::assertArrayHasKey($field, $tools[$tool], "$tool has no $field");
                foreach ($clauses as $clause) {
                    self::assertTrue(
                        FilingRules::says($tools[$tool][$field]['description'], $clause),
                        "$tool.$field no longer says “{$clause}”, which the charter does"
                    );
                }
            }
        }
    }
}
