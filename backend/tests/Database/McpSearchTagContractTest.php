<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * The `search` tool's tag filter, and the promise its schema makes.
 *
 * The schema says "Only notes carrying ALL these tags". The filter is built by
 * resolving names to ids and adding one EXISTS per id, so a name that resolves
 * to nothing simply stops contributing a clause — and until 2026-08-24 that
 * happened in silence. The caller is a model, models misspell tags, and the
 * failure was invisible from the outside: you got a correct answer to a query
 * you had not asked, with the same shape as a correct answer to the one you
 * had.
 *
 * These tests pin the contract rather than the implementation: what a caller
 * can conclude from a response, when one of the names it sent was wrong.
 */
final class McpSearchTagContractTest extends ApiTestCase
{
    /** @return array<string, mixed> the decoded tool payload */
    private function search(string $bearer, array $arguments): array
    {
        $this->request('POST', '/mcp', $bearer, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'search', 'arguments' => $arguments],
        ]);
        self::assertSame(200, $this->httpStatus(), 'search did not answer: '.$this->body());
        $result = $this->jsonResponse()['result'];

        return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function testAMisspelledTagIsReportedRatherThanSilentlyDropped(): void
    {
        $this->kb->a->note('Provisioning the box', 'Body.', ['infra']);
        $this->kb->a->note('Unrelated', 'Body.', ['recipes']);

        $payload = $this->search($this->kb->a->agentBearer, ['tags' => ['infra', 'infrastrucutre']]);

        // The resolvable half still answers — refusing the whole call would
        // punish the common case of remembering most of the names correctly.
        self::assertSame(1, $payload['total'], 'the resolvable tag still filters');
        // ...but the caller is told what was ignored, which is the whole fix.
        // Without this key the response is indistinguishable from a correct
        // answer to `tags: ["infra"]`, which is not what was asked.
        self::assertSame(['infrastrucutre'], $payload['unknown_tags'] ?? null);
    }

    public function testAFullyCorrectQuerySaysNothingAboutUnknownTags(): void
    {
        $this->kb->a->note('Provisioning the box', 'Body.', ['infra']);

        $payload = $this->search($this->kb->a->agentBearer, ['tags' => ['infra']]);

        self::assertSame(1, $payload['total']);
        // Absent, not empty: a key that is always present is a key an agent
        // learns to skip.
        self::assertArrayNotHasKey('unknown_tags', $payload);
    }

    public function testWhenEveryNameIsUnknownTheZeroComesWithItsReason(): void
    {
        $this->kb->a->note('Provisioning the box', 'Body.', ['infra']);

        $payload = $this->search($this->kb->a->agentBearer, ['tags' => ['nonesuch', 'alsonot']]);

        self::assertSame(0, $payload['total'], 'no filter survives, so no rows are the honest answer');
        self::assertSame(['nonesuch', 'alsonot'], $payload['unknown_tags'] ?? null);
    }

    public function testTheContractIsAdvertisedInTheToolSchema(): void
    {
        $this->request('POST', '/mcp', $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
        ]);
        $tools = $this->jsonResponse()['result']['tools'];
        $search = null;
        foreach ($tools as $tool) {
            if ($tool['name'] === 'search') {
                $search = $tool;
            }
        }
        self::assertNotNull($search, 'search is not advertised at all');

        // The schema is product surface for an assistant: a behaviour it
        // cannot read about is a behaviour it will not use.
        self::assertStringContainsString(
            'unknown_tags',
            $search['inputSchema']['properties']['tags']['description'],
            'the tags parameter does not tell the caller that unmatched names are reported'
        );
    }

    public function testAnotherTeamsTagNameIsUnknownHere(): void
    {
        // Tenancy, stated as a contract rather than assumed: `infra` existing
        // in team B must not resolve for team A, and must be REPORTED unknown
        // rather than quietly ignored — otherwise the report itself would leak
        // which tag names exist next door.
        $this->kb->b->note('Their note', 'Body.', ['infra']);
        $this->kb->a->note('Our note', 'Body.', ['recipes']);

        $payload = $this->search($this->kb->a->agentBearer, ['tags' => ['infra']]);

        self::assertSame(0, $payload['total']);
        self::assertSame(['infra'], $payload['unknown_tags'] ?? null);
    }
}
