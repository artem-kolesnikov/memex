<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\SkillServing;

final class SkillPushTest extends ApiTestCase
{
    private function rpc(string $bearer, string $method, array $params = []): array
    {
        $this->request('POST', '/mcp', $bearer, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        self::assertSame(200, $this->httpStatus());

        return $this->jsonResponse();
    }

    private function toolPayload(array $rpc): array
    {
        return json_decode($rpc['result']['content'][0]['text'], true);
    }

    public function testInitializeAndTheToolDescriptionNameTheServedList(): void
    {
        $this->kb->a->note('House style', 'Cite the source.', ['skill'], 'How notes are written here.');
        $init = $this->rpc($this->kb->a->agentBearer, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']]);
        self::assertStringContainsString('`house-style` — How notes are written here. — get_skill("house-style") or prompt "house-style"', $init['result']['instructions']);
        self::assertStringContainsString('`memex-guide`', $init['result']['instructions']);

        $tools = $this->rpc($this->kb->a->agentBearer, 'tools/list');
        $listSkills = array_values(array_filter($tools['result']['tools'], static fn ($t) => $t['name'] === 'list_skills'))[0];
        self::assertStringContainsString('`house-style`', $listSkills['description']);
    }

    public function testACommandOnlySkillsLineOffersOnlyThePrompt(): void
    {
        $note = $this->kb->a->note('Codex only', 'For Codex.', ['skill'], 'Runs only as a prompt.');
        $this->in($this->kb->a);
        self::getContainer()->get(SkillServing::class)->update($note->getId(), ['auto' => false]);
        $init = $this->rpc($this->kb->a->agentBearer, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']]);
        $line = array_values(array_filter(explode("\n", $init['result']['instructions']), static fn ($l) => str_starts_with($l, '`codex-only`')))[0];
        self::assertStringContainsString('prompt "', $line);
        self::assertStringNotContainsString('get_skill(', $line);
    }

    public function testASkillWithBothSwitchesOffIsAdvertisedNowhere(): void
    {
        $note = $this->kb->a->note('Codex only', 'For Codex.', ['skill'], 'Runs only as a prompt.');
        $this->in($this->kb->a);
        self::getContainer()->get(SkillServing::class)->update($note->getId(), ['auto' => false]);
        $withCommand = $this->rpc($this->kb->a->agentBearer, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']]);
        self::assertStringContainsString('`codex-only`', $withCommand['result']['instructions']);
        $versionWithCommand = $this->toolPayload($this->rpc($this->kb->a->agentBearer, 'tools/call', ['name' => 'health', 'arguments' => []]))['skills_version'];

        $this->in($this->kb->a);
        self::getContainer()->get(SkillServing::class)->update($note->getId(), ['command' => false]);
        $init = $this->rpc($this->kb->a->agentBearer, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']]);
        self::assertStringNotContainsString('codex-only', $init['result']['instructions']);

        $tools = $this->rpc($this->kb->a->agentBearer, 'tools/list');
        $listSkills = array_values(array_filter($tools['result']['tools'], static fn ($t) => $t['name'] === 'list_skills'))[0];
        self::assertStringNotContainsString('codex-only', $listSkills['description']);

        $payload = $this->toolPayload($this->rpc($this->kb->a->agentBearer, 'tools/call', ['name' => 'health', 'arguments' => []]));
        self::assertNotSame($versionWithCommand, $payload['skills_version'], 'turning the second switch off changes skills_version');
    }

    public function testAChangeIsAnnouncedOnceOnTheNextToolResult(): void
    {
        $note = $this->kb->a->note('House style', 'Cite the source.', ['skill']);
        $first = $this->toolPayload($this->rpc($this->kb->a->agentBearer, 'tools/call', ['name' => 'health', 'arguments' => []]));
        self::assertArrayHasKey('skills_version', $first);
        self::assertArrayHasKey('skills_changed', $first, 'a token that has seen nothing is told the list on its first call');

        $second = $this->toolPayload($this->rpc($this->kb->a->agentBearer, 'tools/call', ['name' => 'health', 'arguments' => []]));
        self::assertArrayNotHasKey('skills_changed', $second);
        self::assertSame($first['skills_version'], $second['skills_version']);

        $this->in($this->kb->a);
        self::getContainer()->get(SkillServing::class)->update($note->getId(), ['enabled' => false]);
        $third = $this->toolPayload($this->rpc($this->kb->a->agentBearer, 'tools/call', ['name' => 'health', 'arguments' => []]));
        self::assertNotSame($first['skills_version'], $third['skills_version']);
        self::assertStringNotContainsString('house-style', $third['skills_changed']);

        $fourth = $this->toolPayload($this->rpc($this->kb->a->agentBearer, 'tools/call', ['name' => 'health', 'arguments' => []]));
        self::assertArrayNotHasKey('skills_changed', $fourth);
    }

    public function testTheServedListIsComputedOncePerRequest(): void
    {
        $this->kb->a->note('House style', 'Cite the source.', ['skill']);
        $selects = 0;
        \App\Tests\Support\SqlObservation::during($this->em->getConnection(), function (string $sql) use (&$selects): void {
            if (str_starts_with($sql, 'SELECT') && str_contains($sql, 'FROM skill_settings')) {
                ++$selects;
            }
        }, fn () => $this->rpc($this->kb->a->agentBearer, 'tools/call', ['name' => 'health', 'arguments' => []]));
        self::assertSame(2, $selects);
    }

    public function testTheListIsPerConnection(): void
    {
        $note = $this->kb->a->note('Codex only', 'For Codex.', ['skill']);
        $this->in($this->kb->a);
        self::getContainer()->get(SkillServing::class)->update($note->getId(), ['grants' => [$this->kb->a->curatorTokenId]]);
        $agent = $this->toolPayload($this->rpc($this->kb->a->agentBearer, 'tools/call', ['name' => 'health', 'arguments' => []]));
        $curator = $this->toolPayload($this->rpc($this->kb->a->curatorBearer, 'tools/call', ['name' => 'health', 'arguments' => []]));
        self::assertNotSame($agent['skills_version'], $curator['skills_version']);
        self::assertStringContainsString('codex-only', $curator['skills_changed']);
        self::assertStringNotContainsString('codex-only', $agent['skills_changed']);
    }
    public function testFailedLoadAnnouncesAPauseAndThenStaysQuiet(): void
    {
        $note = $this->kb->a->note('House style', 'Steps.', ['skill']);
        $first = $this->toolPayload($this->rpc($this->kb->a->agentBearer, 'tools/call', ['name' => 'health']));
        $this->in($this->kb->a);
        self::getContainer()->get(SkillServing::class)->update($note->getId(), ['enabled' => false]);
        $failed = $this->rpc($this->kb->a->agentBearer, 'tools/call', ['name' => 'get_skill', 'arguments' => ['slug' => 'house-style']]);
        self::assertTrue($failed['result']['isError']);
        $payload = $this->toolPayload($failed);
        self::assertArrayHasKey('skills_version', $payload);
        self::assertNotSame($first['skills_version'], $payload['skills_version']);
        self::assertStringNotContainsString('house-style', $payload['skills_changed']);
        $next = $this->toolPayload($this->rpc($this->kb->a->agentBearer, 'tools/call', ['name' => 'get_skill', 'arguments' => ['slug' => 'house-style']]));
        self::assertArrayHasKey('skills_version', $next);
        self::assertArrayNotHasKey('skills_changed', $next);
    }

    public function testDescriptionChangesRefreshTheAdvertisedMatchingInstructions(): void
    {
        $note = $this->kb->a->note('House style', 'Steps.', ['skill'], 'Use for writing notes.');
        $first = $this->toolPayload($this->rpc($this->kb->a->agentBearer, 'tools/call', ['name' => 'health']));
        $this->in($this->kb->a);
        $this->em->getConnection()->executeStatement('UPDATE notes SET summary = :s WHERE id = :n', ['s' => 'Use for reviewing release notes.', 'n' => $note->getId()]);
        $next = $this->toolPayload($this->rpc($this->kb->a->agentBearer, 'tools/call', ['name' => 'health']));
        self::assertNotSame($first['skills_version'], $next['skills_version']);
        self::assertStringContainsString('Use for reviewing release notes.', $next['skills_changed']);
    }

}
