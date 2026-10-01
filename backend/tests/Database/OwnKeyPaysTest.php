<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\AiCredential;
use App\Entity\VaultSettings;
use App\Service\AiProviders;
use App\Service\EmbeddingModel;
use App\Service\EmbeddingSpace;
use App\Service\EnrichmentSettings;
use App\Service\MlClient;
use App\Service\NoteWriter;
use App\Tests\Support\KbFixture;
use App\Tests\Support\Tenant;

/**
 * An own key changes who PAYS, and this is where that stops being a pointer in
 * a table and becomes the key on the wire.
 *
 * The settings gained a credential pointer for the embedding section on
 * 2026-09-08, and the edition's limits lifted that section's ceiling for any vault
 * holding one — correctly, since a bound memex invents has nothing behind it
 * when the bill is the team's. But the client still sent the operator's key,
 * so the pointer bought a team an exemption from a limit that was protecting
 * HIS account. The tests here are the ones that would have caught that:
 *
 *  - the key on the request is the team's when it has one, and absent when it
 *    has not;
 *  - the ledger records the payer the SERVICE reported, not the one the
 *    backend intended, and names a credential only when the team paid;
 *  - a key that will not decrypt falls back to this box's key AND brings the
 *    ceiling back with it, because those two have to move together;
 *  - and a key can only pay for what its vendor sells.
 *
 * The model is the vault's vector space, never the key's: a team's own key
 * buys the same model as everyone else's on this box.
 */
