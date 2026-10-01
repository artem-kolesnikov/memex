<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Tag;
use App\Service\SpendAllowance;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Telling CURATION apart from everything else an agent does, and naming every
 * note a row touched (operator, 2026-08-29).
 *
 * The distinction is not decorative. One curator connection does both: it runs
 * the charter overnight and it is driven by hand during the day, minutes
 * apart, writing rows the server cannot tell apart at write time. Nothing
 * records a charter load, so the only honest marker is the pass's own
 * declaration — a run-summary that states when it began draws a window, and
 * the rows inside it are that pass's work.
 *
 * The property that matters most here is the NEGATIVE one: a pass that did NOT
 * state its start stamps nothing but itself. Its fallback window is everything
 * the connection wrote since its previous pass, which on the operator's own
 * vault held sixteen edits and eight new notes made by hand during the day
 * against a pass that applied nothing and said so. Under-reporting that pass
 * is a gap; filing the operator's afternoon under curation is a lie.
 */
final class ActivityCurationScopeTest extends ApiTestCase
{
    /** @return array<string, mixed> the tool's own payload, decoded */
    private function payload(string $tool, string $bearer, array $arguments = []): array
    {
        $this->request('POST', '/mcp', $bearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $result = $this->jsonResponse()['result'];
        self::assertNotTrue($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));

        return json_decode($result['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);
    }

    private function backdate(int $tokenId, string $interval): void
    {
        $this->in($this->kb->a);
        $this->em->getConnection()->executeStatement(
            'UPDATE curator_log SET created_at = datetime(created_at, :shift) WHERE token_id = :t',
            ['shift' => '-'.$interval, 't' => $tokenId],
        );
    }

    /**
     * A streamed download's body. The client reads the stream after the
     * request has ended and left the vault, so the owner's vault is entered
     * again for it, as it still is on the box while a response is sent.
     */
    private function download(string $uri): string
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $reenter = fn () => $this->in($this->kb->a);
        $dispatcher->addListener(KernelEvents::TERMINATE, $reenter, -4096);
        try {
            $this->client->request('GET', $uri);
        } finally {
            $dispatcher->removeListener(KernelEvents::TERMINATE, $reenter);
        }

        return (string) $this->client->getInternalResponse()->getContent();
    }

    /** An applied curator edit, which writes one `edit` row. */
    private function editNote(string $title, string $replace): int
    {
        $note = $this->kb->a->note($title, 'Body.', ['reference'], summary: 'A description.');
        $this->payload('propose', $this->kb->a->curatorBearer, [
            'note_id' => $note->getId(),
            'patch' => [['find' => 'Body.', 'replace' => $replace]],
        ]);

        return (int) $note->getId();
    }

    /** @return array<string, mixed> */
    private function journal(string $queryString = ''): array
    {
        $this->sessionRequest('GET', '/api/curator-log'.($queryString === '' ? '' : '?'.$queryString));
        self::assertSame(200, $this->httpStatus(), $this->body());

        return $this->jsonResponse();
    }

    /** @return array<string, mixed>|null */
    private function rowFor(array $journal, string $action): ?array
    {
        foreach ($journal['entries'] as $entry) {
            if ($entry['action'] === $action) {
                return $entry;
            }
        }

        return null;
    }

    // ---- curation against agent work ---------------------------------------

    public function testAStatedRunClaimsTheRowsInsideItAndNotTheDaysWorkBeforeIt(): void
    {
        $this->editNote('Yesterday', 'Edited by hand.');
        // The hand-driven work is an hour old; the pass below is now. Without
        // this they share a second and the run cannot be told from the day.
        $this->backdate($this->kb->a->curatorTokenId, '1 hour');

        $this->editNote('During the pass', 'Edited by the pass.');
        $summary = $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary',
            'description' => 'One edit.',
            'started_at' => (new \DateTimeImmutable('-5 minutes', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ]);

        $this->loginAs($this->kb->a);
        $curation = $this->journal('curation=1');

        $descriptions = array_column($curation['entries'], 'description');
        self::assertNotEmpty(array_filter(
            $descriptions,
            static fn (string $d) => str_contains($d, 'During the pass'),
        ), 'the row the pass wrote is curation');
        self::assertEmpty(array_filter(
            $descriptions,
            static fn (string $d) => str_contains($d, 'Yesterday'),
        ), 'the hand-driven edit an hour earlier is not');

        foreach ($curation['entries'] as $entry) {
            self::assertSame($summary['id'], $entry['curation_run'], 'every row names the pass that claimed it');
        }
    }

    public function testTheUnfilteredJournalStillHoldsBothKinds(): void
    {
        $this->editNote('Yesterday', 'Edited by hand.');
        $this->backdate($this->kb->a->curatorTokenId, '1 hour');
        $this->editNote('During the pass', 'Edited by the pass.');
        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary',
            'description' => 'One edit.',
            'started_at' => (new \DateTimeImmutable('-5 minutes', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ]);

        $this->loginAs($this->kb->a);
        $all = $this->journal();

        $descriptions = array_column($all['entries'], 'description');
        self::assertNotEmpty(array_filter($descriptions, static fn (string $d) => str_contains($d, 'Yesterday')));
        self::assertNotEmpty(array_filter($descriptions, static fn (string $d) => str_contains($d, 'During the pass')));
        self::assertGreaterThan(
            count($this->journal('curation=1')['entries']),
            count($all['entries']),
            'the ledger is unified; the filter is a view of it',
        );
    }

    /**
     * A pass that did not say when it began claims only itself.
     *
     * This is the whole judgment of the feature. The fallback window is
     * everything the connection has written since its PREVIOUS pass, so the
     * shape that matters is a second summary with hand-driven work sitting
     * between the two: a run that trusted its fallback window would file that
     * work as curation, which on the operator's real vault meant sixteen
     * daytime edits attributed to a pass that had applied none.
     */
    public function testAnUnstatedRunClaimsOnlyItsOwnSummary(): void
    {
        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary', 'description' => 'An earlier pass.',
        ]);
        // Everything so far moves into the past, so the edit below lands
        // between the two summaries — inside the fallback window, outside any
        // window the second pass actually established.
        $this->backdate($this->kb->a->curatorTokenId, '2 hours');
        $this->editNote('By hand', 'Edited by hand.');

