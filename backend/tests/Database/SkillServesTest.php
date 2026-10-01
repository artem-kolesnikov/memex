<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\SkillServe;

final class SkillServesTest extends ApiTestCase
{
    public function testAServeRowCarriesTheSlugAndTheTeam(): void
    {
        $this->em->persist(new SkillServe('house-style', $this->kb->a->agentToken(), null, SkillServe::PATH_TOOL));
        $this->em->flush();

        $row = $this->em->getConnection()->fetchAssociative('SELECT slug, token_id, path, preset_version FROM skill_serves');
        self::assertSame('house-style', $row['slug']);
        self::assertSame($this->kb->a->agentTokenId, (int) $row['token_id']);
        self::assertSame('tool', $row['path']);
        self::assertSame(0, (int) $row['preset_version']);
    }

    public function testTheSettingsAndGrantsTablesExistWithTheirKeys(): void
    {
        $note = $this->kb->a->note('House style', 'Cite the source.', ['skill']);
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'INSERT INTO skill_settings (note_id, slug) VALUES (:n, :s)',
            ['n' => $note->getId(), 's' => 'house-style'],
        );
        $conn->executeStatement(
            'INSERT INTO skill_grants (note_id, token_id) VALUES (:n, :k)',
            ['n' => $note->getId(), 'k' => $this->kb->a->agentTokenId],
        );
        self::assertSame(1, (int) $conn->fetchOne('SELECT enabled + auto + command - 2 FROM skill_settings WHERE note_id = :n', ['n' => $note->getId()]));
        $this->expectExceptionMessageMatches('/UNIQUE constraint failed: skill_settings\.slug/');
        $other = $this->kb->a->note('House style two', 'Body.', ['skill']);
        $conn->executeStatement(
            'INSERT INTO skill_settings (note_id, slug) VALUES (:n, :s)',
            ['n' => $other->getId(), 's' => 'house-style'],
        );
    }

    public function testATokenRemembersTheSkillsListItLastSaw(): void
    {
        $token = $this->kb->a->agentToken();
        self::assertNull($token->getSkillsSeen());
        $token->markSkillsSeen('abc123def456');
        $this->em->flush();
        self::assertSame('abc123def456', $this->em->getConnection()->fetchOne('SELECT skills_seen FROM api_tokens WHERE id = :id', ['id' => $token->getId()]));
    }

    public function testEveryLoadPathWritesOneRowWithTheSlug(): void
    {
        $this->kb->a->note('House style', 'Cite the source.', ['skill']);
        $calls = [
            ['method' => 'tools/call', 'params' => ['name' => 'get_skill', 'arguments' => ['slug' => 'house-style']]],
            ['method' => 'prompts/get', 'params' => ['name' => 'house-style']],
            ['method' => 'resources/read', 'params' => ['uri' => 'memex://skill/house-style']],
        ];
        foreach ($calls as $i => $call) {
            $this->request('POST', '/mcp', $this->kb->a->agentBearer, ['jsonrpc' => '2.0', 'id' => $i] + $call);
            self::assertSame(200, $this->httpStatus());
        }
        $this->in($this->kb->a);
        $paths = $this->em->getConnection()->fetchFirstColumn(
            'SELECT path FROM skill_serves WHERE slug = :s ORDER BY id', ['s' => 'house-style'],
        );
        self::assertSame(['tool', 'prompt', 'resource'], $paths);
    }

    public function testPruningKeepsNinetyDays(): void
    {
        $this->kb->a->note('House style', 'Cite the source.', ['skill']);
        $this->em->getConnection()->executeStatement(
            "INSERT INTO skill_serves (slug, path, preset_version, served_at) VALUES ('house-style', 'tool', 0, :old), ('house-style', 'tool', 0, :kept)",
            ['old' => self::daysAgo(91), 'kept' => self::daysAgo(89)],
        );
        $this->request('POST', '/mcp', $this->kb->a->agentBearer, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_skill', 'arguments' => ['slug' => 'house-style']]]);
        $this->in($this->kb->a);
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM skill_serves'));
    }

    public function testUsageReportsLastLoadedEvenWhenOutsideTheThirtyDayWindow(): void
    {
        $this->kb->a->note('House style', 'Cite the source.', ['skill']);
        $this->em->getConnection()->executeStatement(
            "INSERT INTO skill_serves (slug, token_id, path, preset_version, served_at) VALUES ('house-style', :k, 'tool', 0, :at)",
            ['k' => $this->kb->a->agentTokenId, 'at' => self::daysAgo(40)],
        );
        $usage = self::getContainer()->get(\App\Service\SkillServes::class)->usage();
        self::assertSame(0, $usage['house-style']['total_30d']);
        self::assertNotNull($usage['house-style']['last_at']);
        self::assertSame($this->kb->a->agentTokenId, $usage['house-style']['last_token_id']);
    }

    public function testUsageSummarisesPerSlugAndPerToken(): void
    {
        $this->kb->a->note('House style', 'Cite the source.', ['skill']);
        $this->request('POST', '/mcp', $this->kb->a->agentBearer, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_skill', 'arguments' => ['slug' => 'house-style']]]);
        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'get_skill', 'arguments' => ['slug' => 'house-style']]]);
        $this->in($this->kb->a);
        $usage = self::getContainer()->get(\App\Service\SkillServes::class)->usage();
        self::assertSame(2, $usage['house-style']['total_30d']);
        self::assertSame($this->kb->a->curatorTokenId, $usage['house-style']['last_token_id']);
        self::assertCount(2, $usage['house-style']['by_token']);
        $this->in($this->kb->b);
        self::assertArrayNotHasKey('house-style', self::getContainer()->get(\App\Service\SkillServes::class)->usage());
    }

    private static function daysAgo(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - $days * 86400);
    }
}
