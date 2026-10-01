<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\AiProviders;
use App\Service\EnrichmentSettings;

/**
 * `/api/analyze` — the pre-save check, and until 2026-08-22 the only endpoint
 * in the app with **no tests at all** (audit M-19).
 *
 * Two reasons that mattered more than the count. Its duplicate check ran a
 * raw SQL tenancy predicate that was asserted nowhere: dropping it would have
 * served another account's note titles and summaries to anyone who typed a
 * draft, with the suite still green. And it is the endpoint where the spend
 * rules meet — its text calls are the account's own money, its
 * duplicate check is an embedding on the operator's, and the two must not be
 * confused for each other.
 *
 * Since 2026-08-24 the duplicate half lives in `App\Service\WriteHints`,
 * shared with every MCP write, so the isolation these tests defend covers
 * both surfaces at once.
 */
final class AnalyzeApiTest extends ApiTestCase
{
    /** A draft body long enough to be worth analysing. */
    private const DRAFT = 'A draft about the same subject as an existing note.';

    public function testTheDuplicateCheckNeverReachesAnotherTeamsNotes(): void
    {
        // Every note the stub embeds gets the SAME fixed vector, so every note
        // on the box is a near-duplicate of every other. That is what makes
        // this a real tenancy test: if B's request could reach A's vault, A's
        // note would be the top match for anything B typed.
        $this->kb->a->note('Alpha private note', 'A body only team A has.');
        $this->kb->b->note('Bravo own note', 'A body team B has.');

        $this->request('POST', '/api/analyze', $this->kb->b->agentBearer, ['body_md' => self::DRAFT]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $body = $this->jsonResponse();
        $titles = array_column($body['hints']['duplicates'], 'title');

        self::assertContains(
            'Bravo own note',
            $titles,
            'Team B saw none of its OWN notes — the fixture is what would be passing, not the predicate'
        );
        self::assertNotContains('Alpha private note', $titles);
        self::assertStringNotContainsString('Alpha', json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * A draft is compared with notes, so it is embedded as one: a model that
     * embeds queries apart from documents would put an exact copy of a note
     * past the duplicate cut-off.
     */
    public function testTheDraftIsEmbeddedAsADocument(): void
    {
        $this->request('POST', '/api/analyze', $this->kb->b->agentBearer, ['body_md' => self::DRAFT]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('document', $this->ml->lastBody('/create-embeddings')['purpose'] ?? null);
    }

    /** The list beside the count is per vault too, or it announces the neighbours. */
    public function testTheDuplicateListIsPerTeam(): void
    {
        $this->kb->a->note('Alpha one', 'Body.');
        $this->kb->a->note('Alpha two', 'Body.');
        $this->kb->a->note('Alpha three', 'Body.');
        $this->kb->b->note('Bravo one', 'Body.');

        $this->request('POST', '/api/analyze', $this->kb->b->agentBearer, ['body_md' => self::DRAFT]);

        self::assertCount(1, $this->jsonResponse()['hints']['duplicates']);
    }

    /**
     * With no provider configured, the answer is duplicates and nothing else —
     * and it says so, rather than looking broken. This is the spend invariant
     * at the surface a person actually touches: text is the team's decision,
     * and the box does not make it for them.
     */
    public function testWithNoProviderConfiguredItReturnsDuplicatesAndSaysWhy(): void
    {
        $this->kb->b->note('Bravo own note', 'Body.');

        $this->request('POST', '/api/analyze', $this->kb->b->agentBearer, ['body_md' => self::DRAFT]);

        $body = $this->jsonResponse();
        self::assertFalse($body['ai_enabled']);
        self::assertNull($body['summary']);
        self::assertSame(0, $this->ml->callCount('/summarize'), 'A summary was bought for a team that pays for none');
        self::assertNotSame([], $body['hints']['duplicates'], 'The duplicate check must work without any AI settings');
    }

    /** With the team's own key, the same request describes the draft as well. */
    public function testWithTheTeamsOwnKeyItAlsoDescribesTheDraft(): void
    {
        $settings = self::getContainer()->get(EnrichmentSettings::class);
        $this->in($this->kb->b);
        $key = $settings->saveKey(AiProviders::OPENAI, 'OpenAI', 'sk-valid-key')['credential'];
        $settings->save(enabled: true, credential: $key);

        $this->request('POST', '/api/analyze', $this->kb->b->agentBearer, ['body_md' => self::DRAFT]);

        $body = $this->jsonResponse();
        self::assertTrue($body['ai_enabled']);
        self::assertSame('A summary written by the ml-processor stub.', $body['summary']);
        self::assertSame('sk-valid-key', $this->ml->lastBody('/summarize')['api_key'] ?? null);
    }

    /**
     * A weak title is worth one completion; a real one is not. The rule is a
     * spend decision written as a private method with no test, and "no spaces
     * plus a filename marker" is easy to widen by accident.
     */
    public function testOnlyAFilenameShapedTitleBuysATitleCompletion(): void
    {
        $settings = self::getContainer()->get(EnrichmentSettings::class);
        $this->in($this->kb->b);
        $key = $settings->saveKey(AiProviders::OPENAI, 'OpenAI', 'sk-valid-key')['credential'];
        $settings->save(enabled: true, credential: $key);

        $this->request('POST', '/api/analyze', $this->kb->b->agentBearer, [
            'title' => 'A title somebody wrote on purpose',
            'body_md' => self::DRAFT,
        ]);
        self::assertSame(0, $this->ml->callCount('/suggest-title'));
        self::assertNull($this->jsonResponse()['suggested_title']);

        $this->ml->calls = [];
        $this->request('POST', '/api/analyze', $this->kb->b->agentBearer, [
            'title' => 'notes_export_2026_final.md',
            'body_md' => self::DRAFT,
        ]);
        self::assertSame(1, $this->ml->callCount('/suggest-title'));
    }

    /** An empty draft is refused before anything is bought. */
    public function testAnEmptyDraftBuysNothing(): void
    {
        $this->request('POST', '/api/analyze', $this->kb->b->agentBearer, ['body_md' => "   \n"]);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->ml->callCount());
    }

    /** And it is behind the door like everything else. */
    public function testItNeedsAuthentication(): void
    {
        $this->client->request(
            'POST',
            '/api/analyze',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['body_md' => self::DRAFT], JSON_THROW_ON_ERROR),
        );

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }
}
