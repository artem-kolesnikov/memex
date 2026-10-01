<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\ShippedSkills;
use App\Service\SkillLibrary;
use App\Service\UserGuide;

/**
 * The guide is one text, served live as a shipped skill. No knowledge base
 * holds a copy of it, so there is nothing to fall behind.
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

        $this->kb->a->note('memex — guide', 'A note that would slugify to the shipped name.', ['skill']);
        $slugs = array_column($library->all(), 'slug');
        self::assertContains(ShippedSkills::GUIDE.'-2', $slugs, 'The owner\'s note is served under a suffixed name');
        $found = $library->find(ShippedSkills::GUIDE);
        self::assertNotNull($found);
        self::assertNull($found['id'], 'The shipped guide still answers to its own name');
    }
}
