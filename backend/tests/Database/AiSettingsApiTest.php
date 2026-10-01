<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * The settings endpoints, driven through HTTP.
 *
 * Three properties here are not conveniences. A key is verified with its
 * provider before it is stored, so nothing on the screen is a key that has
 * never worked; the key is write-only across this boundary, so no response may
 * carry it back; and the whole surface is session-only, because a connected
 * assistant that could switch server-side enrichment on, or read a key out,
 * would be spending its user's money on its own authority.
 */
final class AiSettingsApiTest extends ApiTestCase
{
    public function testAFreshKnowledgeBaseReportsEverythingOff(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/settings/ai');

        self::assertSame(200, $this->httpStatus());
        $response = $this->jsonResponse();
        self::assertFalse($response['enabled']);
        self::assertNull($response['provider']);
        self::assertNull($response['model']);
        self::assertSame([], $response['keys']);
    }

    public function testTheCatalogueCarriesRealInstructionsForEveryProvider(): void
    {
        // The screen renders these; a wrong URL wastes somebody's afternoon.
        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/settings/ai');

        $providers = $this->jsonResponse()['providers'];
        self::assertSame(['openai', 'anthropic', 'google'], array_column($providers, 'id'));
        foreach ($providers as $provider) {
            self::assertStringStartsWith('https://', $provider['key_url']);
            self::assertNotSame('', $provider['key_steps']);
            self::assertNotSame('', $provider['default_model']);
        }
    }

    public function testAVerifiedKeyIsStoredAndListed(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/settings/ai/keys', ['provider' => 'openai', 'name' => 'OpenAI', 'api_key' => 'sk-a-valid-key']);

        self::assertSame(200, $this->httpStatus());
        $keys = $this->jsonResponse()['keys'];
        self::assertCount(1, $keys);
        self::assertSame('openai', $keys[0]['provider']);
        self::assertSame('OpenAI', $keys[0]['name']);
        self::assertSame('-key', $keys[0]['hint']);
        self::assertNotNull($keys[0]['verified_at']);
        self::assertNull($keys[0]['last_used_at']);
    }

    public function testAKeyTheProviderRejectsIsNotStored(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/settings/ai/keys', ['provider' => 'openai', 'name' => 'OpenAI', 'api_key' => 'sk-invalid-key']);

        self::assertSame(400, $this->httpStatus());
        // The provider's own words: a bad key and an account with no credit
        // need different fixes.
        self::assertSame('Incorrect API key provided.', $this->jsonResponse()['error']);

        $this->sessionRequest('GET', '/api/settings/ai');
        self::assertSame([], $this->jsonResponse()['keys']);
    }

    public function testAKeyForTheWrongProviderIsCaughtBeforeTheRoundTrip(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/settings/ai/keys', ['provider' => 'anthropic', 'name' => 'Anthropic', 'api_key' => 'sk-not-an-anthropic-key']);

        self::assertSame(400, $this->httpStatus());
        self::assertStringContainsString('does not look like that', $this->jsonResponse()['error']);
    }

    public function testNoResponseEverCarriesTheKey(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/settings/ai/keys', ['provider' => 'openai', 'name' => 'OpenAI', 'api_key' => 'sk-a-valid-key']);
        self::assertStringNotContainsString('sk-a-valid-key', $this->body());

        $this->sessionRequest('GET', '/api/settings/ai');
        self::assertStringNotContainsString('sk-a-valid-key', $this->body());
    }

    public function testTheModelListComesFromTheProvider(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/settings/ai/keys', ['provider' => 'openai', 'name' => 'OpenAI', 'api_key' => 'sk-a-valid-key']);
        $this->sessionRequest('GET', '/api/settings/ai/models/'.$this->keyId());

        self::assertSame(200, $this->httpStatus());
        $response = $this->jsonResponse();
        self::assertSame('gpt-4o-mini', $response['default_model']);
        self::assertSame(['gpt-4.1', 'gpt-4o-mini'], array_column($response['models'], 'id'));
    }

    public function testAskingForModelsOfAKeyThatDoesNotExistIsRefused(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/settings/ai/models/999999');

        self::assertSame(404, $this->httpStatus());
    }

    public function testARoleCannotPointAtAKeyThatDoesNotExist(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/settings/ai', ['credential_id' => 999999]);

        self::assertSame(400, $this->httpStatus());
    }

