<?php

declare(strict_types=1);

namespace App\Tests\Database;

final class SkillPageApiTest extends ApiTestCase
{
    public function testThePageListsEveryKindWithItsState(): void
    {
        $note = $this->kb->a->note('House style', 'Cite the source.', ['skill'], 'How notes are written here, and when to reread this.');
        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/skills');
        self::assertSame(200, $this->httpStatus());
        $body = $this->jsonResponse();

        $bySlug = array_column($body['skills'], null, 'slug');
        self::assertSame('served', $bySlug['house-style']['status']);
        self::assertSame($note->getId(), $bySlug['house-style']['note_id']);
        self::assertSame([], $bySlug['house-style']['grants']);
        self::assertSame(0, $bySlug['house-style']['usage']['total_30d']);
        self::assertSame('built_in', $bySlug['memex-docs']['status']);
        self::assertSame('offered', $bySlug['skill-handoff']['status']);
        self::assertSame('offered', $bySlug['skill-ingest']['status']);
        self::assertSame('built_in', $bySlug['memex-writing']['status']);
        self::assertGreaterThan(0, $bySlug['memex-docs']['size_tokens']);
        self::assertCount(2, $body['connections']);
    }

    public function testPatchChangesTheRecordAndAnswersTheRow(): void
    {
        $note = $this->kb->a->note('House style', 'Cite the source.', ['skill']);
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PATCH', '/api/skills/'.$note->getId(), ['enabled' => false, 'command' => false, 'slug' => 'style', 'grants' => [$this->kb->a->agentTokenId]]);
        self::assertSame(200, $this->httpStatus());
        $row = $this->jsonResponse()['skill'];
        self::assertSame('paused', $row['status']);
        self::assertFalse($row['command']);
        self::assertSame('style', $row['slug']);
        self::assertSame([$this->kb->a->agentTokenId], $row['grants']);
    }

    public function testPatchRefusals(): void
    {
        $foreign = $this->kb->b->connection('agent-b-3', 'mxt_agent_b_3');
        $note = $this->kb->a->note('House style', 'Cite.', ['skill']);
        $this->kb->a->note('Other', 'Other.', ['skill']);
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PATCH', '/api/skills/'.$note->getId(), ['slug' => 'Bad Slug']);
        self::assertSame(400, $this->httpStatus());
        $this->sessionRequest('PATCH', '/api/skills/'.$note->getId(), ['slug' => 'other']);
        self::assertSame(409, $this->httpStatus());
        $this->sessionRequest('PATCH', '/api/skills/'.$note->getId(), ['slug' => 'memex-docs']);
        self::assertSame(409, $this->httpStatus());
        $this->sessionRequest('PATCH', '/api/skills/'.$note->getId(), ['slug' => 'memex-guide']);
        self::assertSame(409, $this->httpStatus(), 'the docs\' earlier name is reserved too');
        $this->sessionRequest('PATCH', '/api/skills/'.$note->getId(), ['grants' => [$foreign]]);
        self::assertSame(404, $this->httpStatus());
        $this->sessionRequest('PATCH', '/api/skills/999999', ['enabled' => false]);
        self::assertSame(404, $this->httpStatus());
    }

    public function testAnotherTenantAndABearerTokenAreRefused(): void
    {
        $note = $this->kb->a->note('House style', 'Cite.', ['skill']);
        $this->loginAs($this->kb->b);
        $this->sessionRequest('PATCH', '/api/skills/'.$note->getId(), ['enabled' => false]);
        self::assertSame(404, $this->httpStatus());

        $this->request('PATCH', '/api/skills/'.$note->getId(), $this->kb->a->agentBearer, ['enabled' => false]);
        self::assertSame(403, $this->httpStatus());
        $this->request('GET', '/api/skills', $this->kb->a->agentBearer);
        self::assertSame(403, $this->httpStatus());
    }
}
