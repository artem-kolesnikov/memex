<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\SkillServe;

/**
 * Canon plus brief, served as one skill, with the brief chosen per connection.
 *
 * The defect the desk replaces was silent in every direction. The charter was a
 * note, and a note is a frozen copy: the operator's own was taken before
 * 2026-08-26, never received one shipped correction, still told agent-role
 * passes to browse rather than use the queue, and never asked for `started_at`
 * — which is the whole of the attribution gap the digest reports. Nothing on
 * the screen said any of it, because from outside a stale charter and a current
 * one produce the same kind of run.
 *
 * What is worth a test rather than a comment is what fails without a sound:
 * a connection served the wrong brief, a brief edit that never reaches an
 * agent, a serve that goes unrecorded, and a token that can rewrite the
 * instructions it is about to be given.
 */
final class CurationDeskTest extends ApiTestCase
{
    private function charterFor(string $bearer): string
    {
        // The firewall is stateful, so a session left by an earlier bearer
        // request outranks the header on the next one and both connections
        // read as whichever went first.
        $this->client->getCookieJar()->clear();
        $this->request('POST', '/mcp', $bearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'get_skill', 'arguments' => ['slug' => 'memex-curation']],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        return json_decode(
            $this->jsonResponse()['result']['content'][0]['text'],
            true,
            flags: JSON_THROW_ON_ERROR,
        )['instructions'];
    }

    /** @return array<string, mixed> */
    private function instructions(): array
    {
        $this->sessionRequest('GET', '/api/curation/instructions');
        self::assertSame(200, $this->httpStatus(), $this->body());

        return $this->jsonResponse();
    }

    public function testTheBriefIsServedWithTheCanonAndCarriesItsSettings(): void
    {
        $charter = $this->charterFor($this->kb->a->curatorBearer);

        self::assertStringContainsString('You are the Curator', $charter, 'the canon is not served');
        self::assertStringContainsString('Notes per run: 20', $charter,
            'a run served no budget invents one, and the whole point of the brief is that the '
            .'budget is the operator\'s decision rather than the model\'s');
    }