    /**
     * A key id is only a number, and every vault numbers its keys from 1, so
     * "no such key" has to mean "not one of YOURS" — otherwise the id is a way to point your automation at somebody
     * else's account and spend their money.
     */
    public function testARoleCannotPointAtAnotherTeamsKey(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/settings/ai/keys', ['provider' => 'openai', 'name' => 'OpenAI', 'api_key' => 'sk-team-a-only']);
        $theirs = $this->keyId();

        $this->loginAs($this->kb->b);
        $this->sessionRequest('PUT', '/api/settings/ai', ['credential_id' => $theirs]);

        self::assertSame(400, $this->httpStatus());
        $this->sessionRequest('GET', '/api/settings/ai');
        self::assertNull($this->jsonResponse()['credential_id']);
    }

    /** A team may hold two keys for one provider — two accounts, two bills. */
    public function testTwoKeysForOneProviderAreBothKeptAndTellApartByName(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/settings/ai/keys', ['provider' => 'openai', 'name' => 'Personal', 'api_key' => 'sk-personal-key']);
        $this->sessionRequest('POST', '/api/settings/ai/keys', ['provider' => 'openai', 'name' => 'Work', 'api_key' => 'sk-work-key']);

        $keys = $this->jsonResponse()['keys'];
        self::assertCount(2, $keys);
        self::assertSame(['Personal', 'Work'], array_column($keys, 'name'));
        self::assertNotSame($keys[0]['id'], $keys[1]['id']);
    }

    public function testAKeyWithNoNameIsRefused(): void
    {
        // Two keys for one provider are indistinguishable without it, so the
        // name is the thing that makes choosing between them a choice.
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/settings/ai/keys', ['provider' => 'openai', 'api_key' => 'sk-a-valid-key']);

        self::assertSame(400, $this->httpStatus());
    }

    /**
     * Nothing here has a clock any more, and saying so is a test.
     *
     * Enrichment lost its cadence on 2026-08-23 because it fires on save.
     * Curation kept one until 2026-08-27, for a runner that turned out not to
     * be coming, and it is the last reader of `schedule` and `hour` anywhere
     * in this payload.
     */
    public function testNoRoleReportsACadence(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/settings/ai');

        $response = $this->jsonResponse();
        self::assertArrayNotHasKey('schedule', $response, 'Enrichment fires on save; a cadence would be a control with one true answer');
        self::assertArrayNotHasKey('hour', $response);
    }

    public function testTheSwitchCannotBeTurnedOnWithNothingBehindIt(): void
    {
        // Otherwise it silently bills the operator for every description.
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/settings/ai', ['enabled' => true]);

        self::assertSame(400, $this->httpStatus());
    }

    public function testTheWholeFlowInOrder(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/settings/ai/keys', ['provider' => 'anthropic', 'name' => 'Anthropic', 'api_key' => 'sk-ant-valid-key']);
        $this->sessionRequest('PUT', '/api/settings/ai', ['credential_id' => $this->keyId()]);
        $this->sessionRequest('PUT', '/api/settings/ai', ['model' => 'claude-haiku-4-5-20251001']);
        $this->sessionRequest('PUT', '/api/settings/ai', ['enabled' => true]);

        self::assertSame(200, $this->httpStatus());
        $response = $this->jsonResponse();
        self::assertTrue($response['enabled']);
        self::assertSame('anthropic', $response['provider']);
        self::assertSame('claude-haiku-4-5-20251001', $response['model']);
    }

    public function testDeletingTheKeyInUseStopsTheServerWriting(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/settings/ai/keys', ['provider' => 'openai', 'name' => 'OpenAI', 'api_key' => 'sk-a-valid-key']);
        $id = $this->keyId();
        $this->sessionRequest('PUT', '/api/settings/ai', ['credential_id' => $id, 'enabled' => true]);

        $this->sessionRequest('DELETE', '/api/settings/ai/keys/'.$id);

        self::assertSame(200, $this->httpStatus());
        $response = $this->jsonResponse();
        self::assertSame([], $response['keys']);
        self::assertFalse($response['enabled']);
        self::assertNull($response['provider']);
    }

    public function testABearerTokenCannotReadOrChangeTheSettings(): void
    {
        $this->request('GET', '/api/settings/ai', $this->kb->a->agentBearer);
        self::assertSame(403, $this->httpStatus());

        $this->request('GET', '/api/settings/ai', $this->kb->a->curatorBearer);
        self::assertSame(403, $this->httpStatus(), 'Curator buys an exemption from review, never from this');

        $this->request('PUT', '/api/settings/ai', $this->kb->a->curatorBearer, ['enabled' => true]);
        self::assertSame(403, $this->httpStatus());
    }

    public function testABearerTokenCannotAddOrDeleteAKey(): void
    {
        $this->request('POST', '/api/settings/ai/keys', $this->kb->a->curatorBearer, ['provider' => 'openai', 'name' => 'OpenAI', 'api_key' => 'sk-a-valid-key']);
        self::assertSame(403, $this->httpStatus());

        $this->request('DELETE', '/api/settings/ai/keys/1', $this->kb->a->curatorBearer);
        self::assertSame(403, $this->httpStatus());
    }

