<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * "Needs attention" — the queue, as the OWNER sees it from a browser.
 *
 * The defect this route exists for is not a bug in a query, it is an absence:
 * every view into the curation queue was curator-TOKEN work, so the person who
 * owns the knowledge base, raises the flags and gives the verdicts had no way
 * to ask what was waiting. These tests hold the three properties that made it
 * worth building rather than the fact that a route answers 200.
 *
 *  1. **One ranking, not two.** The rows the pane shows are ranked by the
 *     queue's own sort, not by an order the pane invented. (Not the stronger
 *     claim the first draft made: an assistant passing `include_pending` or a
 *     `reason` is asking a narrower question and gets a narrower list.)
 *  2. **A flag is never lost.** The queue deliberately suppresses a note whose
 *     deletion is already waiting on the operator — correct there, and a lie
 *     on this screen, where the flag is open and the operator is the one being
 *     waited on.
 *  3. **An agent token still cannot read any of it**, and a curator token
 *     still can. The rule must not change with the doorway.
 */
final class CurationAttentionTest extends ApiTestCase
{
    /** @return array<string, mixed> */
    private function attention(): array
    {
        $this->sessionRequest('GET', '/api/curation/attention');
        self::assertSame(200, $this->httpStatus(), $this->body());

        return $this->jsonResponse();
    }

    private function flag(int $noteId, string $comment): void
    {
        $this->sessionRequest('POST', "/api/notes/$noteId/flag", ['comment' => $comment]);
        self::assertSame(200, $this->httpStatus(), $this->body());
    }

    /** @param array<string, mixed> $arguments */
    private function callTool(string $tool, string $bearer, array $arguments = []): void
    {
        $this->request('POST', '/mcp', $bearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
        self::assertSame(200, $this->httpStatus(), "$tool did not answer: ".$this->body());
        self::assertNotTrue($this->jsonResponse()['result']['isError'] ?? false, $this->body());
    }

    // ---- who may read it ---------------------------------------------------

    public function testAnAgentTokenIsRefused(): void
    {
        $this->request('GET', '/api/curation/attention', $this->kb->a->agentBearer);

        self::assertSame(403, $this->httpStatus(), $this->body());
    }

    /**
     * The other half of the same rule, and the reason this route uses
     * `assertSessionOrCurator()` rather than `assertSessionAuth()`: a curator
     * token already reads every one of these numbers over MCP. Refusing it
     * here would make the answer depend on which door it came through.
     *
     * **What it does NOT pin, said so nobody reads it as more:** that the rows
     * and the census come from one call. Two equivalent calls would return the
     * same ids and pass this identically (Codex, 2026-08-29). The shared call
     * is an efficiency and an anti-drift argument, and the thing that actually
     * holds it is `preflight()` taking the limit — a mutation there is caught
     * by the row assertions below, not here.
     */
    public function testACuratorTokenReadsTheSameAnswerAsTheOwner(): void
    {
        $this->kb->a->note('Something to read', 'Body.');

        $this->loginAs($this->kb->a);
        $fromBrowser = $this->attention();

        $this->request('GET', '/api/curation/attention', $this->kb->a->curatorBearer);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $fromCurator = $this->jsonResponse();

        self::assertSame($fromBrowser['queue_total'], $fromCurator['queue_total']);
        self::assertSame(
            array_column($fromBrowser['candidates'], 'note_id'),
            array_column($fromCurator['candidates'], 'note_id'),
        );
    }

    public function testAnotherTeamsQueueIsNotVisible(): void
    {
        $mine = $this->kb->b->note('B keeps this', 'Body.');
        $theirs = $this->kb->a->note('A keeps this', 'Body.');
        $this->loginAs($this->kb->a);
        $this->flag($theirs->getId(), 'Look at this one.');

        $this->loginAs($this->kb->b);
        $result = $this->attention();

        self::assertSame([$mine->getId()], array_column($result['candidates'], 'note_id'));
        self::assertSame([], $result['open_flags']);
        self::assertStringNotContainsString('A keeps this', $this->body());
        self::assertStringNotContainsString('Look at this one.', $this->body());
    }

    // ---- one ranking, not two ---------------------------------------------

    /**
     * The operator's own flag sorts ahead of anything the database found, on
     * this screen as in the run — and the row says WHY it is there.
     */
    public function testTheOwnersFlagSortsAheadOfEveryStructuralDefect(): void
    {
        $defective = $this->kb->a->note('Untagged and undescribed', 'Body.');
        $flagged = $this->kb->a->note('Fine, but wrong', 'Body.', ['reference'], summary: 'A description.');

        $this->loginAs($this->kb->a);
        $this->flag($flagged->getId(), 'The port number here is out of date.');

        $result = $this->attention();

        self::assertSame(
            [$flagged->getId(), $defective->getId()],
            array_column($result['candidates'], 'note_id'),
        );
        self::assertContains('operator_flag', $result['candidates'][0]['reasons']);
        self::assertSame(
            'The port number here is out of date.',
            $result['candidates'][0]['operator_flag']['comment'],
        );
    }

    /**
     * A short list is never an empty queue.
     *
     * The pane shows the top of the queue, so `queue_total` beside it is what
     * stops ten rows from reading as ten notes — the failure this project has
     * already had once, when a census of zero defects was read as an empty
     * queue over 76 notes.
     */
    public function testThePaneShowsTheTopOfTheQueueAndSaysHowMuchMoreThereIs(): void
    {
        $ids = [];
        for ($i = 1; $i <= 12; ++$i) {
            $ids[] = $this->kb->a->note("Note $i", 'Body.')->getId();
        }

        $this->loginAs($this->kb->a);
        $result = $this->attention();

        self::assertSame(10, $result['queue_shown']);
        self::assertSame(12, $result['queue_total']);
        self::assertTrue($result['due']);
        // WHICH ten, not merely how many. Twelve notes of equal rank leave
        // `id ASC` as the only thing deciding, so asserting the count alone
        // passed against a reversed tie-breaker or any arbitrary slice — the
        // test named "the top of the queue" said nothing about the top
        // (Codex, 2026-08-29).
        self::assertSame(array_slice($ids, 0, 10), array_column($result['candidates'], 'note_id'));
    }

    /**
     * The shape `last_curated` serves an assistant is unchanged.
     *
     * `preflight()` gained a candidate limit for this pane; agents have been
     * reading its output since 2026-08-24, and a `candidates: []` appearing in
     * their answer reads as "the queue is empty" — the one sentence this verb
     * must never say by accident.
     */
    public function testTheAssistantsPreflightStillCarriesNoRows(): void
    {
        $this->kb->a->note('Something to read', 'Body.');

        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'last_curated', 'arguments' => []],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $preflight = json_decode(
            $this->jsonResponse()['result']['content'][0]['text'],
            true,
            32,
            JSON_THROW_ON_ERROR
        );

        self::assertArrayNotHasKey('candidates', $preflight);
        self::assertGreaterThan(0, $preflight['queue_total']);
    }

