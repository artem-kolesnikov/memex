<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * `stale_days`: asking the queue for work nobody has read in a while.
 *
 * The narrowest item on the curation implementation list, and the reason it is
 * narrow is worth recording. The brainstorm filed "log-derived staleness" as a
 * feature to build; reading the code found it built. `last_curated_at` has come
 * off `curator_log` since 2026-08-24, the rest ladder counts `examined` rows,
 * and `changed_neighbours` already ranks a note whose neighbours moved. The
 * ranking has put longest-unseen last-but-one since then.
 *
 * What was genuinely missing is the ability to ASK. The query computes each
 * row's staleness and hands it back, and there was no way to say "only those".
 * So this is a filter over an existing answer, and these tests pin the three
 * decisions in it rather than a new producer.
 */
final class CurationStalenessFilterTest extends ApiTestCase
{
    /** @return array<string, mixed> */
    private function callTool(string $tool, array $args = []): array
    {
        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $args],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        return json_decode($this->jsonResponse()['result']['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);
    }

    /**
     * Every call passes `cooldown_days: 0`, and that is load-bearing rather
     * than tidy.
     *
     * The rest ladder is `cooldown * 2^clean_passes`, so a note one pass has
     * approved of rests fourteen days on the default. A test asserting that a
     * note read ten days ago is EXCLUDED by `stale_days: 90` would then pass
     * against a filter that does nothing at all — the note is absent because it
     * is resting, and the assertion would be measuring the ladder. Zeroing the
     * cooldown puts every examined note back in the queue so the filter is the
     * only thing that can remove one.
     *
     * @return int[]
     */
    private function candidateIds(array $args = []): array
    {
        return array_column($this->callTool('curation_candidates', $args + ['cooldown_days' => 0])['candidates'], 'note_id');
    }

    /** Record a pass over this note, then date it into the past. */
    private function examined(int $noteId, int $daysAgo): void
    {
        $this->callTool('log', [
            'action' => 'run-summary',
            'description' => 'A pass.',
            'examined' => [$noteId],
        ]);
        $this->in($this->kb->a);
        $this->em->getConnection()->executeStatement(
            'UPDATE curator_log SET created_at = :at WHERE note_id = :id',
            ['at' => (new \DateTimeImmutable("-$daysAgo days", new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), 'id' => $noteId]
        );
    }

    /** A note with no structural defect: tagged, described, embedded, linked. */
    private function soundNote(string $title): \App\Entity\Note
    {
        $partner = $title.' — companion';
        $note = $this->kb->a->note($title, "Settled. See [[$partner]].", ['reference'], summary: 'A description.');
        $this->kb->a->note($partner, "See [[$title]].", ['reference'], summary: 'A description.');

        return $note;
    }

    public function testTheFilterSeparatesLongUnreadFromRecentlyRead(): void
    {
        $old = $this->soundNote('Read a hundred days ago');
        $recent = $this->soundNote('Read ten days ago');
        $this->examined($old->getId(), 100);
        $this->examined($recent->getId(), 10);

        $unfiltered = $this->candidateIds();
        self::assertContains($old->getId(), $unfiltered);
        self::assertContains($recent->getId(), $unfiltered,
            'both must be IN the queue first, or the next assertion measures the rest ladder instead of the filter');

        $stale = $this->candidateIds(['stale_days' => 90]);
        self::assertContains($old->getId(), $stale);
        self::assertNotContains($recent->getId(), $stale);
    }

    /**
     * A never-read note passes the filter.
     *
     * Not an edge case decided by coin toss: unread is unbounded staleness, the
     * ranking already places it above longest-unseen, and a filter for old work
     * that hid the oldest work would answer the opposite of the question asked.
     */
    public function testANoteNoPassHasEverReadIsIncluded(): void
    {
        $never = $this->soundNote('Nobody has read this');

        self::assertContains($never->getId(), $this->candidateIds(['stale_days' => 90]));
    }

    /**
     * The census is NOT narrowed by the filter, exactly as it is not narrowed
     * by `reason`.
     *
     * That rule was written after a real run read a filtered census as the
     * state of the whole knowledge base and planned three passes off it
     * (CurationQueue::candidates, measured 2026-08-09). A second filter that
     * quietly narrowed the counts would reopen it.
     */
    public function testTheCensusStaysWideWhileTheListNarrows(): void
    {
        $old = $this->soundNote('Read a hundred days ago');
        // Untagged, so it is a defect the census counts — and recently read,
        // so the filter excludes it from the list.
        $recent = $this->kb->a->note('Read ten days ago', 'Body.', [], summary: 'A description.');
        $this->examined($old->getId(), 100);
        $this->examined($recent->getId(), 10);

        $wide = $this->callTool('curation_candidates', ['cooldown_days' => 0]);
        $narrow = $this->callTool('curation_candidates', ['cooldown_days' => 0, 'stale_days' => 90]);

        self::assertSame(
            $wide['reason_counts'],
            $narrow['reason_counts'],
            'the census answers "what is in front of me this run" and must not move when the caller narrows the list'
        );
        self::assertGreaterThan(0, $narrow['reason_counts']['untagged'],
            'the recently-read untagged note is still counted — if this is 0 the assertion above compares two empty censuses and proves nothing'
        );
        self::assertLessThan($wide['total'], $narrow['total'],
            'total follows the filter even though the census does not');
        self::assertNotContains($recent->getId(), array_column($narrow['candidates'], 'note_id'));
    }

    public function testTheFilterIsEchoedBackSoAShortListIsNotMistakenForAnEmptyQueue(): void
    {
        self::assertNull($this->callTool('curation_candidates')['stale_days']);
        self::assertSame(90, $this->callTool('curation_candidates', ['stale_days' => 90])['stale_days']);
    }

    public function testTheTwoFiltersCompose(): void
    {
        $oldUntagged = $this->kb->a->note('Old and untagged', 'Body.', [], summary: 'A description.');
        $oldTagged = $this->soundNote('Old and sound');
        $recentUntagged = $this->kb->a->note('Recent and untagged', 'Body.', [], summary: 'A description.');
        $this->examined($oldUntagged->getId(), 100);
        $this->examined($oldTagged->getId(), 100);
        $this->examined($recentUntagged->getId(), 10);

        $ids = $this->candidateIds(['reason' => 'untagged', 'stale_days' => 90]);

        self::assertContains($oldUntagged->getId(), $ids);
        self::assertNotContains($oldTagged->getId(), $ids, 'reason still applies');
        self::assertNotContains($recentUntagged->getId(), $ids, 'and staleness still applies');
    }

    public function testAStaleWindowOfZeroDaysIsRefusedRatherThanIgnored(): void
    {
        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'curation_candidates', 'arguments' => ['stale_days' => 0]],
        ]);

        // Clamped at the boundary, which is what `limit`, `offset` and
        // `cooldown_days` all do to an out-of-range number in this verb — the
        // schema says minimum 1, so 0 is a client ignoring it rather than a
        // meaning to honour. `CurationQueue::candidates()` throws on it
        // instead, and that guard is for the callers that do not come through
        // here: the owner's own browser will, once the Curation pane exists.
        $result = json_decode($this->jsonResponse()['result']['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(1, $result['stale_days'] ?? null,
            'a window below a day is a mistake, and the answer must say which window was actually applied');
    }
}