    public function testOneTeamsKeysAreInvisibleToTheOther(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/settings/ai/keys', ['provider' => 'openai', 'name' => 'OpenAI', 'api_key' => 'sk-team-a-only']);

        $this->loginAs($this->kb->b);
        $this->sessionRequest('GET', '/api/settings/ai');

        $response = $this->jsonResponse();
        self::assertSame([], $response['keys'], 'Team B must see its own keys, not A');
        self::assertNull($response['provider']);
    }

    public function testOneTeamCannotDeleteTheOthersKey(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/settings/ai/keys', ['provider' => 'openai', 'name' => 'OpenAI', 'api_key' => 'sk-team-a-only']);
        $theirs = $this->keyId();

        $this->loginAs($this->kb->b);
        $this->sessionRequest('DELETE', '/api/settings/ai/keys/'.$theirs);
        self::assertSame(404, $this->httpStatus());

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/settings/ai');
        self::assertCount(1, $this->jsonResponse()['keys']);
    }

    /**
     * Curation is not an automation role, and the payload must not describe
     * one.
     *
     * **The ruling** (operator, 2026-08-25): curation is the user's agents,
     * full stop, run from their own assistant on their own schedule, and there
     * is **no server-side runner, ever**. Until 2026-08-27 this payload carried
     * a `curation` block whose every field answered "how should memex run
     * curation unattended on your key" — the question that ruling struck out —
     * and the settings screen rendered it as a switch, a provider, a model and
     * a cadence identical to enrichment's, under copy promising a runner.
     *
     * The block is the thing that made the screen able to lie, so its absence
     * is what gets asserted. Anything memex genuinely offers about curation
     * belongs on the Curation pane, which describes how curation is actually
     * run here.
     */
    public function testTheSettingsPayloadDescribesNoCurationRole(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/settings/ai');

        self::assertArrayNotHasKey('curation', $this->jsonResponse());
    }

    /**
     * And the endpoint that stored the struck-out decision is gone with it.
     *
     * Separate from the test above rather than folded into it: a payload can
     * stop reporting a thing while the write path that sets it survives, and
     * that combination is worse than either — an old tab would go on saving a
     * decision into a screen that no longer shows it.
     */
    public function testTheCurationRoleEndpointIsGone(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/settings/curation', ['enabled' => true]);

        self::assertSame(404, $this->httpStatus());
    }

    /** The id of the last key in the current response — what a role points at. */
    /**
     * The section that has no switch and no model. An own key here changes
     * who pays and nothing else, and the section route is where that decision
     * is written.
     */
    public function testAnOwnKeyCanBeChosenForEmbeddings(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/settings/ai/keys', ['provider' => 'openai', 'name' => 'Mine', 'api_key' => 'sk-a-valid-key']);
        $openAi = $this->keyId();

        $this->sessionRequest('PUT', '/api/settings/ai/sections', ['embed_credential_id' => $openAi]);

        self::assertSame(200, $this->httpStatus());
        $response = $this->jsonResponse();
        self::assertSame($openAi, $response['embed_credential_id']);
        // The ceiling exists to bound the OPERATOR's money; on a team's own key
        // the bill is theirs and memex sets no bound at all.
        self::assertCeilingLifted(true, $response);

        // 0 hands the section back, and the ceiling comes back with it.
        $this->sessionRequest('PUT', '/api/settings/ai/sections', ['embed_credential_id' => 0]);
        $response = $this->jsonResponse();
        self::assertNull($response['embed_credential_id']);
        self::assertCeilingLifted(false, $response);
    }

    /**
     * A key pays only for what its vendor sells, and the refusal has to be a
     * sentence: the entity refuses the wrong kind by storing null, which on a
     * form reads as "cleared" rather than as "that cannot work".
     */
    public function testASectionRefusesAKeyOfTheWrongKind(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/settings/ai/keys', ['provider' => 'anthropic', 'name' => 'Anthropic', 'api_key' => 'sk-ant-a-valid-key']);
        $anthropic = $this->keyId();

        // Anthropic sells no embeddings at all, which is why every stored
        // vector is one OpenAI model.
        $this->sessionRequest('PUT', '/api/settings/ai/sections', ['embed_credential_id' => $anthropic]);
        self::assertSame(400, $this->httpStatus());
        self::assertStringContainsString('OpenAI key', $this->jsonResponse()['error']);

        $this->sessionRequest('GET', '/api/settings/ai');
        $response = $this->jsonResponse();
        self::assertNull($response['embed_credential_id']);
        self::assertCeilingLifted(false, $response, 'a refused pointer still lifted the ceiling');
    }

