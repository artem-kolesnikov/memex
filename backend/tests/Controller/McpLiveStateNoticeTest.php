<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\McpServer;
use PHPUnit\Framework\TestCase;

/**
 * The `live-state` write-back notice served by `get` (2026-08-18).
 *
 * Unit tests in the strict sense — no kernel, no database, because the whole
 * decision is pure: does this note carry the tag, and does the instruction name
 * the right note. The second half is the one that matters. The reason this
 * notice is injected instead of written into note bodies is that a hand-written
 * footer names its own id and goes wrong the moment a merge gives the surviving
 * note a different one; if the interpolation here were ever wrong, the design
 * would have bought nothing.
 */
final class McpLiveStateNoticeTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function note(int $id, string ...$tags): array
    {
        return [
            'id' => $id,
            'title' => 'Hermes VM — operations runbook',
            'tags' => array_map(
                static fn (string $name, int $i) => ['id' => $i + 1, 'name' => $name],
                $tags,
                array_keys($tags)
            ),
        ];
    }

    public function testAnUntaggedNoteIsUntouched(): void
    {
        $data = self::note(72, 'runbook', 'hermes');

        self::assertSame($data, McpServer::liveStateNotice($data),
            'the notice is opt-in per note — every other note in the vault must '
            .'come back byte-identical');
    }

    public function testATaggedNoteCarriesTheNoticeNamingItsOwnId(): void
    {
        $result = McpServer::liveStateNotice(self::note(72, 'runbook', 'live-state'));

        self::assertArrayHasKey('live_state_notice', $result);
        // Interpolated, never hand-written: this is the failure mode the whole
        // server-side design exists to prevent.
        // The cheapest exit leads (2026-08-22): on the long records that carry
        // this tag, "send a full replacement" meant regenerating tens of
        // thousands of characters to fix a sentence, and an ask that large is
        // an ask that gets skipped — or done badly.
        self::assertStringContainsString('propose(note_id: 72, patch:', $result['live_state_notice']);
        self::assertStringContainsString('propose(note_id: 72, comment:', $result['live_state_notice'],
            'the cheap exit must be offered too — a rewrite-or-nothing rule gets skipped');
        self::assertStringContainsString('body_md', $result['live_state_notice'],
            'a whole-note rewrite is still an option, just not the first one');
        self::assertSame(72, $result['id'], 'the rest of the note is untouched');
    }

    public function testTheTriggerIsStatedAsChangingNotReading(): void
    {
        $result = McpServer::liveStateNotice(self::note(1, 'live-state'));

        // Read-triggered write-back would flood the review inbox, and every
        // agent-role write is held, so the cost lands entirely on the operator.
        self::assertStringContainsString('not reading the note', $result['live_state_notice']);
        self::assertStringContainsString('if you changed nothing, do nothing', $result['live_state_notice']);
    }

    public function testANoteWithNoTagsAtAllIsSafe(): void
    {
        self::assertArrayNotHasKey('live_state_notice',
            McpServer::liveStateNotice(['id' => 5, 'tags' => []]));
        self::assertArrayNotHasKey('live_state_notice',
            McpServer::liveStateNotice(['id' => 5]));
    }
}