        $summary = $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary', 'description' => 'Nothing to report.',
        ]);

        $this->loginAs($this->kb->a);
        $curation = $this->journal('curation=1');

        self::assertSame(
            ['run-summary'],
            array_values(array_unique(array_column($curation['entries'], 'action'))),
            'an unbounded window claims no writes',
        );
        self::assertContains(
            $summary['id'],
            array_column($curation['entries'], 'curation_run'),
            'the summary is still its own pass',
        );
    }

    /** The notes a pass read and left alone belong to it whether it stated a start or not. */
    public function testExaminedRowsBelongToTheirPass(): void
    {
        $noteId = $this->editNote('Read and passed over', 'Edited.');
        $summary = $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary', 'description' => 'Read one.', 'examined' => [$noteId],
        ]);

        $this->loginAs($this->kb->a);
        $examined = $this->rowFor($this->journal('curation=1&action=examined'), 'examined');

        self::assertNotNull($examined);
        self::assertSame($summary['id'], $examined['curation_run']);
    }

    /**
     * A pass that omitted its boundary is TOLD what that cost it.
     *
     * Every run-summary in the operator's vault when this shipped had omitted
     * `started_at`, so the honest answer to "show me curation" was 76 rows out
     * of 678. A silent gap in a filter reads as "the passes did nothing"; the
     * work is to improve the plug, not to guess on the pass's behalf.
     */
    public function testAPassIsToldWhenItsWritesWereNotRecordedAsCuration(): void
    {
        $this->editNote('Unattributed', 'Edited.');
        $result = $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary', 'description' => 'No boundary sent.',
        ]);

        self::assertArrayHasKey('note', $result);
        self::assertStringContainsString('started_at', $result['note']);
        self::assertSame(0, $result['curation_rows']);
    }

    public function testAPassThatSendsItsBoundaryIsToldWhatWasStamped(): void
    {
        $this->editNote('Attributed', 'Edited.');
        $this->backdate($this->kb->a->curatorTokenId, '1 minute');
        $result = $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary',
            'description' => 'One edit.',
            'started_at' => (new \DateTimeImmutable('-5 minutes', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ]);

        self::assertArrayNotHasKey('note', $result);
        self::assertGreaterThan(0, $result['curation_rows']);
    }

    // ---- which notes a row touched -----------------------------------------

    public function testATagMergeNamesEveryNoteItRewrote(): void
    {
        $first = $this->kb->a->note('First', 'Body.', ['draft']);
        $second = $this->kb->a->note('Second', 'Body.', ['draft']);
        $keeper = $this->kb->a->note('Third', 'Body.', ['reference']);
        $this->em->flush();

        $draft = $this->em->getRepository(Tag::class)->findOneBy(['name' => 'draft']);
        $reference = $this->em->getRepository(Tag::class)->findOneBy(['name' => 'reference']);
        self::assertNotNull($draft);
        self::assertNotNull($reference);

        $this->loginAs($this->kb->a);
        $this->sessionRequest('DELETE', '/api/tags/'.$draft->getId().'?merge_into='.$reference->getId());
        self::assertSame(200, $this->httpStatus(), $this->body());

        $row = $this->rowFor($this->journal('action=tag-merged'), 'tag-merged');
        self::assertNotNull($row);

        $ids = array_column($row['affected'], 'id');
        sort($ids);
        self::assertSame([(int) $first->getId(), (int) $second->getId()], $ids);
        self::assertNotContains((int) $keeper->getId(), $ids, 'a note that never carried the tag was not rewritten');
        self::assertSame(2, $row['affected_total']);
        self::assertSame(
            ['First', 'Second'],
            array_column($row['affected'], 'title'),
            'each id arrives with the title it needs to be a link',
        );
    }

    public function testAOneNoteRowStillNamesItsNote(): void
    {
        $noteId = $this->editNote('Edited', 'Edited body.');

        $this->loginAs($this->kb->a);
        $row = $this->rowFor($this->journal('action=edit'), 'edit');

        self::assertNotNull($row);
        self::assertSame([$noteId], array_column($row['affected'], 'id'));
    }

    /**
     * A row outlives what it references — `note_id` is ON DELETE SET NULL and
     * the id list is a copy — so a link to a note that no longer exists would
     * be a 404 offered as a fact.
     */
    public function testANoteThatIsGoneIsNotOfferedAsALink(): void
    {
        $first = $this->kb->a->note('Doomed', 'Body.', ['draft']);
        $survivor = $this->kb->a->note('Survivor', 'Body.', ['draft']);
        $this->em->flush();

        $draft = $this->em->getRepository(Tag::class)->findOneBy(['name' => 'draft']);
        $this->loginAs($this->kb->a);
        $this->sessionRequest('DELETE', '/api/tags/'.$draft->getId());
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('DELETE', '/api/notes/'.$first->getId());
        self::assertSame(200, $this->httpStatus(), $this->body());

        $row = $this->rowFor($this->journal('action=tag-removed'), 'tag-removed');
        self::assertNotNull($row);
        self::assertSame([(int) $survivor->getId()], array_column($row['affected'], 'id'));
        self::assertSame(2, $row['affected_total'], 'the row still records what it touched');
    }

    /** The file is scoped the way the screen is: an id is thinner than a title, not free. */
    public function testTheExportDoesNotPrintAnotherTeamsNoteIds(): void
    {
        // Past the journal row's own number, which the file prints as `#1`.
        $this->kb->b->note('Their filler', 'Body.');
        $this->kb->b->note('Their other filler', 'Body.');
        $theirs = $this->kb->b->note('Their note', 'Body.');

        $this->in($this->kb->a);
        self::assertNull($this->findNumbered((int) $theirs->getId()), 'Precondition: A holds no note by that id');
        $this->em->getConnection()->executeStatement(
            'INSERT INTO curator_log (token_name, action, description, affected_note_ids, created_at, is_precedent)
             VALUES (:by, :action, :description, :ids, :now, 0)',
            [
                'by' => 'operator',
                'action' => 'tag-removed',
                'description' => 'A row naming a note across the boundary.',
                'ids' => json_encode([(int) $theirs->getId()], JSON_THROW_ON_ERROR),
                'now' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            ],
        );

        $this->loginAs($this->kb->a);
        $body = $this->download('/api/curator-log/export?action=tag-removed&format=md');

        self::assertStringContainsString('A row naming a note across the boundary', $body);
        self::assertStringNotContainsString('#'.$theirs->getId(), $body);
    }

    // ---- taking it away ----------------------------------------------------

    public function testTheJournalExportsAsMarkdownAndCsv(): void
    {
        $this->editNote('Exported', 'Edited body.');
        $this->loginAs($this->kb->a);

        foreach (['md' => 'text/markdown', 'csv' => 'text/csv'] as $format => $type) {
            $this->download('/api/curator-log/export?format='.$format);
            $response = $this->client->getResponse();
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString($type, (string) $response->headers->get('Content-Type'));
            self::assertStringContainsString(
                'attachment',
                (string) $response->headers->get('Content-Disposition'),
            );

            $body = $this->client->getInternalResponse()->getContent();
            self::assertStringContainsString('Exported', $body);
        }
    }

    /**
     * The range the download dialog sends, honoured by the file and by the
     * count the dialog shows before you commit to it.
     *
     * INSTANTS, not calendar days. `created_at` is UTC and the journal renders
     * in the reader's zone, so a day resolved server-side cut the range in the
     * wrong place for anybody east or west of UTC. The dialog turns the two
     * days it was given into the two instants that bound them, with `to`
     * exclusive — the start of the day after.
     */
    public function testTheDateRangeBoundsTheJournalAndItsExport(): void
    {
        $this->editNote('Long ago', 'Edited.');
        $this->in($this->kb->a);
        $this->em->getConnection()->executeStatement(
            "UPDATE curator_log SET created_at = '2026-01-15 12:00:00'",
        );
        $this->editNote('Recent', 'Edited.');
        $this->in($this->kb->a);
        $this->em->getConnection()->executeStatement(
            "UPDATE curator_log SET created_at = '2026-03-20 23:59:59'
             WHERE description LIKE '%Recent%'",
        );

        $this->loginAs($this->kb->a);
        $range = 'from=2026-03-01T00%3A00%3A00Z&to=2026-03-21T00%3A00%3A00Z';

        $descriptions = array_column($this->journal($range)['entries'], 'description');
        self::assertNotEmpty(array_filter($descriptions, static fn (string $d) => str_contains($d, 'Recent')),
            'the last second before the exclusive bound is inside the range');
        self::assertEmpty(array_filter($descriptions, static fn (string $d) => str_contains($d, 'Long ago')));

        // One second later is the bound itself, and the bound is exclusive.
        $shorter = $this->journal('from=2026-03-01T00%3A00%3A00Z&to=2026-03-20T23%3A59%3A59Z');
        self::assertEmpty(array_filter(
            array_column($shorter['entries'], 'description'),
            static fn (string $d) => str_contains($d, 'Recent'),
        ));

        // The count the dialog reads, from the same filters and without the
        // rows or the filter menus.
        $this->sessionRequest('GET', '/api/curator-log?'.$range.'&count_only=1');
        self::assertSame(200, $this->httpStatus(), $this->body());
        $counted = $this->jsonResponse();
        self::assertSame(count($descriptions), $counted['total']);
        self::assertArrayNotHasKey('entries', $counted);

        $body = $this->download('/api/curator-log/export?'.$range.'&format=md');
        self::assertStringContainsString('Recent', $body);
        self::assertStringNotContainsString('Long ago', $body);
    }

    /**
     * An offset is honoured, which is the whole reason the boundary is an
     * instant: the same wall-clock day means different instants in different
     * places, and the browser is the only thing that knows which.
     */
    public function testTheBoundaryIsReadInTheZoneItWasSentWith(): void
    {
        $this->editNote('Late on the 25th in New York', 'Edited.');
        $this->in($this->kb->a);
        $this->em->getConnection()->executeStatement(
            "UPDATE curator_log SET created_at = '2026-03-26 02:00:00'",
        );

        $this->loginAs($this->kb->a);

        // 02:00 UTC on the 26th is 22:00 on the 25th in New York, so a New
        // Yorker asking for "up to the end of the 25th" must see it.
        $ny = $this->journal('to=2026-03-26T04%3A00%3A00-04%3A00');
        self::assertNotEmpty($ny['entries'], 'the row is before the end of the 25th, New York time');

        // The same wall-clock boundary read as UTC excludes it.
        $utc = $this->journal('to=2026-03-26T00%3A00%3A00Z');
        self::assertEmpty($utc['entries']);
    }

    /**
     * A filter that silently does nothing is how somebody downloads the wrong
     * range and never finds out. `new DateTimeImmutable()` would have taken
     * "yesterday" — the same laxity Codex found in `started_at` — and
     * `createFromFormat` takes a half-padded `2026-8-27`.
     */
    public function testARangeThatIsNotAnInstantIsRefused(): void
    {
        $this->loginAs($this->kb->a);

        $refused = [
            '/api/curator-log?from=yesterday',
            '/api/curator-log?from=2026-08-26',
            '/api/curator-log?from=2026-8-26T00%3A00%3A00Z',
            '/api/curator-log?from=2026-02-31T00%3A00%3A00Z',
            // No zone at all. Accepted, it would be read in whatever timezone
            // the box happens to run in — the one reading of a boundary that
            // is neither the sender's nor the column's.
            '/api/curator-log?from=2026-08-26T00%3A00%3A00',
            '/api/curator-log/export?to=next+tuesday',
        ];
        foreach ($refused as $uri) {
            $this->sessionRequest('GET', $uri);
            self::assertSame(400, $this->httpStatus(), $uri.' — '.$this->body());
        }

        // An inverted range is a mistake, not an empty answer: a table saying
        // "nothing matches" is how somebody concludes the journal is empty.
        $this->sessionRequest('GET', '/api/curator-log?from=2026-08-28T00%3A00%3A00Z&to=2026-08-27T00%3A00%3A00Z');
        self::assertSame(400, $this->httpStatus(), $this->body());
        self::assertStringContainsString('from is after to', $this->body());
    }

    /**
     * The export carries every note a row touched, where the table cuts at
     * AFFECTED_MAX. A file has room, and one that drops the ninety-first
     * without saying so is the silent truncation this endpoint replaces.
     */
    public function testTheExportDoesNotCutARowsNoteList(): void
    {
        // 120 notes may be more than an account may embed in an hour, and
        // this test is about the export rather than the ceiling.
        $this->kb->a->account()->setTier(SpendAllowance::UNLIMITED);
        static::getContainer()->get('doctrine.orm.directory_entity_manager')->flush();

        $ids = [];
        for ($i = 0; $i < 120; ++$i) {
            $note = $this->kb->a->note('Bulk note '.$i, 'Body.', ['draft']);
            $ids[] = $note;
        }
        $this->em->flush();

        $draft = $this->em->getRepository(Tag::class)->findOneBy(['name' => 'draft']);
        $this->loginAs($this->kb->a);
        $this->sessionRequest('DELETE', '/api/tags/'.$draft->getId());
        self::assertSame(200, $this->httpStatus(), $this->body());

        $row = $this->rowFor($this->journal('action=tag-removed'), 'tag-removed');
        self::assertCount(100, $row['affected'], 'the table cuts at AFFECTED_MAX');
        self::assertSame(120, $row['affected_total'], 'and says so');

        $body = $this->download('/api/curator-log/export?action=tag-removed&format=md');
        $line = '';
        foreach (explode("\n", $body) as $candidate) {
            if (str_starts_with($candidate, 'Notes: ')) {
                $line = $candidate;
                break;
            }
        }
        self::assertSame(120, substr_count($line, '#'), 'the file carries all of them');
    }

    public function testAnAgentTokenIsRefusedTheExport(): void
    {
        $this->request('GET', '/api/curator-log/export', $this->kb->a->agentBearer);
        self::assertSame(403, $this->httpStatus(), $this->body());
    }
}
