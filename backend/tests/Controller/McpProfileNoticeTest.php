<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\McpServer;
use App\Service\ShippedSkills;
use PHPUnit\Framework\TestCase;

/**
 * The reading instruction served with the owner's profile (2026-09-20).
 *
 * Pure like the live-state notice's tests: does the note carry the tag, does
 * the instruction name the right note and the right skill, and does a note
 * carrying BOTH held tags get one instruction rather than two. The last is
 * the point of `retrievalNotice`: the live-state trigger — "you changed the
 * system this describes" — is never true of a person, and two standing
 * instructions on one note teach an assistant to ignore both.
 */
final class McpProfileNoticeTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function note(int $id, string ...$tags): array
    {
        return [
            'id' => $id,
            'title' => 'What my assistants should know about me',
            'tags' => array_map(
                static fn (string $name, int $i) => ['id' => $i + 1, 'name' => $name],
                $tags,
                array_keys($tags)
            ),
        ];
    }

    public function testAnUntaggedNoteIsUntouched(): void
    {
        $data = self::note(12, 'person', 'profiles');

        self::assertSame($data, McpServer::retrievalNotice($data));
        self::assertSame($data, McpServer::profileNotice($data));
    }

    public function testAProfileCarriesTheNoticeNamingItsOwnIdAndTheSkill(): void
    {
        $result = McpServer::retrievalNotice(self::note(12, 'person', 'user-profile'));

        self::assertArrayHasKey('profile_notice', $result);
        self::assertStringContainsString('propose(note_id: 12, patch:', $result['profile_notice'],
            'interpolated, never hand-written — a merge gives the survivor a new id');
        self::assertStringContainsString('get_skill("'.ShippedSkills::PROFILE.'")', $result['profile_notice'],
            'the notice is the short form; the skill is where the procedure lives');
        self::assertStringContainsString('unknown, not a fact', $result['profile_notice'],
            'an unwritten section is the one thing every reader must be told about');
        self::assertStringContainsString('Reading this note is not a reason to edit it', $result['profile_notice']);
        self::assertArrayNotHasKey('live_state_notice', $result);
        self::assertSame(12, $result['id']);
    }

    public function testAProfileThatAlsoCarriesLiveStateGetsOneNoticeNotTwo(): void
    {
        $result = McpServer::retrievalNotice(self::note(12, 'live-state', 'user-profile'));

        self::assertArrayHasKey('profile_notice', $result);
        self::assertArrayNotHasKey('live_state_notice', $result,
            'the live-state trigger is about a changed SYSTEM; served beside the profile notice it '
            .'would give the same reader two triggers for the same note');
    }

    public function testALiveStateNoteWithoutTheProfileTagIsServedAsBefore(): void
    {
        $result = McpServer::retrievalNotice(self::note(72, 'runbook', 'live-state'));

        self::assertArrayHasKey('live_state_notice', $result);
        self::assertArrayNotHasKey('profile_notice', $result);
    }

    public function testANoteWithNoTagsAtAllIsSafe(): void
    {
        self::assertArrayNotHasKey('profile_notice', McpServer::retrievalNotice(['id' => 5, 'tags' => []]));
        self::assertArrayNotHasKey('profile_notice', McpServer::retrievalNotice(['id' => 5]));
    }
}
