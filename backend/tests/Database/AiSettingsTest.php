<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;
use App\Service\AiProviders;
use App\Service\CredentialCipher;
use App\Service\EnrichmentSettings;
use App\Service\NoteWriter;
use App\Tests\Support\Embeddings;
use App\Tests\Support\KbFixture;

/**
 * Server-side enrichment is a per-vault setting, and this is the suite that says
 * so in code rather than in a paragraph.
 *
 * Until it existed, "memex hosts no AI of its own" was the product's position
 * while `NoteEnricher::enrich()` summarised anything that arrived without a
 * description, on the operator's key, for everybody. The properties worth
 * holding on to are all about money and whose it is:
 *
 *  - a knowledge base nobody configured describes nothing;
 *  - the switch does not take meaning-based search down with it;
 *  - the team's own provider, model and key are what travel;
 *  - **embeddings never carry a team key** — one model, one vector space, the
 *    operator's account, for every team (operator, 2026-08-20);
 *  - a key the provider rejected is never stored;
 *  - and a stored key is not readable from the database.
 */
final class AiSettingsTest extends DatabaseTestCase
{
    private KbFixture $kb;
    private NoteWriter $writer;
    private EnrichmentSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kb = new KbFixture(self::getContainer());
        $this->writer = self::getContainer()->get(NoteWriter::class);
        $this->settings = self::getContainer()->get(EnrichmentSettings::class);
    }

    public function testANewKnowledgeBaseDescribesNothingOnItsOwn(): void
    {
        $note = $this->write('An undescribed note');

        self::assertNull($note->getSummary(), 'A vault nobody configured must not be enriched by the server');
        self::assertSame(0, $this->ml->callCount('/summarize'));
    }

    public function testTheSwitchOffStillEmbedsSoSearchKeepsWorking(): void
    {
        $note = $this->write('Searchable without a summary');

        self::assertSame(1, $this->ml->callCount('/create-embeddings'));
        self::assertSame(
            1,
            (int) $this->em->getConnection()->fetchOne(
                'SELECT count(*) FROM note_embeddings WHERE note_id = :id',
                ['id' => $note->getId()]
            )
        );
    }

    public function testTheSwitchOnLetsTheServerDescribeANote(): void
    {
        $this->enable(AiProviders::OPENAI, 'sk-valid-key');

        $note = $this->write('A note the server describes');

        self::assertSame('A summary written by the ml-processor stub.', $note->getSummary());
        self::assertSame(Note::SUMMARY_BY_MEMEX, $note->getSummaryBy());
    }

    public function testASuppliedSummaryStillSkipsTheCallWithTheSwitchOn(): void
    {
        $this->enable(AiProviders::OPENAI, 'sk-valid-key');

        $note = $this->write('Described by its author', 'The assistant wrote this one.');

        self::assertSame('The assistant wrote this one.', $note->getSummary());
        self::assertSame(0, $this->ml->callCount('/summarize'), 'A written summary must not buy a second one');
    }

    public function testTheProviderKeyAndModelTravelWithTheTextCall(): void
    {
        $this->enable(AiProviders::ANTHROPIC, 'sk-ant-team-key', 'claude-haiku-4-5-20251001');

        $this->write('A note on our own account');

        $body = $this->ml->lastBody('/summarize');
        self::assertSame('anthropic', $body['provider'] ?? null);
        self::assertSame('sk-ant-team-key', $body['api_key'] ?? null);
        self::assertSame('claude-haiku-4-5-20251001', $body['model'] ?? null);
    }

    public function testEmbeddingsNeverCarryATeamKey(): void
    {
        // Anthropic sells no embeddings and the vault's embedding model is its
        // vector space, so nothing chosen for text travels with this call.
        $this->enable(AiProviders::ANTHROPIC, 'sk-ant-team-key');

        $this->write('A note that gets a vector');

        $body = $this->ml->lastBody('/create-embeddings');
        self::assertSame(['contents', 'model', 'purpose'], array_keys($body), 'the chunks of the note and the vault\'s vector space, and nothing else — no key');
        self::assertSame(Embeddings::model(self::getContainer())->value, $body['model']);
    }

    /**
     * The switch on over NO key does not run text on this box's account.
     *
     * This test used to assert the opposite property — that such a team's
     * summary went out with no key and no provider on the request, which is
     * this box paying for a team nobody sponsored. `PUT /api/settings/ai` with
     * `credential_id: 0` and no `enabled` reached exactly that state (found by
     * Codex, 2026-09-10), so the row saying so is not hypothetical.
     *
     * Resolved rather than trusted to the writers: the switch and the pointer
     * are two columns, and a route that clears one without the other is a route
     * the next batch can add again.
     */
    public function testTheSwitchOnWithNoKeyBuysNothing(): void
    {
        $this->settings->save(enabled: true);

        $this->write('A note on the house key');

        self::assertNull($this->ml->lastBody('/summarize'), 'a team with no key and no sponsor bought a summary');
    }

    public function testAKeyIsNotReadableFromTheDatabase(): void
    {
        $stored_credential = $this->addKey(AiProviders::OPENAI, 'sk-secret-value');

        $stored = (string) $this->em->getConnection()->fetchOne('SELECT api_key_enc FROM ai_credentials');

        self::assertNotSame('', $stored);
        self::assertStringNotContainsString('sk-secret-value', $stored);
        self::assertSame('alue', $stored_credential->getApiKeyHint());
    }

    public function testAKeyTheProviderRejectsIsNeverStored(): void
    {
        $result = $this->settings->saveKey(AiProviders::OPENAI, 'OpenAI', 'sk-invalid-key');

        self::assertFalse($result['ok']);
        self::assertSame('Incorrect API key provided.', $result['error']);
        self::assertNull($result['credential']);
        self::assertSame([], $this->settings->credentials());
    }

    public function testAStoredKeyIsMarkedVerified(): void
    {
        self::assertNotNull($this->addKey(AiProviders::GOOGLE, 'AIza-valid-key')->getVerifiedAt());
    }

    public function testAListingStampsAKeyThatWasNeverVerified(): void
    {
        // A key carried over from the single-key column has no verified_at and
        // read "unverified" for ever, however often it paid. Listing models IS
        // the verification call, so a successful listing is the proof.
        $id = (int) $this->addKey(AiProviders::OPENAI, 'sk-valid-key')->getId();
        $this->em->getConnection()->executeStatement('UPDATE ai_credentials SET verified_at = NULL');
        $this->em->clear();
        $this->kb->refresh();

        $settings = self::getContainer()->get(EnrichmentSettings::class);
        self::assertNull($settings->credential($id)?->getVerifiedAt());

        self::assertTrue($settings->models($id)['ok']);

        self::assertNotNull($settings->credential($id)?->getVerifiedAt());
    }

    public function testUsingATeamsOwnKeyRecordsWhen(): void
    {
        // The only answer to "did that summary use my key" that does not
        // involve logging the key.
        $this->enable(AiProviders::OPENAI, 'sk-valid-key');
        self::assertNull($this->credential()->getLastUsedAt());

        $this->write('A note that spends');

        self::assertNotNull($this->credential()->getLastUsedAt());
    }

    public function testTheModelListIsFilteredToModelsThatWriteText(): void
    {
        $key = $this->addKey(AiProviders::OPENAI, 'sk-valid-key');

        $ids = array_column($this->settings->models((int) $key->getId())['models'], 'id');

        self::assertSame(['gpt-4.1', 'gpt-4o-mini'], $ids, 'Embedding and realtime models cannot write a summary');
    }

    public function testGoogleModelsAreFilteredByWhatTheySupport(): void
    {
        $key = $this->addKey(AiProviders::GOOGLE, 'AIza-valid-key');

        $ids = array_column($this->settings->models((int) $key->getId())['models'], 'id');

        self::assertSame(['gemini-2.0-flash'], $ids);
    }

    public function testChangingProviderClearsTheModel(): void
    {
        // A model id belongs to the provider that listed it: sending
        // "gpt-4o-mini" to Anthropic would be nonsense it cannot refuse well.
        $this->enable(AiProviders::OPENAI, 'sk-valid-key', 'gpt-4.1');
        $anthropic = $this->addKey(AiProviders::ANTHROPIC, 'sk-ant-valid');
        $this->settings->save(enabled: null, credential: $anthropic);

        self::assertNull($this->settings->load()->getModel());
    }

    /**
     * The case named keys were added FOR: two keys, one provider, two
     * accounts. Switching between them keeps the model, because the model
     * belongs to the provider and the provider has not changed — and losing
     * the user's model choice on every switch would be a silent downgrade to
     * the provider's default.
     */
    public function testSwitchingBetweenTwoKeysOfOneProviderKeepsTheModel(): void
    {
        $this->enable(AiProviders::OPENAI, 'sk-personal-key', 'gpt-4.1');
        $work = $this->addKey(AiProviders::OPENAI, 'sk-work-key', 'Work account');

        $this->settings->save(enabled: null, credential: $work);

        $settings = $this->settings->load();
        self::assertSame('gpt-4.1', $settings->getModel());
        self::assertSame($work->getId(), $settings->getCredential()?->getId());
    }

    public function testATeamMayHoldSeveralKeysForOneProvider(): void
    {
        $this->addKey(AiProviders::OPENAI, 'sk-personal-key', 'Personal');
        $this->addKey(AiProviders::OPENAI, 'sk-work-key', 'Work');

        $names = array_map(
            static fn (\App\Entity\AiCredential $c) => $c->getName(),
            $this->settings->credentials(),
        );
        self::assertSame(['Personal', 'Work'], $names);
    }

    /**
     * A process that outlives a request, MCP over stdio above all, resets its
     * services between two messages; the switch turned off in a browser
     * meanwhile has to hold for the next one.
     */
    public function testResettingServicesForgetsTheResolvedSwitch(): void
    {
        $this->enable(AiProviders::OPENAI, 'sk-valid-key');
        self::assertTrue($this->settings->forVault()->textEnabled);

        $this->em->getConnection()->executeStatement('UPDATE settings SET ai_enabled = 0');
        self::getContainer()->get('services_resetter')->reset();
        $this->kb->tenant->enter();

        self::assertFalse(self::getContainer()->get(EnrichmentSettings::class)->forVault()->textEnabled);
    }

    public function testDeletingTheKeyInUseSwitchesTheServerOff(): void
    {
        // Otherwise the team keeps the switch on with nothing behind it, and
        // every description quietly bills the operator.
        $this->enable(AiProviders::OPENAI, 'sk-valid-key');
        $id = (int) $this->credential()->getId();

        $this->settings->deleteKey($id);

        $settings = $this->settings->load();
        self::assertFalse($settings->isAiEnabled());
        self::assertNull($settings->getCredential());
        self::assertNull($settings->getProvider());

        // And the OTHER key is untouched: deleting one of a team's several
        // keys is not deleting its provider.
        self::assertSame([], $this->settings->credentials());
    }

    /**
     * **This test asserted the defect until 2026-08-22** (audit M-8), and the
     * rewrite is the point of the entry rather than a detail of it.
     *
     * It used to end `assertTrue($creds->textEnabled); assertNull($apiKey);`
     * and read as reassurance: a rotated APP_SECRET does not break anything,
     * the user just re-enters their key. What that combination actually means
     * is enabled-with-no-key, which ml-processor reads as "use the box's own"
     * — so the team's text calls moved onto the OPERATOR's account, running
     * the team's chosen model, uncapped, until somebody noticed a bill. The
     * suite was green over it because the suite had been told this was right.
     *
     * `decrypt()` still returns null rather than throwing, and that part was
     * always right: nothing should die because a key went unreadable. What
     * changed is what null MEANS — text off, an incident opened, and the
     * settings screen told, rather than a silent change of who pays.
     */
    public function testAKeyThatCannotBeDecryptedSwitchesTextOffRatherThanChangingWhoPays(): void
    {
        $this->enable(AiProviders::OPENAI, 'sk-valid-key');
        $this->em->getConnection()->executeStatement(
            'UPDATE ai_credentials SET api_key_enc = :enc',
            ['enc' => (new CredentialCipher('a different application secret'))->encrypt('sk-unreadable')]
        );
        $this->em->clear();
        $this->kb->refresh();

        $creds = self::getContainer()->get(EnrichmentSettings::class)->forVault();

        self::assertFalse($creds->textEnabled, 'An unreadable key left server-side text enabled');
        self::assertNull($creds->apiKey);
        self::assertArrayNotHasKey('api_key', $creds->payload());
    }

    public function testTheCipherRoundTripsAndRepeatsNothing(): void
    {
        $cipher = new CredentialCipher('test secret');

        $first = $cipher->encrypt('sk-value');
        $second = $cipher->encrypt('sk-value');

        self::assertNotSame($first, $second, 'A fresh nonce per call, so two identical keys do not look identical');
        self::assertSame('sk-value', $cipher->decrypt($first));
        self::assertNull($cipher->decrypt('not base64 ciphertext at all'));
    }

    /**
     * A STORED row saying "on, with no key" still buys nothing.
     *
     * The writer clears the switch with the key, so reaching this state through
     * `save()` is no longer possible — which is exactly why the resolver is
     * tested separately here. The rule is "text runs on the team's own key, or
     * on this box's only when sponsored", and a rule that lives only in the
     * writers is a rule the next route can forget: `PUT /api/settings/ai` with
     * `credential_id: 0` and no `enabled` already did (found by Codex,
     * 2026-09-10). Rows in that state also survive in any database written
     * before the fix.
     */
    public function testAStoredSwitchOverNoKeyIsRefusedByTheResolver(): void
    {
        $settings = $this->settings->load();
        $settings->setAiEnabled(true);
        $this->em->flush();
        $this->settings->forget();

        self::assertNull($settings->getCredential(), 'the state under test is a switch with no key');
        self::assertFalse(
            $this->settings->forVault()->textEnabled,
            'a row with the switch on and no key put text on this box\'s account',
        );

        $this->write('Nothing describes this');
        self::assertSame(0, $this->ml->callCount('/summarize'));
    }

    /**
     * Clearing the key through the ROUTE does not leave text on this box.
     *
     * `PUT /api/settings/ai` with `credential_id: 0` and no `enabled` passed
     * validation — which only refuses a missing key when `enabled` is
     * explicitly true — and the save left the switch standing over no key,
     * which resolves to this box's own configuration for a team nobody
     * sponsored (found by Codex, 2026-09-10).
     */
    public function testClearingTheKeyTakesTheTextSwitchWithIt(): void
    {
        $this->enable(AiProviders::OPENAI, 'sk-team-key');
        $this->write('Described on their own key');
        self::assertSame(1, $this->ml->callCount('/summarize'));

        // `enabled` deliberately absent: the shape the route accepted.
        $this->settings->save(enabled: null, credential: false);

        $resolved = $this->settings->forVault();
        self::assertFalse($resolved->textEnabled, 'text stayed on with no key, which is this box paying');

        $this->write('And now nothing describes it');
        self::assertSame(1, $this->ml->callCount('/summarize'), 'a summary was bought on this box\'s account');
    }

    /**
     * The section that asked takes the key, and no other.
     *
     * Adopting by PROVIDER put an OpenAI key added under Semantic search into
     * descriptions as well, which starts writing text on a screen that never
     * mentioned it (found by Codex, 2026-09-10).
     */
    public function testAKeyAddedForOneSectionIsNotAdoptedByAnother(): void
    {
        $result = $this->settings->saveKey(
            AiProviders::OPENAI,
            'Search only',
            'sk-search-only-key',
            EnrichmentSettings::SECTION_EMBED,
        );
        self::assertTrue($result['ok']);

        // Before the clear below, which detaches what the fixture holds.
        $this->write('A note nobody asked to have described');
        self::assertSame(0, $this->ml->callCount('/summarize'));

        $this->em->clear();
        $stored = $this->em->getRepository(\App\Entity\VaultSettings::class)->current();

        self::assertSame($result['credential']->getId(), $stored->getEmbedCredential()?->getId());
        self::assertNull($stored->getCredential(), 'the key was adopted by descriptions as well');
        self::assertFalse($stored->isAiEnabled(), 'adding a search key switched descriptions on');
    }

    private function enable(string $provider, string $key, ?string $model = null): void
    {
        $this->settings->save(
            enabled: true,
            credential: $this->addKey($provider, $key),
            model: $model,
        );
    }

    /** Store a key and hand back the row, which is what a role now points at. */
    private function addKey(string $provider, string $key, ?string $name = null): \App\Entity\AiCredential
    {
        $result = $this->settings->saveKey($provider, $name ?? AiProviders::label($provider), $key);
        self::assertTrue($result['ok'], (string) $result['error']);
        self::assertNotNull($result['credential']);

        return $result['credential'];
    }

    /** The role's key, re-read, for the assertions about last-used and verified. */
    private function credential(): \App\Entity\AiCredential
    {
        $credential = $this->settings->load()->getCredential();
        self::assertNotNull($credential);

        return $credential;
    }

    private function write(string $title, ?string $summary = null): Note
    {
        return $this->kb->note($this->writer, $title, 'Body text for '.$title.'.', [], $summary);
    }
}
