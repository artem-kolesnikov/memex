<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * The preflight: "is there curation to do?", asked before spending a turn.
 *
 * B4 from the curation brainstorm, and the shape it survived in is the point.
 * A persisted `curation_due` flag was killed by both adversaries on two
 * counts — it duplicates what the log already knows, the same
 * derive-from-the-log ruling that keeps `last_curated_at` off the note, and a
 * stored due-flag is the first half of a server-owned scheduler, which the
 * 2026-08-25 "curation is the user's agents, full stop" ruling exists to
 * prevent. So this is computed on every call and stored nowhere.
 *
 * **The invariant these tests exist to hold: `due` carries no cadence.** It is
 * `queue_total > 0` and nothing else. The queue has already applied the
 * cooldown and the earned rest ladder, so "something is in the queue" already
 * means "there is work now" — and anything on top of that would be this server
 * deciding when a run should happen. If a future change gives `due` a clock,
 * these tests should fail, and that is what they are for.
 */
final class CurationPreflightTest extends ApiTestCase
{
    /** @return array<string, mixed> */
    private function preflight(): array
    {
        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'last_curated', 'arguments' => []],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        return json_decode($this->jsonResponse()['result']['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);
    }

    /**
     * Record a pass over this note, then date the whole log into the past so
     * the note's earned rest has run out and it is back in the queue.
     */
    private function examined(int $noteId, int $daysAgo): void
    {
        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'log', 'arguments' => [
                'action' => 'run-summary',
                'description' => 'A pass.',
                'examined' => [$noteId],
            ]],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $this->in($this->kb->a);
        $this->em->getConnection()->executeStatement(
            'UPDATE curator_log SET created_at = :at WHERE note_id = :id',
            ['at' => $this->utc("-$daysAgo days"), 'id' => $noteId]
        );
    }