    // ---- a flag is never lost ---------------------------------------------

    /**
     * **The case this section exists for.** A note with a held delete leaves
     * the queue flag and all, so that the next run does not re-propose what is
     * already in the inbox. On this screen that same suppression would hide
     * the operator's own standing request from the only person who can answer
     * it — and the thing they are waiting on is a verdict only they can give.
     *
     * So the flag is listed, and it says which of the two states it is in.
     */
    public function testAFlagWaitingOnADeleteVerdictIsStillListedAndSaysSo(): void
    {
        $doomed = $this->kb->a->note('Probably obsolete', 'Body.');
        $this->loginAs($this->kb->a);
        $this->flag($doomed->getId(), 'I think this is superseded — check.');

        $this->callTool('propose_delete', $this->kb->a->agentBearer, [
            'note_id' => $doomed->getId(),
            'reason' => 'Superseded, as the flag says.',
        ]);

        $this->loginAs($this->kb->a);
        $result = $this->attention();

        self::assertNotContains(
            $doomed->getId(),
            array_column($result['candidates'], 'note_id'),
            'A note awaiting a destructive verdict must stay out of the QUEUE',
        );
        self::assertSame([$doomed->getId()], array_column($result['open_flags'], 'note_id'));
        self::assertTrue($result['open_flags'][0]['awaiting_review']);
        self::assertSame('I think this is superseded — check.', $result['open_flags'][0]['comment']);
    }

    /**
     * The ordinary flag, for contrast: open, in the queue, and not waiting on
     * anything. Without this the assertion above would pass against an
     * `awaiting_review` hard-coded true.
     */
    public function testAnOrdinaryOpenFlagIsNotMarkedAsWaitingOnAVerdict(): void
    {
        $note = $this->kb->a->note('Fine, but wrong', 'Body.', ['reference'], summary: 'A description.');
        $this->loginAs($this->kb->a);
        $this->flag($note->getId(), 'Reads as settled and is not.');

        $result = $this->attention();

        self::assertSame([$note->getId()], array_column($result['open_flags'], 'note_id'));
        self::assertFalse($result['open_flags'][0]['awaiting_review']);
        self::assertContains($note->getId(), array_column($result['candidates'], 'note_id'));
    }

    public function testAWithdrawnFlagLeavesTheList(): void
    {
        $note = $this->kb->a->note('Fine, but wrong', 'Body.', ['reference'], summary: 'A description.');
        $this->loginAs($this->kb->a);
        $this->flag($note->getId(), 'Reads as settled and is not.');
        self::assertCount(1, $this->attention()['open_flags']);

        $this->sessionRequest('DELETE', "/api/notes/{$note->getId()}/flag");
        self::assertSame(200, $this->httpStatus(), $this->body());

        self::assertSame([], $this->attention()['open_flags']);
    }

    // ---- the empty account -------------------------------------------------

    /**
     * What a stranger's new account sees: nothing waiting, no numbers, no
     * scolding. The vault-rot dashboard was rejected for exactly this reason,
     * and the empty state is where that ruling either holds or quietly does
     * not.
     */
    public function testANewKnowledgeBaseHasNothingWaiting(): void
    {
        $this->loginAs($this->kb->a);
        $result = $this->attention();

        self::assertFalse($result['due']);
        self::assertSame(0, $result['queue_total']);
        self::assertSame([], $result['candidates']);
        self::assertSame([], $result['open_flags']);
        self::assertSame(0, $result['open_flags_total']);
        self::assertNull($result['last_run_at']);
    }

    // ---- what Codex found on review (2026-08-29) ---------------------------

    /**
     * The flags list is capped, and the cap is visible.
     *
     * Codex, on review: the first version served 25 of 26 with nothing on the
     * screen saying so — one paragraph below a comment arguing that truncating
     * somebody's own requests is worse than a long list. The cap was not the
     * error; a cap nobody can see was.
     */
    public function testTheFlagListSaysWhenItIsShowingOnlySomeOfThem(): void
    {
        $this->loginAs($this->kb->a);
        for ($i = 1; $i <= 26; ++$i) {
            $note = $this->kb->a->note("Note $i", 'Body.');
            $this->flag($note->getId(), "Something is wrong with number $i.");
        }

        $result = $this->attention();

        self::assertCount(25, $result['open_flags']);
        self::assertSame(26, $result['open_flags_total']);
    }
}
