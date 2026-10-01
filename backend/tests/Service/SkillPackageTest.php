<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SkillPackage;
use PHPUnit\Framework\TestCase;

final class SkillPackageTest extends TestCase
{
    public function testRenderEmitsValidAgentSkillsFrontmatter(): void
    {
        $md = (new SkillPackage())->render([
            'id' => 12, 'slug' => 'house-style', 'title' => 'House style',
            'description' => 'How notes: "are" written', 'body' => "Steps.\n", 'updated_at' => '2026-09-22 10:00:00',
        ], 'abc');
        self::assertStringStartsWith("---\nname: \"house-style\"\ndescription: \"How notes: \\\"are\\\" written\"\nmetadata:\n  memex_note_id: \"12\"\n  memex_updated_at: \"2026-09-22 10:00:00\"\n---\n\nSteps.\n", $md);
        $parsed = (new SkillPackage())->parse($md, 'fallback');
        self::assertSame('house-style', $parsed['name']);
        self::assertSame('How notes: "are" written', $parsed['description']);
        self::assertSame("Steps.\n", $parsed['body']);
    }

    public function testANumericSlugRendersAsAQuotedStringAndParsesBackToItself(): void
    {
        $md = (new SkillPackage())->render([
            'id' => 3, 'slug' => '2026', 'title' => '2026', 'description' => 'A year-named skill.', 'body' => "Steps.\n", 'updated_at' => 'now',
        ], 'abc');
        self::assertStringContainsString("name: \"2026\"\n", $md);
        $parsed = (new SkillPackage())->parse($md, 'fallback');
        self::assertSame('2026', $parsed['name']);
    }

    public function testAShippedSkillCarriesItsVersion(): void
    {
        $md = (new SkillPackage())->render(['id' => null, 'slug' => 'memex-recall', 'title' => 'x', 'description' => 'y', 'body' => 'z', 'updated_at' => 'now'], 'v9');
        self::assertStringContainsString("metadata:\n  memex_shipped: \"v9\"", $md);
    }

    public function testParseFallsBackWhenTheNameIsMissing(): void
    {
        $noFront = (new SkillPackage())->parse("Just a body.\n", 'weekly-review');
        self::assertSame('weekly-review', $noFront['name']);
        self::assertSame('Weekly review', $noFront['title']);
        self::assertSame("Just a body.\n", $noFront['body']);

        $this->expectException(\InvalidArgumentException::class);
        (new SkillPackage())->parse("---\nname: Bad Name\ntitle: Weekly review\ndescription: d\n---\nBody.\n", 'x');
    }
}
