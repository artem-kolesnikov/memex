<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;

/**
 * `search` narrows by when a note last changed or was added, and a keyword
 * match carries the passage it matched, so "what changed since Monday" and
 * "what does the note say about X" each take one call.
 */
final class SearchDatesAndPassagesTest extends ApiTestCase
{
    /** @return array<string, mixed> the tool result payload */
    private function search(array $arguments): array
    {
        $this->request('POST', '/mcp', $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'search', 'arguments' => $arguments],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $result = $this->jsonResponse()['result'];

        return ($result['isError'] ?? false)
            ? ['error' => $result['content'][0]['text']]
            : json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private static function titles(array $payload): array
    {
        return array_map(static fn (array $item): string => $item['title'], $payload['items'] ?? []);
    }

    private function dated(string $title, string $created, string $updated, string $body = 'Body.'): Note
    {
        $note = $this->kb->a->note($title, $body);
        $this->in($this->kb->a);
        $this->em->getConnection()->executeStatement(
            'UPDATE notes SET created_at = :c, updated_at = :u WHERE id = :id',
            ['c' => $created, 'u' => $updated, 'id' => $note->getId()]
        );

        return $note;
    }

    public function testUpdatedAfterKeepsOnlyWhatChangedSinceThen(): void
    {
        $this->dated('Old', '2026-09-01 10:00:00', '2026-09-02 10:00:00');
        $this->dated('Edited Monday', '2026-09-01 10:00:00', '2026-10-12 08:00:00');
        $this->dated('Added Tuesday', '2026-10-13 09:00:00', '2026-10-13 09:00:00');

        self::assertSame(['Added Tuesday', 'Edited Monday'], self::titles($this->search(['updated_after' => '2026-10-12'])));
        self::assertSame(['Added Tuesday'], self::titles($this->search(['created_after' => '2026-10-12T00:00:00Z'])));
        self::assertSame(['Old'], self::titles($this->search(['updated_before' => '2026-10-12'])));
    }

    public function testAfterIncludesItsMomentAndBeforeExcludesIt(): void
    {
        $this->dated('On the dot', '2026-10-12 08:00:00', '2026-10-12 08:00:00');

        self::assertSame(['On the dot'], self::titles($this->search(['updated_after' => '2026-10-12T08:00:00Z'])));
        self::assertSame([], self::titles($this->search(['updated_before' => '2026-10-12T08:00:00Z'])));
    }

    public function testAnOffsetIsReadInUtc(): void
    {
        $this->dated('Late evening in Moscow', '2026-10-12 20:30:00', '2026-10-12 20:30:00');

        // 23:00 in Moscow is 20:00 UTC: the note, at 20:30 UTC, is after it.
        self::assertSame(['Late evening in Moscow'], self::titles($this->search(['updated_after' => '2026-10-12T23:00:00+03:00'])));
        self::assertSame([], self::titles($this->search(['updated_after' => '2026-10-12T21:00:00Z'])));
    }

    public function testTheWindowNarrowsAQueryToo(): void
    {
        $this->dated('Pump manual', '2026-09-01 10:00:00', '2026-09-01 10:00:00', 'The pump needs priming.');
        $this->dated('Pump log', '2026-10-14 10:00:00', '2026-10-14 10:00:00', 'Primed the pump again.');

        self::assertSame(['Pump log'], self::titles($this->search(['query' => 'pump', 'updated_after' => '2026-10-01'])));
    }

    public function testAMomentThatIsNotOneIsRefusedRatherThanIgnored(): void
    {
        $this->dated('Any', '2026-10-12 08:00:00', '2026-10-12 08:00:00');

        self::assertStringContainsString('updated_after must be an ISO-8601 instant', $this->search(['updated_after' => 'last monday'])['error'] ?? '');
        self::assertStringContainsString('not a real date', $this->search(['created_before' => '2026-02-31'])['error'] ?? '');
        self::assertStringContainsString('updated_after must be earlier than updated_before', $this->search([
            'updated_after' => '2026-10-12', 'updated_before' => '2026-10-01',
        ])['error'] ?? '');
    }

    public function testTheWebsitesListAndItsMapReadTheSameWindow(): void
    {
        $old = $this->dated('Old', '2026-09-01 10:00:00', '2026-09-02 10:00:00');
        $new = $this->dated('New', '2026-10-13 09:00:00', '2026-10-13 09:00:00');

        $this->loginAs($this->kb->a);
        $this->client->request('GET', '/api/notes?updated_after=2026-10-01');
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame([$new->getId()], array_column($this->jsonResponse()['items'], 'id'));

        $this->client->request('GET', '/api/notes/ids?updated_after=2026-10-01');
        self::assertSame([$new->getId()], $this->jsonResponse()['ids']);

        $this->client->request('GET', '/api/notes?updated_after=soon');
        self::assertSame(400, $this->httpStatus());
        self::assertNotSame($old->getId(), $new->getId());
    }

    public function testAKeywordMatchCarriesThePassageItMatched(): void
    {
        $filler = str_repeat('Unrelated words about the garden shed and its roof. ', 30);
        $this->kb->a->note('Boiler', $filler.'The boiler pressure must stay between 1.2 and 1.5 bar. '.$filler);

        $item = $this->search(['query' => 'pressure'])['items'][0];

        self::assertArrayHasKey('matched_text', $item);
        self::assertStringContainsString('pressure must stay between', $item['matched_text']);
        self::assertLessThan(400, mb_strlen($item['matched_text']), 'a passage, not the body');
    }

    public function testATitleOnlyMatchCarriesNoPassage(): void
    {
        $this->kb->a->note('Pressure washer', 'Hire it from the shop on the corner.');

        $item = $this->search(['query' => 'pressure'])['items'][0];

        self::assertSame('Pressure washer', $item['title']);
        self::assertArrayNotHasKey('matched_text', $item);
    }

    public function testANegatedQueryCarriesNoPassage(): void
    {
        $this->kb->a->note('Shed', 'The roof leaks.');
        $this->kb->a->note('Boiler', 'The pressure drops.');

        $payload = $this->search(['query' => 'NOT pressure']);

        self::assertSame(['Shed'], self::titles($payload));
        self::assertArrayNotHasKey('matched_text', $payload['items'][0]);
    }
}