    public function testEditingAProfileChangesWhatTheNextRunIsServed(): void
    {
        $profile = $this->createPreset('Careful', 7);
        $this->sessionRequest('PUT', '/api/curation/wiring/'.$this->kb->a->curatorTokenId, ['preset_id' => $profile['id']]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $fields = $profile['fields'];
        $fields['never_touch_tags'] = ['archive'];
        $this->sessionRequest('PUT', '/api/curation/presets/'.$profile['id'], ['fields' => $fields]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame($profile['version'] + 1, $this->jsonResponse()['version'],
            'the version must advance on a content change: a run records the version it was '
            .'served, and a profile that edits without advancing makes two different runs look alike');

        $charter = $this->charterFor($this->kb->a->curatorBearer);
        self::assertStringContainsString('Notes per run: 7', $charter);
        self::assertStringContainsString('`archive`', $charter,
            'an exclusion the operator set is not in what the agent is served, so the agent will '
            .'edit exactly the notes they said to leave alone');
    }

    public function testAConnectionIsServedTheBriefItWasPointedAt(): void
    {
        $created = $this->createPreset('Thorough', 55);

        $tokenId = $this->kb->a->curatorTokenId;
        $this->sessionRequest('PUT', '/api/curation/wiring/'.$tokenId, ['preset_id' => $created['id']]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        self::assertStringContainsString('Notes per run: 55', $this->charterFor($this->kb->a->curatorBearer));
        // The connection that was NOT pointed at it still gets Standard, which
        // is what makes an agent-against-agent comparison mean anything.
        self::assertStringContainsString('Notes per run: 20', $this->charterFor($this->kb->a->agentBearer));
    }

    /**
     * The fallback is found by its flag, not by being first.
     *
     * A vault whose first brief is one they created themselves has no Standard
     * row yet, and taking "the first preset" there makes that user brief the
     * fallback for every connection nobody pointed anywhere — silently, and
     * differently for each account depending on what they happened to name.
     */
    public function testAUserCreatedFirstProfileDoesNotBecomeTheFallback(): void
    {
        $this->createPreset('Aggressive', 90);

        self::assertStringContainsString('Notes per run: 20', $this->charterFor($this->kb->a->agentBearer));
        self::assertStringContainsString('# Your brief — Default', $this->charterFor($this->kb->a->agentBearer));
    }

    public function testDeletingABriefFallsItsConnectionsBackToStandard(): void
    {
        $created = $this->createPreset('Temporary', 44);
        $this->sessionRequest('PUT', '/api/curation/wiring/'.$this->kb->a->curatorTokenId, ['preset_id' => $created['id']]);
        self::assertSame(200, $this->httpStatus());

        $this->sessionRequest('DELETE', '/api/curation/presets/'.$created['id']);
        self::assertSame(200, $this->httpStatus(), $this->body());

        self::assertStringContainsString('Notes per run: 20', $this->charterFor($this->kb->a->curatorBearer),
            'an assistant whose profile was deleted must be served the default rather than nothing — a '
            .'charter with no brief has no budget and no exclusions');
    }

    public function testTheDefaultProfileIsLockedAgainstEveryKindOfChange(): void
    {
        $standard = $this->instructions()['presets'][0];
        self::assertTrue($standard['is_standard']);

        $this->sessionRequest('DELETE', '/api/curation/presets/'.$standard['id']);
        self::assertSame(400, $this->httpStatus(),
            'every assistant falls back to the default profile, so deleting it leaves them with none');

        $this->sessionRequest('PUT', '/api/curation/presets/'.$standard['id'], ['name' => 'Renamed']);
        self::assertSame(400, $this->httpStatus());

        $fields = $standard['fields'];
        $fields['notes_per_run'] = 3;
        $this->sessionRequest('PUT', '/api/curation/presets/'.$standard['id'], ['fields' => $fields]);
        self::assertSame(400, $this->httpStatus(),
            'the lock is server-side, not a disabled input: Standard is what an unedited account '
            .'is served and what every connection falls back to');

        self::assertStringContainsString('Notes per run: 20', $this->charterFor($this->kb->a->curatorBearer));
    }

    public function testThePreviewComposesTheSameBriefThatIsServed(): void
    {
        $this->sessionRequest('POST', '/api/curation/preview', [
            'name' => 'Draft',
            'fields' => ['notes_per_run' => 9, 'never_touch_tags' => ['archive']],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $preview = $this->jsonResponse();

        self::assertStringContainsString('memex-curation', $preview['short'],
            'the short prompt is a pointer at the skill; without the name it points nowhere');
        self::assertStringContainsString('started_at', $preview['short'],
            'the field no charter note ever asked for is the one the prompt must carry');
        self::assertStringContainsString('You are the Curator', $preview['full'],
            'the full prompt is for a harness that will not load a skill, so it must carry the '
            .'canon and not the brief alone');
        self::assertStringContainsString('Notes per run: 9', $preview['full']);
        self::assertStringContainsString('`archive`', $preview['full']);

        // Unsaved settings, so nothing served may have moved.
        self::assertStringContainsString('Notes per run: 20', $this->charterFor($this->kb->a->curatorBearer));
    }

    /**
     * A run with no serve behind it is a pass that never read the instructions,
     * and from the log alone it looks identical to one that did.
     */
    public function testLoadingTheCharterIsRecorded(): void
    {
        $before = $this->serveCount();
        $this->charterFor($this->kb->a->curatorBearer);
        self::assertSame($before + 1, $this->serveCount(), 'get_skill did not record the serve');

        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'prompts/get',
            'params' => ['name' => 'memex-curation'],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame($before + 2, $this->serveCount(),
            'the prompt path serves the same document and must be recorded the same way, or a '
            .'harness that loads skills as prompts reads as one that never loaded the charter');

        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read',
            'params' => ['uri' => 'memex://skill/memex-curation'],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame($before + 3, $this->serveCount(), 'the resource path is unrecorded');

        $this->in($this->kb->a);
        $paths = $this->em->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT path FROM skill_serves ORDER BY path',
        );
        self::assertSame(
            [SkillServe::PATH_PROMPT, SkillServe::PATH_RESOURCE, SkillServe::PATH_TOOL],
            $paths,
        );
    }

    public function testLoadingAnOrdinarySkillIsNotRecordedAsACharterLoad(): void
    {
        $this->kb->a->note('Recall', 'How to recall.', ['skill'], 'Recall guidance.');
        $before = $this->serveCount();

        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'get_skill', 'arguments' => ['slug' => 'recall']],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        self::assertSame($before + 1, $this->serveCount(),
            'every skill load is logged now, including an ordinary one');
        $this->in($this->kb->a);
        $slugs = $this->em->getConnection()->fetchFirstColumn(
            'SELECT slug FROM skill_serves ORDER BY id DESC LIMIT 1',
        );
        self::assertSame(['recall'], $slugs, 'an ordinary skill load must be logged under its own slug, not the charter\'s');
    }

    /**
     * Setup lists the connections that curate, not the account's directory.
     *
     * The Curator box in Settings → Memex MCP is the flag (operator,
     * 2026-08-27). The rest are counted rather than listed: an agent-role
     * connection may still curate with everything held for review, so saying
     * nothing at all about them would be false.
     */
    public function testWiringListsCuratorsAndCountsTheRest(): void
    {
        $this->sessionRequest('GET', '/api/curation/wiring');
        self::assertSame(200, $this->httpStatus(), $this->body());
        $wiring = $this->jsonResponse();

        $ids = array_column($wiring['connections'], 'id');
        self::assertContains($this->kb->a->curatorTokenId, $ids);
        self::assertNotContains($this->kb->a->agentTokenId, $ids,
            'an agent-role connection in this table is the directory the operator asked to be rid of');
        self::assertGreaterThan(0, $wiring['other_count'],
            'the connections that are not listed must still be counted, or the screen implies '
            .'the account has none');
    }

    /**
     * The shipped profile's name is not available to a user's own.
     *
     * `standard()` seeds by NAME, so a vault holding an ordinary profile called
     * `Default` made that seed collide on the unique index; the re-query for
     * `standard = true` then found nothing and every MCP path that lists or
     * loads a skill threw — and `prompts/list` and `resources/list` are outside
     * `callTool`'s catch, so it was a 500 rather than a tool error. Found by
     * Codex on review; it is guarded twice, here and by a partial index.
     */
    public function testAProfileCannotTakeTheShippedProfilesName(): void
    {
        foreach (['Default', 'default', '  DEFAULT  '] as $name) {
            $this->sessionRequest('POST', '/api/curation/presets', ['name' => $name, 'fields' => []]);
            self::assertSame(400, $this->httpStatus(), "“{$name}” was accepted: ".$this->body());
        }

        $mine = $this->createPreset('Careful', 8);
        $this->sessionRequest('PUT', '/api/curation/presets/'.$mine['id'], ['name' => 'Default']);
        self::assertSame(400, $this->httpStatus(), 'a rename reaches the same collision as a create');

        // The charter still loads, which is the thing the collision broke.
        self::assertStringContainsString('You are the Curator', $this->charterFor($this->kb->a->curatorBearer));
    }

    /**
     * The paths outside `callTool`'s try/catch, which is why the collision was
     * a 500 rather than a tool error.
     */
    public function testTheSkillPathsOutsideTheToolCatchStillAnswer(): void
    {
        foreach (['prompts/list', 'resources/list'] as $method) {
            $this->client->getCookieJar()->clear();
            $this->request('POST', '/mcp', $this->kb->a->curatorBearer, [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => $method,
            ]);
            self::assertSame(200, $this->httpStatus(), "$method: ".$this->body());
        }
    }

    /** A token that could rewrite its own instructions is not gated at all. */
    public function testATokenCannotReadOrWriteTheInstructions(): void
    {
        foreach ([$this->kb->a->curatorBearer, $this->kb->a->agentBearer] as $bearer) {
            $this->request('GET', '/api/curation/instructions', $bearer);
            self::assertSame(403, $this->httpStatus(), $this->body());

            $this->request('GET', '/api/curation/wiring', $bearer);
            self::assertSame(403, $this->httpStatus(), $this->body());
        }
    }

    public function testAnotherTeamsBriefIsNotReachable(): void
    {
        $this->loginAs($this->kb->b);
        $this->instructions();
        $theirs = $this->createPreset('B private brief', 7)['id'];

        $this->loginAs($this->kb->a);
        self::assertNotContains($theirs, array_column($this->instructions()['presets'], 'id'), 'Precondition: A holds no brief by that id');
        $this->sessionRequest('PUT', '/api/curation/presets/'.$theirs, ['fields' => ['notes_per_run' => 1]]);
        self::assertSame(404, $this->httpStatus(), $this->body());
        self::assertStringNotContainsString('B private brief', $this->body());

        $this->loginAs($this->kb->b);
        $kept = array_column($this->instructions()['presets'], null, 'id')[$theirs];
        self::assertSame(7, $kept['fields']['notes_per_run']);
    }

    /** @return array<string, mixed> */
    private function createPreset(string $name, int $notesPerRun): array
    {
        $this->sessionRequest('POST', '/api/curation/presets', [
            'name' => $name,
            'fields' => ['notes_per_run' => $notesPerRun],
        ]);
        self::assertSame(201, $this->httpStatus(), $this->body());

        return $this->jsonResponse();
    }

    private function serveCount(): int
    {
        $this->in($this->kb->a);

        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM skill_serves');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs($this->kb->a);
    }
}