    /**
     * An id memex cannot read is a refusal, never a decision about who pays.
     *
     * `(int) "invalid-key"` is 0, and 0 is this route's word for "hand the
     * section back to this box" — so a stale tab or a typo moved a team's
     * embeddings off the key it was paying with, onto the operator's, under the
     * cap, and answered 200 (Codex, 2026-09-09).
     */
    public function testAMalformedKeyIdIsRefusedRatherThanReadAsClearing(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/settings/ai/keys', ['provider' => 'openai', 'name' => 'Mine', 'api_key' => 'sk-a-valid-key']);
        $mine = $this->keyId();
        $this->sessionRequest('PUT', '/api/settings/ai/sections', ['embed_credential_id' => $mine]);
        self::assertSame($mine, $this->jsonResponse()['embed_credential_id']);

        foreach (['invalid-key', null, [], 0.5, '12abc', true] as $rubbish) {
            $this->sessionRequest('PUT', '/api/settings/ai/sections', ['embed_credential_id' => $rubbish]);
            self::assertSame(400, $this->httpStatus(), 'a malformed id was accepted: '.json_encode($rubbish));
        }

        $this->sessionRequest('GET', '/api/settings/ai');
        $response = $this->jsonResponse();
        self::assertSame($mine, $response['embed_credential_id'], 'a malformed id moved this team back onto the operator\'s key');
        self::assertCeilingLifted(true, $response, 'and brought the ceiling back with it');
    }

    /**
     * The same defect on the TEXT role, where it is worse.
     *
     * Clearing the text credential is exactly what lets sponsorship switch the
     * operator's key back on for a team that had chosen its own — so a stale
     * tab sending a malformed id would move a knowledge base off its own
     * account and onto his, silently, with a 200 (Codex, second round,
     * 2026-09-09). The first fix repaired the section route and left this one.
     */
    public function testAMalformedKeyIdCannotClearTheTextRoleEither(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/settings/ai/keys', ['provider' => 'openai', 'name' => 'Mine', 'api_key' => 'sk-a-valid-key']);
        $mine = $this->keyId();
        $this->sessionRequest('PUT', '/api/settings/ai', ['enabled' => true, 'credential_id' => $mine]);
        self::assertSame(200, $this->httpStatus());

        foreach (['invalid-key', null, [], 0.5, '12abc'] as $rubbish) {
            $this->sessionRequest('PUT', '/api/settings/ai', ['credential_id' => $rubbish]);
            self::assertSame(400, $this->httpStatus(), 'a malformed id was accepted: '.json_encode($rubbish));
        }

        $this->sessionRequest('GET', '/api/settings/ai');
        $response = $this->jsonResponse();
        self::assertSame($mine, $response['credential_id'], 'a malformed id cleared the key this team had chosen');
        self::assertTrue($response['enabled']);
    }

    /** A section pointed at another team's key would spend a stranger's account. */
    public function testASectionCannotPointAtAnotherTeamsKey(): void
    {
        $this->loginAs($this->kb->b);
        $this->sessionRequest('POST', '/api/settings/ai/keys', ['provider' => 'openai', 'name' => 'B', 'api_key' => 'sk-a-valid-key']);
        $theirs = $this->keyId();

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/settings/ai/sections', ['embed_credential_id' => $theirs]);

        self::assertSame(400, $this->httpStatus());
        self::assertSame('No such key', $this->jsonResponse()['error']);
    }

    /** Session-only, like every route on this controller: a connected assistant
     *  that could move a section onto its user's own account would be spending
     *  their money on its own authority. */
    public function testABearerTokenCannotChooseWhoPaysForASection(): void
    {
        foreach ([$this->kb->a->curatorBearer, $this->kb->a->agentBearer] as $bearer) {
            $this->request('PUT', '/api/settings/ai/sections', $bearer, ['embed_credential_id' => 0]);
            self::assertSame(403, $this->httpStatus());
        }
    }

    private function keyId(): int
    {
        $keys = $this->jsonResponse()['keys'];
        self::assertNotSame([], $keys, 'Expected a stored key');

        return (int) $keys[array_key_last($keys)]['id'];
    }

    /**
     * Where the edition caps anything, whether the account's own key lifted the
     * search section's ceiling. With nothing capped there is no ceiling to lift.
     *
     * @param array<string, mixed> $response
     */
    private static function assertCeilingLifted(bool $lifted, array $response, string $message = ''): void
    {
        if ($response['limits'] !== null) {
            self::assertSame($lifted, $response['limits']['own_embed_key'], $message);
        }
    }
}
