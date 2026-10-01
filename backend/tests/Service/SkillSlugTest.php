<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SkillSlug;
use PHPUnit\Framework\TestCase;

final class SkillSlugTest extends TestCase
{
    /** @dataProvider valid */
    public function testAcceptsTheAgentSkillsNameRule(string $slug): void
    {
        self::assertTrue(SkillSlug::isValid($slug));
    }

    public static function valid(): iterable
    {
        yield ['a'];
        yield ['pdf-processing'];
        yield ['filing-a-decision-2'];
        yield [str_repeat('a', 64)];
    }

    /** @dataProvider invalid */
    public function testRefusesWhatTheRuleRefuses(string $slug): void
    {
        self::assertFalse(SkillSlug::isValid($slug));
    }

    public static function invalid(): iterable
    {
        yield [''];
        yield ['PDF-Processing'];
        yield ["weekly-review\n"];
        yield ['-pdf'];
        yield ['pdf-'];
        yield ['pdf--processing'];
        yield ['pdf processing'];
        yield ['memex_guide'];
        yield [str_repeat('a', 65)];
    }

    public function testFromTitleMatchesTheHistoricDerivation(): void
    {
        self::assertSame('how-i-want-notes-written', SkillSlug::fromTitle('How I want notes written'));
        self::assertSame('memex-recall', SkillSlug::fromTitle('memex — recall'));
        self::assertSame('skill', SkillSlug::fromTitle('———'));
    }

    public function testFromTitleAlwaysYieldsAValidSlug(): void
    {
        $long = SkillSlug::fromTitle(str_repeat('word ', 30));
        self::assertTrue(SkillSlug::isValid($long));
        self::assertLessThanOrEqual(64, strlen($long));
    }
}
