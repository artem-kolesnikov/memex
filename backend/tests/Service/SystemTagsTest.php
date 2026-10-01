<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\McpServer;
use App\Service\SkillLibrary;
use App\Service\SystemTags;
use PHPUnit\Framework\TestCase;

/**
 * The two tags memex reads, and the thing that keeps them honest.
 *
 * The registry is only worth having if its names are the names the rest of the
 * code actually branches on. A `SystemTags::SKILL` of `'skills'` would lock a
 * word nothing reads while leaving the real one deletable — a lock that looks
 * present and is not, which is worse than no lock. So the assertions here are
 * mostly about AGREEMENT with the two places that consume the tags, not about
 * the registry's own shape.
 */
final class SystemTagsTest extends TestCase
{
    public function testTheRegistryNamesTheTagTheSkillLibraryQueries(): void
    {
        // SkillServing::skillNotes() selects on this bound parameter; if the
        // two ever drifted, the tag screen would be protecting a word no
        // skill is served by.
        self::assertStringContainsString(
            "'tag' => SystemTags::SKILL",
            file_get_contents(__DIR__.'/../../src/Service/SkillServing.php') ?: '',
            'SkillServing must query the same word SystemTags holds',
        );
        self::assertTrue(SystemTags::isSystem(SystemTags::SKILL));
    }

    public function testTheRegistryNamesTheTagThatTriggersTheWriteBackNotice(): void
    {
        // Straight through the real function rather than through a constant:
        // this asserts the BEHAVIOUR the lock exists to protect.
        $noticed = McpServer::liveStateNotice([
            'id' => 7,
            'tags' => [['id' => 1, 'name' => SystemTags::LIVE_STATE]],
        ]);

        self::assertArrayHasKey('live_state_notice', $noticed);
        self::assertTrue(SystemTags::isSystem(SystemTags::LIVE_STATE));
    }

    public function testTheRegistryNamesTheTagThatTriggersTheProfileNotice(): void
    {
        $noticed = McpServer::retrievalNotice([
            'id' => 9,
            'tags' => [['id' => 1, 'name' => SystemTags::USER_PROFILE]],
        ]);

        self::assertArrayHasKey('profile_notice', $noticed);
        self::assertTrue(SystemTags::isSystem(SystemTags::USER_PROFILE));
        // UserProfiles selects on the same word in SQL.
        self::assertStringContainsString(
            'SystemTags::USER_PROFILE',
            file_get_contents(__DIR__.'/../../src/Service/UserProfiles.php') ?: '',
        );
    }

    public function testAnOrdinaryWordIsNotHeld(): void
    {
        self::assertFalse(SystemTags::isSystem('recipes'));
        self::assertNull(SystemTags::reason('recipes'));
        // Near-misses matter: the lock is on the exact word the code reads.
        self::assertFalse(SystemTags::isSystem('skills'));
        self::assertFalse(SystemTags::isSystem('live_state'));
        self::assertFalse(SystemTags::isSystem('profile'));
        self::assertFalse(SystemTags::isSystem('user_profile'));
    }

    public function testMatchingIgnoresCaseAndSurroundingSpace(): void
    {
        // Tags are lowercased on write (NoteWriter::resolveTags), but the
        // callers here include a URL query and a template, so the registry
        // normalises rather than trusting its input.
        self::assertTrue(SystemTags::isSystem(' Skill '));
        self::assertTrue(SystemTags::isSystem('LIVE-STATE'));
    }

    public function testEveryHeldNameSaysWhatBreaksWithoutIt(): void
    {
        // The reason is what the owner reads instead of a × . An empty or
        // absent one turns the lock into an unexplained refusal, which is the
        // failure mode this whole feature was meant to avoid.
        foreach (SystemTags::all() as $entry) {
            self::assertNotSame('', trim($entry['reason']), $entry['name'].' must explain itself');
            self::assertSame($entry['reason'], SystemTags::reason($entry['name']));
        }
        self::assertSame([SystemTags::SKILL, SystemTags::LIVE_STATE, SystemTags::USER_PROFILE], SystemTags::names());
    }

    public function testSlugifyIsNotWhatMakesASkill(): void
    {
        // Guards a misreading rather than a bug: SkillLibrary's fallback slug
        // is also the string 'skill', and it is unrelated to the tag. Someone
        // renaming one should not think they are renaming the other.
        self::assertSame('skill', SkillLibrary::slugify('!!!'));
    }
}