    private function utc(string $when): string
    {
        return (new \DateTimeImmutable($when, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function soundNote(string $title): \App\Entity\Note
    {
        $partner = $title.' — companion';
        $note = $this->kb->a->note($title, "Settled. See [[$partner]].", ['reference'], summary: 'A description.');
        $this->kb->a->note($partner, "See [[$title]].", ['reference'], summary: 'A description.');

        return $note;
    }

    public function testAnUntouchedKnowledgeBaseSaysNobodyHasEverCuratedIt(): void
    {
        $this->soundNote('Something to read');

        $result = $this->preflight();

        self::assertNull($result['last_run_at']);
        self::assertTrue($result['due']);
        self::assertContains('no pass has ever curated this knowledge base', $result['due_reasons']);
    }

    /**
     * The band `reason_counts` cannot see, said in words.
     *
     * This is the preflight's whole reason for existing rather than being
     * "call curation_candidates and look at the census". A real unattended run
     * read `reason_counts` as all zeroes over a queue of 76 sound notes and
     * reported a complete run — the census counts DEFECTS, and notes nobody
     * has read are not defective. An agent deciding whether a turn is worth
     * spending would make the identical mistake.
     */
    public function testAQueueOfSoundNotesIsDueAndSaysSoDespiteAZeroCensus(): void
    {
        $this->soundNote('Sound one');
        $this->soundNote('Sound two');

        $result = $this->preflight();

        self::assertSame(0, array_sum($result['reason_counts']), 'nothing here is broken');
        self::assertTrue($result['due'], 'and there is still work — this is the 76-note post-mortem');
        self::assertNotEmpty(array_filter(
            $result['due_reasons'],
            static fn (string $r): bool => str_contains($r, 'no pass has ever read')
        ), 'the reasons must name the band the census cannot count: '.json_encode($result['due_reasons']));
    }

    /**
     * The sound-note count is a count of NOTES, not of leftover defects.
     *
     * `reason_counts` is one tally per reason over the eligible set, so a note
     * carrying three defects contributes three to `array_sum()` while being one
     * row in `total`. Subtracting one from the other therefore under-reports
     * the sound band by exactly the number of extra defects, and can go
     * negative.
     *
     * This is not a rounding nicety: the sentence it produces is a claim about
     * the operator's knowledge base, addressed to an agent deciding what to do,
     * and it was simply false. Every note in the other tests here carries at
     * most one defect, which is why they all passed over it.
     */
    public function testTheSoundNoteCountIsNotConfusedByNotesWithSeveralDefects(): void
    {
        // Four pairs — eight structurally sound notes.
        foreach (['One', 'Two', 'Three', 'Four'] as $title) {
            $this->soundNote($title);
        }
        // Two notes carrying three defects each: untagged, no_summary, disconnected.
        $this->kb->a->note('Broken three ways', 'Body.', []);
        $this->kb->a->note('Broken three ways as well', 'Body.', []);

        $result = $this->preflight();

        self::assertSame(10, $result['queue_total'], 'ten notes, nothing on cooldown');
        self::assertGreaterThan(
            $result['queue_total'] - array_sum($result['reason_counts']),
            8,
            'the naive subtraction must actually be wrong here, or this test proves nothing'
        );

        $sound = array_values(array_filter(
            $result['due_reasons'],
            static fn (string $r): bool => str_contains($r, 'no pass has ever read')
        ));
        self::assertCount(1, $sound, 'the sound band should be reported once: '.json_encode($result['due_reasons']));
        self::assertStringStartsWith('8 notes', $sound[0], 'eight notes are sound, not four');
    }

    /**
     * A note back off its earned rest is NOT reported as unread.
     *
     * `defect_free` counts every structurally clean, unflagged note in the
     * queue — and that includes notes several passes have read and approved,
     * which return when their rest expires. Reported as one figure they read as
     * a growing unread backlog, which is the wrong end of the queue to work.
     * Found by an adversarial review, 2026-08-25, which built exactly this
     * case and got wording identical to the never-curated one.
     */
    public function testANoteBackOffItsRestIsNotReportedAsNeverRead(): void
    {
        $read = $this->soundNote('Read and approved');
        $this->soundNote('Nobody has opened this');

        // A pass reads the first pair and finds nothing; then time passes and
        // its earned rest runs out, so it returns to the queue.
        $this->examined($read->getId(), 400);

        $result = $this->preflight();

        $never = array_values(array_filter($result['due_reasons'],
            static fn (string $r): bool => str_contains($r, 'no pass has ever read')));
        $again = array_values(array_filter($result['due_reasons'],
            static fn (string $r): bool => str_contains($r, 'read before and due again')));

        self::assertCount(1, $never, json_encode($result['due_reasons']));
        self::assertCount(1, $again, 'the returning note needs its own line: '.json_encode($result['due_reasons']));
        self::assertStringStartsWith('1 note', $again[0], 'exactly one note has been read before');
        self::assertGreaterThan(
            $result['never_read'],
            $result['defect_free'],
            'defect_free must include the returning note while never_read must not'
        );
    }

    public function testDefectsAreNamedWithTheirCounts(): void
    {
        $this->kb->a->note('Untagged one', 'Body.', [], summary: 'A description.');
        $this->kb->a->note('Untagged two', 'Body.', [], summary: 'A description.');

        $result = $this->preflight();

        self::assertSame(2, $result['reason_counts']['untagged']);
        self::assertContains('2 notes — untagged', $result['due_reasons']);
    }

    /**
     * An empty queue is NOT due, and that is the only "no" this verb gives.
     */
    public function testAnEmptyQueueIsNotDue(): void
    {
        $note = $this->soundNote('Read and settled');

        // Curate everything, so the whole queue goes to rest.
        $this->in($this->kb->a);
        $ids = array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT id FROM notes'));
        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'log', 'arguments' => [
                'action' => 'run-summary', 'description' => 'A pass.', 'examined' => $ids,
            ]],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $result = $this->preflight();

        self::assertSame(0, $result['queue_total']);
        self::assertFalse($result['due']);
        self::assertSame([], $result['due_reasons']);
        self::assertNotNull($result['last_run_at'], 'and it knows a pass happened');
        self::assertNotNull($result['last_run_by']);
        self::assertGreaterThan(0, $note->getId());
    }

    /**
     * A note changed since the last pass counts — unless the curator is the
     * one that changed it.
     *
     * The state is constructed directly rather than driven through a curator
     * write, and the first version of this test did drive one, passed for the
     * wrong reason, and proved nothing. A curator write also writes a LOG row,
     * which moves `last_run_at` forward past its own edit — so the count fell
     * to zero because the window moved, not because the actor was filtered.
     * Deleting the actor filter would not have failed it.
     *
     * What the filter actually guards is narrower and cannot be reached from
     * the outside: `curator_log.created_at` and `notes.updated_at` are both
     * whole seconds, written in one flush whose internal ordering is not
     * guaranteed, so a curator's note can land one second AFTER the log row
     * that covers it. That is the row this predicate exists to exclude, and
     * setting it up is the only way to see it.
     */
    public function testAChangeSinceTheLastPassCountsUnlessTheCuratorMadeIt(): void
    {
        $byPerson = $this->soundNote('Changed by a person');
        $byCurator = $this->soundNote('Changed by the curator');

        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'log', 'arguments' => ['action' => 'run-summary', 'description' => 'A pass.']],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->in($this->kb->a);
        $conn = $this->em->getConnection();
        // The pass happened an hour ago; everything it read is older still.
        $conn->executeStatement('UPDATE curator_log SET created_at = :at', ['at' => $this->utc('-1 hour')]);
        $conn->executeStatement('UPDATE notes SET updated_at = :at', ['at' => $this->utc('-2 hours')]);

        self::assertSame(0, $this->preflight()['notes_changed_since'], 'nothing has moved since the pass yet');

        // Two notes touched since, by different actors, at the same moment.
        $this->in($this->kb->a);
        $conn = $this->em->getConnection();
        $now = $this->utc('now');
        $conn->executeStatement(
            "UPDATE notes SET updated_at = :at, last_actor = 'human' WHERE id = :id",
            ['at' => $now, 'id' => $byPerson->getId()]
        );
        $conn->executeStatement(
            "UPDATE notes SET updated_at = :at, last_actor = 'curator' WHERE id = :id",
            ['at' => $now, 'id' => $byCurator->getId()]
        );

        self::assertSame(
            1,
            $this->preflight()['notes_changed_since'],
            'the person\'s edit counts and the curator\'s does not — a pass must not report work it created itself'
        );
    }

    /**
     * An empty `note_ids` is refused rather than quietly answered as the
     * preflight: a caller whose candidate set came back empty would otherwise
     * get an answer about the knowledge base and read it as an answer about
     * the notes it asked for.
     */
    public function testAnEmptyIdListIsRefusedRatherThanTreatedAsOmitted(): void
    {
        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'last_curated', 'arguments' => ['note_ids' => []]],
        ]);

        $text = $this->jsonResponse()['result']['content'][0]['text'];
        self::assertStringContainsString('omit it entirely', $text);
    }

    /** The per-note form is untouched by the new one. */
    public function testTheBatchFormStillAnswersAboutNotes(): void
    {
        $note = $this->soundNote('Asked about by id');

        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'last_curated', 'arguments' => ['note_ids' => [$note->getId()]]],
        ]);
        $result = json_decode($this->jsonResponse()['result']['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);

        self::assertSame($note->getId(), $result['notes'][0]['note_id']);
        self::assertNull($result['notes'][0]['last_at']);
        self::assertArrayNotHasKey('due', $result, 'the two forms must not be confusable');
    }

    /**
     * The tool's own schema must not forbid the call the tool documents.
     *
     * `last_curated` declared `note_ids` REQUIRED for as long as the batch form
     * was its only form, and shipping the no-argument preflight without
     * removing that left the feature's headline entry point unreachable from
     * any client that validates arguments against `inputSchema` before
     * dispatching — which is most of them. The server-side logic was correct
     * the whole time, so nothing failed loudly; the call simply could not be
     * made, and in this session it was mistaken for a stale cached schema.
     *
     * Found by an adversarial review, 2026-08-25.
     */
    public function testTheToolSchemaDoesNotRequireTheArgumentThatMustBeOmitted(): void
    {
        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $tools = $this->jsonResponse()['result']['tools'];
        $verb = array_values(array_filter($tools, static fn (array $t): bool => 'last_curated' === $t['name']));
        self::assertCount(1, $verb);

        self::assertNotContains(
            'note_ids',
            $verb[0]['inputSchema']['required'] ?? [],
            'the schema forbids omitting the argument whose omission IS the documented preflight call'
        );
        self::assertArrayHasKey('note_ids', $verb[0]['inputSchema']['properties'],
            'and it must still be offered — the batch form is unchanged');
    }

    /**
     * REVERSED 2026-08-26, and the reversal is the point.
     *
     * This asserted that an agent-role token is refused the preflight "like
     * every queue verb". That refusal is exactly what made an agent-role
     * connection improvise: asked whether there was curating to do, it could
     * not ask, so it guessed — and the guess arrives in the operator's inbox
     * looking like a curated pass. Asking "is there work here" is a fact about
     * the rows, not a curatorial act, and the answer is identical whoever
     * asks.
     *
     * What the role still gates is unchanged and tested next door
     * ({@see McpCurationAccessTest}): recording a run, reading the audit,
     * resolving flags, and applying an edit without review.
     */
    public function testThePreflightAnswersEveryRoleBecauseAskingIsNotCurating(): void
    {
        // A note that genuinely needs work, so `due: true` is the answer the
        // knowledge base actually has rather than a default a stub could hit.
        $this->kb->a->note('An undescribed note', 'No description, no tags.');

        $this->request('POST', '/mcp', $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'last_curated', 'arguments' => []],
        ]);

        self::assertNotTrue($this->jsonResponse()['result']['isError'] ?? false, $this->body());
        $payload = json_decode(
            $this->jsonResponse()['result']['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR
        );
        self::assertTrue($payload['due'], 'the agent gets the real preflight, not a stub');
        self::assertGreaterThan(0, $payload['queue_total']);
        // And still not the audit: who ran the last pass is Curator log data.
        self::assertArrayNotHasKey('last_run_by', $payload);
    }
}