final class OwnKeyPaysTest extends DatabaseTestCase
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

    public function testAnEmbeddingGoesOutOnTheTeamsOwnKeyAndCarriesTheVaultsModel(): void
    {
        $this->onOpenAi();
        $credential = $this->addKey(AiProviders::OPENAI, 'sk-team-embeddings');
        $this->point('embed', $credential);

        $this->kb->note($this->writer, 'A note this team pays to embed');

        $body = $this->ml->lastBody('/create-embeddings');
        self::assertSame('sk-team-embeddings', $body['api_key'] ?? null, 'the team paid for its own key and the operator\'s was used');
        self::assertSame(EmbeddingModel::OpenAi->value, $body['model'] ?? null, 'a per-team embedding model is a second vector space');
        $row = $this->ledgerRow('embedding');
        self::assertSame('team', $row['payer']);
        self::assertSame($credential->getId(), (int) $row['credential_id'], 'the ledger did not name the key that paid');
    }

    public function testAnEmbeddingWithNoKeyOfItsOwnIsBilledToTheOperator(): void
    {
        $this->requireServerKey();
        $this->onOpenAi();
        $this->kb->note($this->writer, 'A note on this box\'s own key');

        $body = $this->ml->lastBody('/create-embeddings');
        self::assertArrayNotHasKey('api_key', $body, 'a team that brought no key sent one anyway');
        $row = $this->ledgerRow('embedding');
        self::assertSame('operator', $row['payer']);
        // A row that says `operator` must not also name a team credential, or a
        // reader has two answers to one question.
        self::assertNull($row['credential_id']);
    }

    /**
     * The failure this pair of properties exists for.
     *
     * APP_SECRET rotation makes every stored key undecryptable at once, for
     * every team, with no error anywhere. If the exemption read the POINTER, a
     * box in that state would spend the operator's money on every embedding of
     * every team that had ever brought a key, with no ceiling at all — and
     * nothing on any screen would say so.
     */
    public function testAKeyThatWillNotDecryptFallsBackToThisBoxAndBringsTheCeilingBack(): void
    {
        $this->requireServerKey();
        $this->onOpenAi();
        $embed = $this->addKey(AiProviders::OPENAI, 'sk-team-embeddings');
        $this->point('embed', $embed);

        self::assertTrue($this->ownEmbedKey(), 'the exemption was never on, so losing it proves nothing');

        $this->corrupt($embed);

        $this->kb->note($this->writer, 'A note embedded after the secret rotated');

        self::assertArrayNotHasKey('api_key', $this->ml->lastBody('/create-embeddings'));
        self::assertFalse($this->ownEmbedKey(), 'the ceiling stayed off while the operator was paying again');
    }

    /**
     * A key pays for what its vendor sells and for nothing else — refused one
     * level below any route, where a console command or the next controller
     * cannot skip it. Anthropic sells no embeddings.
     */
    public function testAKeyCannotBePointedAtASectionItsVendorDoesNotSell(): void
    {
        $credential = $this->addKey(AiProviders::ANTHROPIC, 'sk-ant-team-key');

        $this->expectException(\InvalidArgumentException::class);
        $this->point('embed', $credential);
    }

    /**
     * The ceiling comes back in the SAME request the key went away in.
     *
     * The allowance was a cached copy of an answer nothing could invalidate
     * (found by Codex, 2026-09-08): resolve it while the key reads, delete the
     * key, and the exemption outlived the key it was granted for — the
     * operator paying, uncapped, which is the one state the limiter exists to
     * prevent. Nothing here clears a cache, because in the request this
     * describes nothing would have.
     */
    public function testDeletingTheKeyTakesTheExemptionWithItWithinOneRequest(): void
    {
        $this->requireServerKey();
        $this->onOpenAi();
        $credential = $this->addKey(AiProviders::OPENAI, 'sk-team-embeddings');
        $this->point('embed', $credential);

        self::assertTrue($this->ownEmbedKey(), 'the exemption was never granted, so losing it proves nothing');

        $this->settings->deleteKey((int) $credential->getId());

        self::assertFalse($this->ownEmbedKey(), 'the ceiling stayed off after the key paying for it was gone');

        $this->kb->note($this->writer, 'A note embedded after the key went');
        self::assertArrayNotHasKey('api_key', $this->ml->lastBody('/create-embeddings'), 'a deleted key was still being sent');
    }

    /**
     * A vendor the provider table does not know cannot be pointed anywhere.
     *
     * ml-processor treats an unknown provider as OpenAI, so a typo in a fixture
     * would send somebody's key to OpenAI as an OpenAI key.
     */
    public function testAKeyFromAVendorThisBoxDoesNotKnowCannotBePointedAnywhere(): void
    {
        $credential = new AiCredential('a-vendor-nobody-added', 'Mystery', 'ciphertext', 'CAFE');
        $this->em->persist($credential);
        $this->em->flush();

        $this->expectException(\InvalidArgumentException::class);
        $this->point('text', $credential);
    }

    /** One vault's key is never on another vault's request. */
    public function testAKeyPaysOnlyForTheKnowledgeBaseThatBroughtIt(): void
    {
        $this->point('embed', $this->addKey(AiProviders::OPENAI, 'sk-team-embeddings'));

        (new Tenant(self::getContainer(), 'Other'))->enter();
        self::getContainer()->get(MlClient::class)->embedQuery('somebody else');

        self::assertArrayNotHasKey(
            'api_key',
            $this->ml->lastBody('/create-embeddings'),
            'one knowledge base\'s key was spent on another\'s embedding'
        );
    }

    // --- helpers ----------------------------------------------------------

    private function addKey(string $provider, string $key): AiCredential
    {
        $result = $this->settings->saveKey($provider, AiProviders::label($provider), $key);
        self::assertTrue($result['ok'], (string) $result['error']);

        return $result['credential'];
    }

    private function point(string $section, AiCredential $credential): void
    {
        $settings = $this->em->getRepository(VaultSettings::class)->current();
        match ($section) {
            'embed' => $settings->setEmbedCredential($credential),
            'text' => $settings->setCredential($credential),
        };
        $this->em->flush();
        $this->settings->forVault();
    }

    /** Make a stored key unreadable, which is what APP_SECRET rotation does. */
    private function corrupt(AiCredential $credential): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE ai_credentials SET api_key_enc = :junk WHERE id = :id',
            ['junk' => 'not-something-this-box-can-decrypt', 'id' => $credential->getId()]
        );
        // The identity map still holds the readable ciphertext, so without this
        // the resolver would decrypt the value the test just overwrote. The
        // resolver's own cache is scoped to a request and this test spans what
        // production would serve as several, so it is dropped for the same
        // reason.
        $this->em->refresh($credential);
        $this->settings->forget();
    }

    /** @return array<string, mixed> the last ledger row for one surface */
    private function ledgerRow(string $surface): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT * FROM provider_spend WHERE surface = :surface ORDER BY id DESC LIMIT 1',
            ['surface' => $surface]
        );
        self::assertIsArray($row, 'nothing was recorded for '.$surface.', so the payer cannot be asserted');

        return $row;
    }

    /**
     * Whether this vault's embeddings are on its own key, which is what lifts
     * the ceiling ({@see \App\Service\AccountLimits::spend()}). Deliberately
     * WITHOUT clearing a cache: the first version of this helper cleared one
     * before every read, and that is exactly what hid the defect Codex found:
     * the answer was a cached copy nothing could invalidate, so a test that
     * dropped the cache by hand asserted a property the product did not have.
     */
    private function ownEmbedKey(): bool
    {
        return $this->settings->embeddingCredentials()->isOwn();
    }

    private function requireServerKey(): void
    {
        if (!self::getContainer()->getParameter('memex.embedding_server_key')) {
            self::markTestSkipped('This edition holds no OpenAI key of its own to fall back on.');
        }
    }

    /** Who pays is a question only OpenAI's model asks; a local vector is bought from nobody. */
    private function onOpenAi(): void
    {
        $this->kb->tenant->enter();
        self::getContainer()->get(EmbeddingSpace::class)->switchTo(EmbeddingModel::OpenAi);
    }
}
