<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * Who is served what, now that seeing the curation queue is open to every role
 * and DOING curation is what the curator role gates (operator ruling
 * 2026-08-26).
 *
 * The defect this closes was a silence rather than a refusal. An agent-role
 * connection was served no curation verbs at all, so asked to curate it did
 * not know curation existed: it improvised — search, read, propose — and
 * produced curation-shaped work with no queue, no cooldown ladder, no flags
 * and no charter, which reaches the operator's inbox looking exactly like the
 * real thing. Silence read as permission.
 *
 * Three properties are worth a test rather than a comment, because each fails
 * quietly: the read verbs really are callable by an agent token, the write and
 * audit verbs really are not, and the tool list keeps the verbs a connection
 * cannot work without ABOVE the curation block — a client that truncates the
 * tail is not hypothetical here (2026-08-20, a twenty-entry list cut short and
 * a whole workflow reported unavailable).
 */
final class McpCurationAccessTest extends ApiTestCase
{
    /** @return array<string, mixed> the raw JSON-RPC result */
    private function call(string $tool, string $bearer, array $arguments = []): array
    {
        $this->request('POST', '/mcp', $bearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
        self::assertSame(200, $this->httpStatus(), "$tool did not answer: ".$this->body());

        return $this->jsonResponse()['result'];
    }

    /** @return string[] tool names, in the order this connection is served them */
    private function toolNames(string $bearer): array
    {
        $this->request('POST', '/mcp', $bearer, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        self::assertSame(200, $this->httpStatus(), $this->body());

        return array_column($this->jsonResponse()['result']['tools'], 'name');
    }

    private function instructions(string $bearer): string
    {
        $this->request('POST', '/mcp', $bearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18'],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        return (string) $this->jsonResponse()['result']['instructions'];
    }

    /** @return array<string, mixed> the tool's own payload, decoded */
    private function payload(string $tool, string $bearer, array $arguments = []): array
    {
        $result = $this->call($tool, $bearer, $arguments);
        self::assertNotTrue(
            $result['isError'] ?? false,
            "$tool refused: ".json_encode($result, JSON_THROW_ON_ERROR)
        );

        return json_decode($result['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);
    }

    public function testAnAgentTokenCanReadTheQueueItIsAskedToWorkFrom(): void
    {
        $note = $this->kb->a->note('An undescribed note', 'Body with no description and no tags.');
        $id = (int) $note->getId();

        // Not "did it answer" — WHAT it answered. A verb that returned an empty
        // success would pass a liveness check and leave the agent exactly where
        // the silence left it, with nothing to work from.
        $queue = $this->payload('curation_candidates', $this->kb->a->agentBearer);
        self::assertContains($id, array_column($queue['candidates'], 'note_id'),
            'the note the fixture made undescribed must be IN the queue the agent reads');

        $curated = $this->payload('last_curated', $this->kb->a->agentBearer, ['note_ids' => [$id]]);
        self::assertSame([$id], array_column($curated['notes'], 'note_id'));
        self::assertNull($curated['notes'][0]['last_at'], 'no pass has touched it, and null says so');

        $radius = $this->payload('blast_radius', $this->kb->a->agentBearer);
        self::assertArrayHasKey('notes', $radius);
    }

    public function testAnAgentIsGivenTheClockButNotTheAudit(): void
    {
        $note = $this->kb->a->note('A note a pass has read', 'Body.');
        $id = (int) $note->getId();
        // A real pass, so there IS an identity to withhold — the test would be
        // vacuous against an unworked note, where every field is null anyway.
        $this->call('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary', 'description' => 'A pass.', 'examined' => [$id],
        ]);

        $asCurator = $this->payload('last_curated', $this->kb->a->curatorBearer, ['note_ids' => [$id]])['notes'][0];
        self::assertSame('examined', $asCurator['last_action']);
        self::assertNotNull($asCurator['last_by'], 'the curator sees who did it');
        self::assertSame(1, $asCurator['entries']);

        $asAgent = $this->payload('last_curated', $this->kb->a->agentBearer, ['note_ids' => [$id]])['notes'][0];
        self::assertNotNull($asAgent['last_at'], 'the clock is the point of the verb and every role gets it');
        foreach (['last_action', 'last_by', 'entries'] as $field) {
            self::assertArrayNotHasKey($field, $asAgent,
                "$field is Curator log data — an audit an agent can read is an audit an agent "
                .'can learn to write around (codex H-5, and codex again on 2026-08-26)');
        }

        // The whole-KB shape carries the same identity, and the same rule.
        self::assertArrayHasKey('last_run_by', $this->payload('last_curated', $this->kb->a->curatorBearer));
        self::assertArrayNotHasKey('last_run_by', $this->payload('last_curated', $this->kb->a->agentBearer));
    }

    public function testTheHeaviestQueueVerbStaysCuratorOnly(): void
    {
        // `duplicate_candidates` runs a nearest-neighbour search per stored
        // embedding and its CTE twice, and no limiter covers database work. It
        // was opened with the other three for as long as it took a review to
        // price it.
        $result = $this->call('duplicate_candidates', $this->kb->a->agentBearer);
        self::assertTrue($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertNotContains('duplicate_candidates', $this->toolNames($this->kb->a->agentBearer));
    }

    public function testTheQueueAnAgentReadsIsTheSameQueueTheCuratorReads(): void
    {
        $this->kb->a->note('An undescribed note', 'Body with no description and no tags.');

        $asAgent = $this->payload('curation_candidates', $this->kb->a->agentBearer);
        // The queue LEASES what it hands out (C-9), so the second connection to
        // ask would otherwise be told — correctly — that the first is already
        // working the only note here. Cleared between the two calls because
        // what this test is about is the RANKING, and the leasing has tests of
        // its own next door.
        static::getContainer()->get('test.cache.curation_leases')->clear();
        $asCurator = $this->payload('curation_candidates', $this->kb->a->curatorBearer);

        // Nonempty first. Two empty lists are identical and prove nothing, and
        // an empty knowledge base is how this project has fooled itself before.
        self::assertNotEmpty($asCurator['candidates']);

        // Not a tidiness assertion: two rankings would mean the operator's
        // browser, their curator and their other assistant disagree about what
        // needs work, and nobody could tell which was right.
        self::assertSame(
            array_column($asCurator['candidates'], 'note_id'),
            array_column($asAgent['candidates'], 'note_id'),
            'the role changes what a connection may DO, never what the queue says'
        );
    }

    public function testWritingAndTheAuditStayCuratorOnly(): void
    {
        $flagged = $this->kb->a->note('A flagged note', 'Body.');
        static::getContainer()->get(\App\Service\CurationFlags::class)
            ->raise($flagged, $this->kb->a->account()->getName(), 'Look at this.');

        $refusals = [
            'log' => ['action' => 'observation', 'description' => 'Something.'],
            'log_recent' => [],
            'resolve_curation_flag' => ['note_id' => $flagged->getId(), 'resolution' => 'Dealt with.'],
        ];
        foreach ($refusals as $verb => $args) {
            $result = $this->call($verb, $this->kb->a->agentBearer, $args);
            self::assertTrue(
                $result['isError'] ?? false,
                "$verb answered an agent token — recording runs, reading the audit and answering "
                .'the operator\'s flags are the curator role.'
            );
        }

        // A refusal that has already written is not a refusal. The flag must
        // still be open, and the log must not have gained the agent's row.
        $this->in($this->kb->a);
        $conn = $this->em->getConnection();
        self::assertSame(1, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM curation_flags WHERE note_id = ? AND resolved_at IS NULL',
            [$flagged->getId()]
        ), 'the flag is still waiting for a curator');
        self::assertSame(0, (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM curator_log WHERE action IN ('observation', 'flag-resolved')"
        ), 'nothing the agent was refused reached the log anyway');
        // The flag-raised row IS there, which is what makes the line above a
        // real check rather than a query against an empty table.
        self::assertSame(1, (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM curator_log WHERE action = 'flag-raised'"
        ));
    }

    public function testAnAgentIsToldWhatItMayAndMayNotDoBeforeItIsAsked(): void
    {
        $asAgent = $this->instructions($this->kb->a->agentBearer);
        $asCurator = $this->instructions($this->kb->a->curatorBearer);

        self::assertStringContainsString('curation_candidates', $asAgent);
        self::assertStringContainsString('Settings › Assistants › Note maintenance', $asAgent,
            'the boundary is only useful if it names where the owner changes it');

        // The property, rather than the wording: this paragraph DEPENDS on the
        // role. A constant containing the same phrases would satisfy the two
        // assertions above and fail this one.
        self::assertNotSame($asCurator, $asAgent,
            'both roles were served the same instructions, so nothing is role-aware'
        );
        self::assertStringNotContainsString('Settings › Assistants › Note maintenance', $asCurator,
            'a connection that already holds the role is not sent to go and get it');
    }

    /**
     * Pull, never push (2026-08-25): the connect text names the backlog and
     * how to work it, and never tells an assistant to suggest the work.
     */
    public function testNoRoleIsToldToOfferTheBacklog(): void
    {
        foreach ([$this->kb->a->agentBearer, $this->kb->a->curatorBearer] as $bearer) {
            $text = $this->instructions($bearer);
            self::assertStringContainsString('needs_enrichment', $text);
            self::assertDoesNotMatchRegularExpression('/\\boffer\\b/i', $text, 'the connect text tells an assistant to offer maintenance');
        }
    }

    /**
     * Both roles are told the charter's name, and the name loads.
     *
     * It used to be said only when the knowledge base held a charter NOTE, and
     * only to a curator. Both halves were defects: a note is a frozen copy that
     * no shipped correction can reach — the operator's own still told agent-role
     * passes to browse instead of using the queue, months after that was fixed —
     * and an agent-role connection runs the same procedure, so leaving it
     * unnamed is what produced improvised passes.
     */
    public function testEveryRoleIsHandedTheCharterAndTheSlugItNamesLoads(): void
    {
        foreach (['curator' => $this->kb->a->curatorBearer, 'agent' => $this->kb->a->agentBearer] as $role => $bearer) {
            self::assertStringContainsString('get_skill("memex-curation")', $this->instructions($bearer),
                "a $role connection is not told the charter's name, and a pass that skipped the "
                .'instruction set looks exactly like a pass that read it');

            // The slug it names must actually LOAD. A hardcoded string in the
            // instructions would pass the assertion above and send the run to a
            // verb that answers "Unknown skill".
            $loaded = $this->payload('get_skill', $bearer, ['slug' => 'memex-curation']);
            self::assertStringContainsString('You are the Curator', $loaded['instructions'],
                "the slug named to a $role connection does not load the canon");
            self::assertStringContainsString('# Your brief', $loaded['instructions'],
                'the brief is served with the canon — without it the run has no budget, no '
                .'exclusions and nothing telling it to report back');
        }
    }

    public function testANoteTitledAlikeCannotTakeTheCharterSlug(): void
    {
        // Skills are slugged from their titles, and a collision used to give the
        // bare slug to the NEWEST note — so a later skill titled alike became
        // the charter, with the charter itself sitting unnamed at
        // `memex-curation-2` (codex, 2026-08-26). The slug is reserved now.
        $this->kb->a->note('memex — curation', 'Something else entirely.', ['skill'], 'Not the charter.');

        $loaded = $this->payload('get_skill', $this->kb->a->curatorBearer, ['slug' => 'memex-curation']);
        self::assertStringContainsString('You are the Curator', $loaded['instructions'],
            'a note claimed the charter\'s slug, so an agent following the pointer it was given '
            .'at connect time loads whatever that note says instead');
        self::assertStringNotContainsString('Something else entirely', $loaded['instructions']);

        // The note is still served, under a slug of its own. Two entries
        // sharing one slug is the actual failure the reservation prevents:
        // `list_skills` offers a name that resolves to only one of them, and
        // `resources/list` publishes the same URI twice.
        $slugs = array_column($this->payload('list_skills', $this->kb->a->curatorBearer)['skills'], 'slug');
        self::assertSame(array_unique($slugs), $slugs, 'two skills are served under one slug');
        self::assertContains('memex-curation-2', $slugs,
            'the colliding note must still be reachable, under a slug the charter does not own');
    }

    public function testEveryVerbDeclaredOpenIsActuallyServedToAnAgent(): void
    {
        // `OPEN_CURATION_TOOLS` is documentation in production — the tool-list
        // filter runs off `CURATOR_TOOLS` alone — so on its own it is a comment
        // that can go stale. This binds it: re-gate one of those verbs, or drop
        // it from the list an agent is served, and the constant stops being
        // true here rather than quietly in a docblock.
        $open = (new \ReflectionClass(\App\Service\McpServer::class))->getConstant('OPEN_CURATION_TOOLS');
        self::assertIsArray($open);
        self::assertNotEmpty($open);

        $names = $this->toolNames($this->kb->a->agentBearer);
        foreach ($open as $verb) {
            self::assertContains($verb, $names, "$verb is declared open but is not in an agent's tool list");
            $result = $this->call($verb, $this->kb->a->agentBearer);
            self::assertNotTrue($result['isError'] ?? false,
                "$verb is declared open but refuses an agent token: ".json_encode($result, JSON_THROW_ON_ERROR));
        }
    }

    public function testTheVerbsAConnectionCannotWorkWithoutComeBeforeTheCurationBlock(): void
    {
        foreach ([$this->kb->a->agentBearer, $this->kb->a->curatorBearer] as $bearer) {
            $names = $this->toolNames($bearer);
            $positions = array_flip($names);

            $essentials = ['needs_enrichment', 'list_skills', 'get_skill', 'list_tags', 'health'];
            $curation = array_values(array_intersect(
                ['log', 'log_recent', 'curation_candidates', 'resolve_curation_flag',
                    'duplicate_candidates', 'blast_radius', 'last_curated'],
                $names
            ));
            self::assertNotEmpty($curation);

            $lastEssential = max(array_map(static fn (string $n) => $positions[$n], $essentials));
            $firstCuration = min(array_map(static fn (string $n) => $positions[$n], $curation));

            self::assertLessThan($firstCuration, $lastEssential,
                'a client that drops the tail of the list must lose curation, not `health` and '
                .'`list_skills` — which is exactly what happened on 2026-08-20'
            );
        }
    }
}
