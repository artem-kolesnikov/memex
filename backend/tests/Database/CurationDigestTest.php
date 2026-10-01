<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\ApiToken;

/**
 * The curation digest — what one pass DID, above the journal it is counted
 * from.
 *
 * The properties here are the ones that decide whether the digest is TRUE, and
 * every one of them was found by running it against the operator's real vault
 * rather than by reasoning about the schema:
 *
 *  1. **A run is one connection's work, not one connection's day.** The
 *     nightly pass of 2026-08-26 applied no edits and said so, while
 *     "everything this connection wrote since its last pass" held sixteen —
 *     the same connection driven by hand during the day. So a pass may state
 *     when it began, and a run that did not state one is LABELLED and is
 *     never accused of anything.
 *  2. **`examined` rows belong to their run by link, not by time.** They are
 *     written after the summary, in the same second, with a higher id: every
 *     window that ends at the summary excludes them, and every window that
 *     ends at the next one files them under the following pass.
 *  3. **The digest and the rows it links to must agree.** The count and the
 *     journal scoped to the same run are the same number, or the link is
 *     worse than no link.
 *  4. **The audit is not readable by an agent token**, here as everywhere
 *     (codex H-5). A digest is the audit with arithmetic on it.
 */
final class CurationDigestTest extends ApiTestCase
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

    /** @return array<string, mixed> the error a refused tool call reports */
    private function toolError(string $tool, string $bearer, array $arguments = []): string
    {
        $this->request('POST', '/mcp', $bearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);

        return $this->body();
    }

    /** @return list<array<string, mixed>> */
    private function digest(?string $bearer = null): array
    {
        if ($bearer === null) {
            $this->sessionRequest('GET', '/api/curation/digest');
        } else {
            $this->request('GET', '/api/curation/digest', $bearer);
        }
        self::assertSame(200, $this->httpStatus(), $this->body());

        return $this->jsonResponse()['runs'];
    }

    /** A second curator connection in A's vault, so two can run at once. */
    private function secondCuratorBearer(): string
    {
        $bearer = 'mxt_curator_second_'.bin2hex(random_bytes(4));
        $this->kb->a->connection('second-curator', $bearer, ApiToken::ROLE_CURATOR);

        return $bearer;
    }

    /**
     * Move rows written by one connection back in time.
     *
     * Reaching into `created_at` rather than sleeping: every property here is
     * about which side of a boundary a row falls on, and a test that waited
     * real seconds to find out would take minutes and still only ever exercise
     * gaps of one second. The rows themselves are written through the ordinary
     * paths — only the clock is moved.
     */
    private function backdate(int $tokenId, string $interval): void
    {
        $this->in($this->kb->a);
        $this->em->getConnection()->executeStatement(
            'UPDATE curator_log SET created_at = datetime(created_at, :shift) WHERE token_id = :t',
            ['shift' => '-'.$interval, 't' => $tokenId],
        );
    }

    private function tokenIdFor(string $name): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT id FROM api_tokens WHERE name = :n ORDER BY id DESC LIMIT 1',
            ['n' => $name],
        );
    }

    // ---- who may read it ---------------------------------------------------

    public function testAnAgentTokenIsRefusedTheDigest(): void
    {
        $this->request('GET', '/api/curation/digest', $this->kb->a->agentBearer);

        self::assertSame(403, $this->httpStatus(), $this->body());
    }

    public function testAnotherTeamsRunsAreNotVisible(): void
    {
        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary', 'description' => 'A ran a pass.',
        ]);
        // B has a run of its own, so `recent()` does NOT return early and the
        // count query actually executes: B seeing no runs because it had none
        // would say nothing about which runs `runs_total` counts (Codex,
        // 2026-08-26).
        $this->payload('log', $this->kb->b->curatorBearer, [
            'action' => 'run-summary', 'description' => 'B ran a pass.',
        ]);

        $this->loginAs($this->kb->b);
        $this->sessionRequest('GET', '/api/curation/digest');
        self::assertSame(200, $this->httpStatus(), $this->body());
        $result = $this->jsonResponse();

        self::assertSame(['B ran a pass.'], array_column($result['runs'], 'description'));
        self::assertSame(1, $result['runs_total'], "the total must count B's runs, not everybody's");
    }

    // ---- the window --------------------------------------------------------

    /**
     * The defect the whole `started_at` field exists for.
     *
     * A curator connection is used for a scheduled pass AND by hand, and the
     * rows are indistinguishable. Here the connection edits a note, then runs
     * a pass that begins afterwards and touches nothing. The edit must not be
     * counted as the pass's work.
     */
    public function testAStatedStartExcludesWorkTheConnectionDidBeforeThePass(): void
    {
        $note = $this->kb->a->note('Edited by hand', 'Body.', ['reference'], summary: 'A description.');
        $this->payload('propose', $this->kb->a->curatorBearer, [
            'note_id' => $note->getId(),
            'patch' => [['find' => 'Body.', 'replace' => 'Body, edited by hand.']],
        ]);
        // The hand-driven edit is an hour old; the pass starts a minute ago.
        $this->backdate($this->kb->a->curatorTokenId, '1 hour');

        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary',
            'description' => 'A pass that changed nothing.',
            'started_at' => (new \DateTimeImmutable('-1 minute'))->format(DATE_ATOM),
        ]);

        $this->loginAs($this->kb->a);
        $run = $this->digest()[0];

        self::assertTrue($run['window_bounded']);
        self::assertSame(0, $run['counts']['edited'], 'the hand-driven edit predates the pass');
    }

    /**
     * The same run, with the pass declining to say when it began: the counts
     * become everything since its last pass, and the screen is told so.
     */
    public function testWithoutAStatedStartTheWindowIsTheFallbackAndSaysSo(): void
    {
        $note = $this->kb->a->note('Edited by hand', 'Body.', ['reference'], summary: 'A description.');
        $this->payload('propose', $this->kb->a->curatorBearer, [
            'note_id' => $note->getId(),
            'patch' => [['find' => 'Body.', 'replace' => 'Body, edited by hand.']],
        ]);
        $this->backdate($this->kb->a->curatorTokenId, '1 hour');

        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary', 'description' => 'A pass that changed nothing.',
        ]);

        $this->loginAs($this->kb->a);
        $run = $this->digest()[0];

        self::assertFalse($run['window_bounded']);
        self::assertSame(1, $run['counts']['edited'], 'the fallback window reaches back over the whole gap');
    }

    /**
     * A pass cannot reach back over the pass before it. `started_at` is the
     * one unverifiable thing a run says about itself, so the only thing it is
     * allowed to do is make a window SMALLER.
     */
    public function testAStatedStartIsClampedToThePreviousRunSummary(): void
    {
        $note = $this->kb->a->note('Worked by the first pass', 'Body.', ['reference'], summary: 'A description.');
        $this->payload('propose', $this->kb->a->curatorBearer, [
            'note_id' => $note->getId(),
            'patch' => [['find' => 'Body.', 'replace' => 'Body, first pass.']],
        ]);
        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary', 'description' => 'The first pass, which did the edit.',
        ]);
        $this->backdate($this->kb->a->curatorTokenId, '1 hour');

        // The second pass claims to have started two hours ago — before the
        // first pass ran at all.
        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary',
            'description' => 'The second pass, reaching backwards.',
            'started_at' => (new \DateTimeImmutable('-2 hours'))->format(DATE_ATOM),
        ]);

        $this->loginAs($this->kb->a);
        $run = $this->digest()[0];

        self::assertSame(0, $run['counts']['edited'], "the first pass's edit belongs to the first pass");
    }

    /**
     * Two curator connections running at once are two windows that never
     * overlap — the shape C-9 made real.
     */
    public function testOneConnectionsWorkIsNotCountedInAnothersRun(): void
    {
        $second = $this->secondCuratorBearer();
        $note = $this->kb->a->note('Worked by the second curator', 'Body.', ['reference'], summary: 'A description.');
        $this->payload('propose', $second, [
            'note_id' => $note->getId(),
            'patch' => [['find' => 'Body.', 'replace' => 'Body, by the second.']],
        ]);

        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary', 'description' => 'The FIRST connection, which did nothing.',
        ]);

        $this->loginAs($this->kb->a);
        $run = $this->digest()[0];

        self::assertSame('The FIRST connection, which did nothing.', $run['description']);
        self::assertSame(0, $run['counts']['edited']);
    }

    // ---- examined belongs to its run by link, not by time -------------------

    /**
     * `examined` rows are persisted AFTER the run-summary, in the same request
     * and so in the same second, with a higher id. Measured against the real
     * vault before `run_id` existed: run 656's own eighteen counted as zero,
     * and run 597's nineteen were attributed to the run after it.
     */
    public function testExaminedRowsAreCountedForTheRunThatWroteThemNotTheNext(): void
    {
        $one = $this->kb->a->note('Read by the first pass', 'Body.', ['reference'], summary: 'A description.');
        $two = $this->kb->a->note('Read by the first pass too', 'Body.', ['reference'], summary: 'A description.');

        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary',
            'description' => 'First pass, read two notes.',
            'examined' => [$one->getId(), $two->getId()],
        ]);
        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary', 'description' => 'Second pass, read nothing.',
        ]);

        $this->loginAs($this->kb->a);
        $runs = $this->digest();

        self::assertSame('Second pass, read nothing.', $runs[0]['description']);
        self::assertSame(0, $runs[0]['counts']['examined'], "the first pass's reading is not the second's");
        self::assertSame(2, $runs[1]['counts']['examined']);
    }

    /**
     * The count is the number of rows, not the number of surviving notes.
     *
     * `curator_log.note_id` is `ON DELETE SET NULL`, so a pass that read a
     * note since deleted keeps its row and loses the reference. Reading the
     * count off the note ids under-reported every such run — found in a
     * browser at 16, against a journal scoped to the same run showing 18.
     */
    public function testExaminedStillCountsANoteThatHasSinceBeenDeleted(): void
    {
        $kept = $this->kb->a->note('Kept', 'Body.', ['reference'], summary: 'A description.');
        $doomed = $this->kb->a->note('Deleted afterwards', 'Body.', ['reference'], summary: 'A description.');

        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary',
            'description' => 'Read both.',
            'examined' => [$kept->getId(), $doomed->getId()],
        ]);

        $this->loginAs($this->kb->a);
        $this->sessionRequest('DELETE', '/api/notes/'.$doomed->getId());
        self::assertSame(200, $this->httpStatus(), $this->body());

        $run = $this->digest()[0];

        self::assertSame(2, $run['counts']['examined'], 'the pass read two notes whatever became of them');
    }

    /**
     * The count and the rows behind it are the same number.
     *
     * This is the property that makes the digest's links worth having: a
     * count you click into must land on exactly what it counted.
     */
    public function testTheCountAndTheJournalScopedToThatRunAgree(): void
    {
        $one = $this->kb->a->note('One', 'Body.', ['reference'], summary: 'A description.');
        $two = $this->kb->a->note('Two', 'Body.', ['reference'], summary: 'A description.');
        $this->payload('propose', $this->kb->a->curatorBearer, [
            'note_id' => $one->getId(),
            'patch' => [['find' => 'Body.', 'replace' => 'Body, edited.']],
        ]);
        $summary = $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary',
            'description' => 'One edit, two notes read.',
            'examined' => [$one->getId(), $two->getId()],
        ]);

        $this->loginAs($this->kb->a);
        $run = $this->digest()[0];

        foreach (['examined' => 'examined', 'edited' => 'edit'] as $count => $action) {
            $this->sessionRequest('GET', "/api/curator-log?run={$summary['id']}&action=$action&limit=500");
            self::assertSame(200, $this->httpStatus(), $this->body());
            self::assertCount(
                $run['counts'][$count],
                $this->jsonResponse()['entries'],
                "the digest's $count count and the rows it links to disagree",
            );
        }
    }

    /**
     * The journal scoped to a run must not pick up the PREVIOUS run's examined
     * rows, which sit inside its window by timestamp.
     */
    public function testTheJournalScopedToARunExcludesAnotherRunsExaminedRows(): void
    {
        $note = $this->kb->a->note('Read by the first pass', 'Body.', ['reference'], summary: 'A description.');
        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary', 'description' => 'First.', 'examined' => [$note->getId()],
        ]);
        $second = $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary', 'description' => 'Second.',
        ]);

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', "/api/curator-log?run={$second['id']}&limit=500");
        self::assertSame(200, $this->httpStatus(), $this->body());
        $actions = array_column($this->jsonResponse()['entries'], 'action');

        self::assertNotContains('examined', $actions);
    }

    // ---- the tripwire ------------------------------------------------------

    // ---- the notes a pass touched, not only how many ----------------------

    /**
     * The count said "edited 8" and never which eight. The rows come from
     * the same window as the counts, one per note however many edits it took,
     * and a note since deleted keeps its title without a link.
     */
    public function testARunListsTheNotesItWroteAndTheNotesItRead(): void
    {
        $edited = $this->kb->a->note('Edited by the pass', 'Body.', ['reference'], summary: 'A description.');
        $read = $this->kb->a->note('Only read', 'Body.', ['reference'], summary: 'A description.');
        $doomed = $this->kb->a->note('Read, then deleted', 'Body.', ['reference'], summary: 'A description.');

        $this->payload('propose', $this->kb->a->curatorBearer, [
            'note_id' => $edited->getId(),
            'patch' => [['find' => 'Body.', 'replace' => 'Body, edited.']],
        ]);
        $this->payload('propose', $this->kb->a->curatorBearer, [
            'note_id' => $edited->getId(),
            'summary' => 'A second description.',
        ]);
        $created = $this->payload('propose', $this->kb->a->curatorBearer, [
            'title' => 'Written by the pass',
            'body_md' => 'New.',
            'tags' => ['reference'],
            'summary' => 'A description.',
        ]);
        $this->payload('propose', $this->kb->a->curatorBearer, [
            'note_id' => $doomed->getId(),
            'title' => 'Read, renamed, then deleted',
        ]);
        $this->payload('propose', $this->kb->a->curatorBearer, [
            'note_id' => $doomed->getId(),
            'summary' => 'Described under the new title.',
        ]);
        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary',
            'description' => 'Two edits to one note, one note created, three read.',
            'examined' => [$edited->getId(), $read->getId(), $doomed->getId()],
        ]);

        $this->loginAs($this->kb->a);
        $this->sessionRequest('DELETE', '/api/notes/'.$doomed->getId());
        self::assertSame(200, $this->httpStatus(), $this->body());

        $run = $this->digest()[0];

        self::assertSame(4, $run['counts']['edited'], 'four edit rows');
        self::assertSame(3, $run['written_total'], 'but three notes written');
        $written = array_column($run['written'], null, 'title');
        self::assertSame(
            ['Read, renamed, then deleted', 'Written by the pass', 'Edited by the pass'],
            array_keys($written),
            'newest first, and a note renamed then deleted in the pass is one row under its last title',
        );
        self::assertNull($written['Read, renamed, then deleted']['note_id']);
        self::assertSame(2, $written['Read, renamed, then deleted']['rows']);
        self::assertSame('created', $written['Written by the pass']['kind']);
        self::assertSame((int) $created['note']['id'], $written['Written by the pass']['note_id']);
        self::assertSame('edited', $written['Edited by the pass']['kind']);
        self::assertSame(2, $written['Edited by the pass']['rows']);
        self::assertSame($edited->getId(), $written['Edited by the pass']['note_id']);

        self::assertSame(3, $run['counts']['examined']);
        $examined = array_column($run['examined'], 'note_id', 'title');
        self::assertSame($edited->getId(), $examined['Edited by the pass']);
        self::assertSame($read->getId(), $examined['Only read']);
        self::assertArrayHasKey('Read, renamed, then deleted', $examined, 'the row recorded the title the note had when it was read');
        self::assertNull($examined['Read, renamed, then deleted'], 'a deleted note keeps the title its row recorded and loses its link');
    }

    public function testABoundedRunIsToldWhereItsClaimAndTheJournalDisagree(): void
    {
        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary',
            'description' => 'A pass that says it made three edits.',
            'started_at' => (new \DateTimeImmutable('-1 minute'))->format(DATE_ATOM),
            'claimed' => ['edited' => 3, 'held' => 0],
        ]);

        $this->loginAs($this->kb->a);
        $run = $this->digest()[0];

        self::assertSame(
            [['claim' => 'edited', 'claimed' => 3, 'logged' => 0]],
            $run['mismatches'],
            'a claim that matches must not be reported, and one that does not must be',
        );
    }

    /**
     * A claim of ZERO is a claim, and the log can contradict it.
     *
     * The test above cannot see this: its `held => 0` matches, so an
     * implementation that silently DISCARDED zero-valued claims passed it
     * unchanged (Codex, 2026-08-26). The whole "absent is not zero" rule lives
     * or dies here — a pass that says it held nothing while the log holds one
     * has said something wrong, and must be told.
     */
    public function testAClaimOfZeroIsStillCheckedAgainstTheJournal(): void
    {
        $note = $this->kb->a->note('Held for review', 'Body.', ['reference'], summary: 'A description.');
        $this->payload('propose', $this->kb->a->curatorBearer, [
            'note_id' => $note->getId(),
            'patch' => [['find' => 'Body.', 'replace' => 'Body, proposed.']],
            'hold' => true,
        ]);
        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary',
            'description' => 'A pass that says it held nothing.',
            'started_at' => (new \DateTimeImmutable('-1 minute'))->format(DATE_ATOM),
            'claimed' => ['held' => 0],
        ]);

        $this->loginAs($this->kb->a);
        $run = $this->digest()[0];

        self::assertSame([['claim' => 'held', 'claimed' => 0, 'logged' => 1]], $run['mismatches']);
    }

    /**
     * The rule that keeps the tripwire worth having: a run whose boundary the
     * server could not establish is never accused. Without it every honest
     * nightly pass on the operator's own vault would have been reported wrong,
     * because the fallback window holds a day of hand-driven work.
     */
    public function testAnUnboundedRunIsNeverAccusedEvenWhenItsClaimIsWrong(): void
    {
        $note = $this->kb->a->note('Edited by hand', 'Body.', ['reference'], summary: 'A description.');
        $this->payload('propose', $this->kb->a->curatorBearer, [
            'note_id' => $note->getId(),
            'patch' => [['find' => 'Body.', 'replace' => 'Body, edited by hand.']],
        ]);
        $this->backdate($this->kb->a->curatorTokenId, '1 hour');

        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary',
            'description' => 'A pass that says it made no edits, and did not say when it began.',
            'claimed' => ['edited' => 0],
        ]);

        $this->loginAs($this->kb->a);
        $run = $this->digest()[0];

        self::assertFalse($run['window_bounded']);
        self::assertSame(1, $run['counts']['edited'], 'the fallback window does hold the hand-driven edit');
        self::assertSame([], $run['mismatches'], 'and the run is not accused over it');
    }

    /** A claim a pass did not file is not compared; only what it stated is. */
    public function testAClaimNotFiledIsNotChecked(): void
    {
        $note = $this->kb->a->note('Edited', 'Body.', ['reference'], summary: 'A description.');
        $this->payload('propose', $this->kb->a->curatorBearer, [
            'note_id' => $note->getId(),
            'patch' => [['find' => 'Body.', 'replace' => 'Body, edited.']],
        ]);
        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary',
            'description' => 'Claims only its holds.',
            'started_at' => (new \DateTimeImmutable('-1 minute'))->format(DATE_ATOM),
            'claimed' => ['held' => 0],
        ]);

        $this->loginAs($this->kb->a);
        $run = $this->digest()[0];

        self::assertSame(1, $run['counts']['edited']);
        self::assertSame([], $run['mismatches'], 'nothing was claimed about edits, so nothing is checked');
    }

    /**
     * A start the server did not USE must not leave the run marked bounded.
     *
     * Two ways to send one that cannot bound the window, and both used to slip
     * through because `bounded` followed the fact that a VALUE arrived rather
     * than the start that was applied (Codex, 2026-08-26):
     * a stale start earlier than the previous pass, and one inside the tool's
     * sixty-second future allowance, which puts the lower bound after the
     * summary and leaves an empty window claims are then checked against.
     *
     * @dataProvider unusableStarts
     */
    public function testAStartTheServerCannotUseLeavesTheRunUnbounded(string $offset): void
    {
        $note = $this->kb->a->note('Edited by hand', 'Body.', ['reference'], summary: 'A description.');
        $this->payload('propose', $this->kb->a->curatorBearer, [
            'note_id' => $note->getId(),
            'patch' => [['find' => 'Body.', 'replace' => 'Body, edited by hand.']],
        ]);
        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary', 'description' => 'The previous pass.',
        ]);
        $this->backdate($this->kb->a->curatorTokenId, '1 hour');

        $this->payload('propose', $this->kb->a->curatorBearer, [
            'note_id' => $note->getId(),
            'patch' => [['find' => 'Body, edited by hand.', 'replace' => 'Body, edited again by hand.']],
        ]);
        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary',
            'description' => 'A pass with an unusable start, claiming it changed nothing.',
            'started_at' => (new \DateTimeImmutable($offset))->format(DATE_ATOM),
            'claimed' => ['edited' => 0],
        ]);

        $this->loginAs($this->kb->a);
        $run = $this->digest()[0];

        self::assertFalse($run['window_bounded'], 'a start the server ignored does not bound anything');
        self::assertSame([], $run['mismatches'], 'and an unbounded run is never accused');
    }

    /** @return array<string, array{string}> */
    public static function unusableStarts(): array
    {
        return [
            'earlier than the previous pass' => ['-3 hours'],
            'inside the future allowance' => ['+30 seconds'],
        ];
    }

    /**
     * A run's own writes still land inside a stated window.
     *
     * The counter-case to the two above, and the one that stops "ignore every
     * stated start" from passing this file: a start between the previous pass
     * and the summary must be USED, and must be reported as bounding.
     */
    public function testAUsableStartBoundsTheWindowAndLicensesTheTripwire(): void
    {
        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary', 'description' => 'The previous pass.',
        ]);
        $this->backdate($this->kb->a->curatorTokenId, '1 hour');

        $note = $this->kb->a->note('Edited by the pass', 'Body.', ['reference'], summary: 'A description.');
        $this->payload('propose', $this->kb->a->curatorBearer, [
            'note_id' => $note->getId(),
            'patch' => [['find' => 'Body.', 'replace' => 'Body, edited by the pass.']],
        ]);
        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary',
            'description' => 'A pass that edited one note and says so.',
            'started_at' => (new \DateTimeImmutable('-5 minutes'))->format(DATE_ATOM),
            'claimed' => ['edited' => 1],
        ]);

        $this->loginAs($this->kb->a);
        $run = $this->digest()[0];

        self::assertTrue($run['window_bounded']);
        self::assertSame(1, $run['counts']['edited'], "the pass's own edit is inside its window");
        self::assertSame([], $run['mismatches']);
    }

    /**
     * Work written in the same SECOND as a stated start is not the run's.
     *
     * `curator_log.created_at` and `run_started_at` are both whole seconds,
     * so a row at 10:00:00.4 and a start at 10:00:00.6 are the same value and
     * the tuple comparison cannot separate them (Codex, 2026-08-26). The
     * boundary is rounded UP, which errs toward a smaller window — the same
     * direction every other decision about an unverifiable boundary errs
     * here, and it costs nothing real, because a pass's first write is never
     * in the same second as its own start.
     */
    public function testWorkInTheSameSecondAsAStatedStartIsNotCounted(): void
    {
        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary', 'description' => 'The previous pass.',
        ]);
        $this->backdate($this->kb->a->curatorTokenId, '1 hour');

        $note = $this->kb->a->note('Edited by hand', 'Body.', ['reference'], summary: 'A description.');
        $this->payload('propose', $this->kb->a->curatorBearer, [
            'note_id' => $note->getId(),
            'patch' => [['find' => 'Body.', 'replace' => 'Body, edited by hand.']],
        ]);
        // Half a minute back, so the pass below finishes in a LATER second
        // than it began — which every real pass does, and which this test must
        // too: rounding a start up to the next second would otherwise push it
        // past a summary written in the same second, and the run would fall
        // back rather than exercise the boundary this case is about.
        $this->backdate($this->kb->a->curatorTokenId, '30 seconds');
        $handEditAt = (string) $this->em->getConnection()->fetchOne(
            "SELECT created_at FROM curator_log WHERE token_id = :t AND action = 'edit' ORDER BY id DESC LIMIT 1",
            ['t' => $this->kb->a->curatorTokenId],
        );

        // The pass declares it began in the SAME recorded second as the
        // hand-driven edit above it — the one case the column's precision
        // leaves ambiguous.
        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary',
            'description' => 'A pass starting in the same second as somebody else\'s edit.',
            'started_at' => (new \DateTimeImmutable($handEditAt, new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'claimed' => ['edited' => 0],
        ]);

        $this->loginAs($this->kb->a);
        $run = $this->digest()[0];

        self::assertTrue($run['window_bounded']);
        self::assertSame(0, $run['counts']['edited'], 'a whole second of ambiguity resolves outward');
        self::assertSame([], $run['mismatches']);
    }

    /**
     * A composite count links to exactly the rows it counted.
     *
     * `proposed` is `delete-proposed` plus `merge-proposed`, so a count that
     * could name only one of them returned a row set that was not what it
     * counted (Codex, 2026-08-26). The journal's `action` therefore takes a LIST.
     */
    public function testACompositeCountLinksToExactlyTheRowsItCounted(): void
    {
        $gone = $this->kb->a->note('Superseded', 'Body.', ['reference'], summary: 'A description.');
        $absorbed = $this->kb->a->note('Duplicate', 'Body.', ['reference'], summary: 'A description.');
        $keeper = $this->kb->a->note('Keeper', 'Body.', ['reference'], summary: 'A description.');
        $this->payload('propose_delete', $this->kb->a->curatorBearer, [
            'note_id' => $gone->getId(), 'reason' => 'Superseded.',
        ]);
        $this->payload('propose_merge', $this->kb->a->curatorBearer, [
            'note_id' => $absorbed->getId(), 'into_note_id' => $keeper->getId(),
        ]);
        $summary = $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary', 'description' => 'One delete and one merge proposed.',
        ]);

        $this->loginAs($this->kb->a);
        $run = $this->digest()[0];
        self::assertSame(2, $run['counts']['proposed']);

        $this->sessionRequest(
            'GET',
            "/api/curator-log?run={$summary['id']}&action=delete-proposed,merge-proposed&limit=500",
        );
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertCount($run['counts']['proposed'], $this->jsonResponse()['entries']);
    }

    /**
     * The journal pages rather than cutting.
     *
     * It used to return the 200 most recent rows and tell the reader to narrow
     * their filters to reach the rest, which is an archive refusing to be read
     * (operator, 2026-08-29). Every row is now reachable, and the page says
     * how many there are in total so a reader can tell where they are.
     */
    public function testTheJournalPagesThroughEveryRow(): void
    {
        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary', 'description' => 'One.',
        ]);
        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary', 'description' => 'Two.',
        ]);

        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/curator-log?per_page=1');
        self::assertSame(200, $this->httpStatus(), $this->body());
        $first = $this->jsonResponse();
        self::assertCount(1, $first['entries']);
        self::assertSame(1, $first['page']);
        self::assertGreaterThanOrEqual(2, $first['total']);
        self::assertSame((int) ceil($first['total'] / 1), $first['pages']);

        $this->sessionRequest('GET', '/api/curator-log?per_page=1&page=2');
        $second = $this->jsonResponse();
        self::assertSame(2, $second['page']);
        self::assertNotSame(
            $first['entries'][0]['id'],
            $second['entries'][0]['id'],
            'a second page is different rows, not the same ones again',
        );

        // A page past the end is a stale link, not an error: it lands on the
        // last page and says which one that is.
        $this->sessionRequest('GET', '/api/curator-log?per_page=1&page=9999');
        $past = $this->jsonResponse();
        self::assertSame($past['pages'], $past['page']);
        self::assertCount(1, $past['entries']);
    }

    // ---- what a claim may be -----------------------------------------------

    public function testAnUnknownClaimKeyIsRefusedByName(): void
    {
        $body = $this->toolError('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary', 'description' => 'x', 'claimed' => ['deleted' => 1],
        ]);

        self::assertStringContainsString('is not something a run can claim', $body);
        self::assertStringContainsString('edited, held, proposed', $body);
    }

    public function testAClaimOnAnObservationIsRefused(): void
    {
        $body = $this->toolError('log', $this->kb->a->curatorBearer, [
            'action' => 'observation', 'description' => 'x', 'claimed' => ['edited' => 1],
        ]);

        self::assertStringContainsString('belongs on a run-summary', $body);
    }

    /**
     * The schema says ISO-8601; `new DateTimeImmutable()` also takes
     * "yesterday", "+3 hours" and "next tuesday", and the value becomes a run
     * boundary (Codex, 2026-08-26). A contract that quietly accepts relative
     * English is a contract nobody can rely on.
     */
    public function testARelativeDateIsNotAnIsoTimestamp(): void
    {
        $body = $this->toolError('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary', 'description' => 'x', 'started_at' => 'yesterday',
        ]);

        self::assertStringContainsString('not an ISO-8601 timestamp', $body);
    }

    public function testAStartInTheFutureIsRefused(): void
    {
        $body = $this->toolError('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary',
            'description' => 'x',
            'started_at' => (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM),
        ]);

        self::assertStringContainsString('cannot begin after it ended', $body);
    }

    // ---- the exceptions ----------------------------------------------------

    /**
     * A note the pass recorded as read while it still carries a structural
     * defect is the digest's own check on the pass's claim to have read it.
     * The predicates are the queue's own, so this cannot drift from what put
     * the note in the queue in the first place.
     */
    public function testANoteRecordedAsExaminedThatStillCarriesADefectIsSurfaced(): void
    {
        $sound = $this->kb->a->note('Sound', 'Body.', ['reference'], summary: 'A description.');
        $untagged = $this->kb->a->note('Never filed anywhere', 'Body.', [], summary: 'A description.');

        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary',
            'description' => 'Read both, changed neither.',
            'examined' => [$sound->getId(), $untagged->getId()],
        ]);

        $this->loginAs($this->kb->a);
        $run = $this->digest()[0];

        // Asserted per NOTE and per REASON rather than as a total. Neither
        // fixture is defect-free — a note in a test database has no embedding
        // and nothing links to it, so both carry `not_embedded` and
        // `disconnected` — and a total would pass just as well if the reasons
        // were attached to the wrong note.
        $reasons = array_column($run['still_defective'], 'reasons', 'note_id');
        self::assertContains('untagged', $reasons[$untagged->getId()]);
        self::assertNotContains('untagged', $reasons[$sound->getId()] ?? []);
    }

    /**
     * A delete the pass proposed is an exception while it is undecided, and
     * stops being one the moment the operator rules — the count of what was
     * proposed does not move, because that is a fact about the run.
     */
    public function testAProposedDeleteIsWaitingUntilItIsDecided(): void
    {
        $note = $this->kb->a->note('Proposed for deletion', 'Body.', ['reference'], summary: 'A description.');
        $this->payload('propose_delete', $this->kb->a->curatorBearer, [
            'note_id' => $note->getId(), 'reason' => 'Superseded.',
        ]);
        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary', 'description' => 'Proposed one delete.',
        ]);

        $this->loginAs($this->kb->a);
        $run = $this->digest()[0];
        self::assertSame(1, $run['counts']['proposed']);
        self::assertSame(1, $run['waiting_total']);
        self::assertSame('delete', $run['waiting'][0]['kind']);

        $this->in($this->kb->a);
        $proposalId = (int) $this->em->getConnection()->fetchOne(
            'SELECT id FROM edit_proposals WHERE note_id = :n AND type = :t',
            ['n' => $note->getId(), 't' => 'delete'],
        );
        $this->sessionRequest('POST', "/api/proposals/$proposalId/reject");
        self::assertSame(200, $this->httpStatus(), $this->body());

        $run = $this->digest()[0];
        self::assertSame(1, $run['counts']['proposed'], 'the run did propose it, and that does not change');
        self::assertSame(0, $run['waiting_total'], 'but it is no longer waiting on anybody');
    }
}
