<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\ShippedSkills;
use App\Service\SkillLibrary;
use App\Service\UserGuide;

/**
 * The docs are one text, served live as a shipped skill. No knowledge base
 * holds a copy of them, so there is nothing to fall behind.
 */
final class UserGuideTest extends ApiTestCase
{
    public function testTheShippedGuideIsServedToEveryAssistantAndCannotBeShadowed(): void
    {
        $this->in($this->kb->a);
        $library = self::getContainer()->get(SkillLibrary::class);
        self::assertContains(ShippedSkills::GUIDE, array_column($library->all(), 'slug'));
        self::assertContains(ShippedSkills::GUIDE, ShippedSkills::reservedSlugs());

        $served = $library->find(ShippedSkills::GUIDE);
        self::assertNotNull($served);
        self::assertNull($served['id']);
        $shipped = self::getContainer()->get(UserGuide::class)->shipped();
        self::assertNotNull($shipped);
        self::assertSame($shipped['body'], $served['body'], 'What an assistant loads is the shipped file, nothing appended');

        $this->kb->a->note('memex — docs', 'A note that would slugify to the shipped name.', ['skill']);
        $slugs = array_column($library->all(), 'slug');
        self::assertContains(ShippedSkills::GUIDE.'-2', $slugs, 'The owner\'s note is served under a suffixed name');
        $found = $library->find(ShippedSkills::GUIDE);
        self::assertNotNull($found);
        self::assertNull($found['id'], 'The shipped guide still answers to its own name');
    }

    /**
     * Assistants remember `memex-guide` and older welcome notes name it, so a
     * request by that name still gets the docs; it is never listed, and a note
     * titled into it cannot take it.
     */
    public function testTheEarlierNameStillLoadsTheDocsAndIsNeverListed(): void
    {
        $this->kb->a->note('memex — guide', 'A note that would slugify to the earlier name.', ['skill']);

        $loaded = $this->call('get_skill', ['slug' => UserGuide::ALIAS]);
        self::assertSame(UserGuide::SLUG, $loaded['slug']);
        $this->in($this->kb->a);
        self::assertSame(self::getContainer()->get(UserGuide::class)->shipped()['body'], $loaded['instructions']);

        $listed = array_column($this->call('list_skills')['skills'], 'slug');
        self::assertContains(UserGuide::SLUG, $listed);
        self::assertNotContains(UserGuide::ALIAS, $listed);
        self::assertContains(UserGuide::ALIAS.'-2', $listed, 'the owner\'s note is served under a suffixed name');
    }

    /** @return array<string, mixed> */
    private function call(string $tool, array $arguments = []): array
    {
        $this->request('POST', '/mcp', $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
        self::assertSame(200, $this->httpStatus(), "$tool did not answer: ".$this->body());
        $result = $this->jsonResponse()['result'];
        self::assertNotTrue($result['isError'] ?? false, "$tool refused: ".json_encode($result));

        return $result['structuredContent'] ?? json_decode((string) $result['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);
    }
}
