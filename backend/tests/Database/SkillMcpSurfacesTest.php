<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\SkillServing;

final class SkillMcpSurfacesTest extends ApiTestCase
{
    private function rpc(string $bearer, string $method, array $params = []): array
    {
        $this->request('POST', '/mcp', $bearer, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        self::assertSame(200, $this->httpStatus());

        return $this->jsonResponse();
    }

    private function names(array $rpc, string $key, string $field): array
    {
        return array_column($rpc['result'][$key], $field);
    }

    public function testACommandOnlySkillIsAPromptAndNotAToolListing(): void
    {
        $note = $this->kb->a->note('Weekly review', 'Steps.', ['skill']);
        self::getContainer()->get(SkillServing::class)->update($note->getId(), ['auto' => false]);

        self::assertContains('weekly-review', $this->names($this->rpc($this->kb->a->agentBearer, 'prompts/list'), 'prompts', 'name'));
        self::assertNotContains('memex://skill/weekly-review', $this->names($this->rpc($this->kb->a->agentBearer, 'resources/list'), 'resources', 'uri'));
        $tools = $this->rpc($this->kb->a->agentBearer, 'tools/call', ['name' => 'list_skills', 'arguments' => []]);
        self::assertNotContains('weekly-review', array_column($tools['result']['structuredContent']['skills'], 'slug'));
        $got = $this->rpc($this->kb->a->agentBearer, 'tools/call', ['name' => 'get_skill', 'arguments' => ['slug' => 'weekly-review']]);
        self::assertTrue($got['result']['isError'] ?? false, 'a skill off the auto surface cannot be fetched as a tool');
        $prompt = $this->rpc($this->kb->a->agentBearer, 'prompts/get', ['name' => 'weekly-review']);
        self::assertStringContainsString('Steps.', json_encode($prompt));
    }

    public function testAnAutoOnlySkillIsNotAPrompt(): void
    {
        $note = $this->kb->a->note('Weekly review', 'Steps.', ['skill']);
        self::getContainer()->get(SkillServing::class)->update($note->getId(), ['command' => false]);

        self::assertNotContains('weekly-review', $this->names($this->rpc($this->kb->a->agentBearer, 'prompts/list'), 'prompts', 'name'));
        $prompt = $this->rpc($this->kb->a->agentBearer, 'prompts/get', ['name' => 'weekly-review']);
        self::assertArrayHasKey('error', $prompt);
        $got = $this->rpc($this->kb->a->agentBearer, 'tools/call', ['name' => 'get_skill', 'arguments' => ['slug' => 'weekly-review']]);
        self::assertStringContainsString('Steps.', json_encode($got));
    }

    public function testAGrantIsHonouredOnEverySurface(): void
    {
        $note = $this->kb->a->note('Codex only', 'For Codex.', ['skill']);
        self::getContainer()->get(SkillServing::class)->update($note->getId(), ['grants' => [$this->kb->a->curatorTokenId]]);

        foreach (['prompts/list' => ['prompts', 'name', 'codex-only'], 'resources/list' => ['resources', 'uri', 'memex://skill/codex-only']] as $method => [$key, $field, $value]) {
            self::assertNotContains($value, $this->names($this->rpc($this->kb->a->agentBearer, $method), $key, $field));
            self::assertContains($value, $this->names($this->rpc($this->kb->a->curatorBearer, $method), $key, $field));
        }
        $denied = $this->rpc($this->kb->a->agentBearer, 'tools/call', ['name' => 'get_skill', 'arguments' => ['slug' => 'codex-only']]);
        self::assertTrue($denied['result']['isError'] ?? false);
    }
}
