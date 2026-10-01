<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\AiCredential;
use App\Service\AiProviders;
use App\Service\EnrichmentSettings;
use App\Service\NoteWriter;
use App\Tests\Support\KbFixture;
use App\Tests\Support\ServiceHealthRecorder;
use App\Tests\Support\Tenant;

/**
 * A key the box can no longer read (audit M-8).
 *
 * The stored credential is encrypted with a key derived from `APP_SECRET`, so
 * rotating that — or restoring an older `.env.local` — makes every account's key
 * undecryptable at once, in one step, with no error anywhere.
 *
 * What happened then was the part worth a test. `decrypt()` returned null, and
 * the credentials handed to `MlClient` were still ENABLED, still named the
 * team's provider and model, and simply carried no `api_key` — which
 * ml-processor reads as "use the box's own key". A team that had deliberately
 * put its text calls on its own account was quietly moved onto the operator's,
 * running a model he never chose, uncapped. Nothing logged it. The first
 * symptom available to anybody was a bill.
 *
 * CLAUDE.md §Spend says server-side text never runs without the team's own
 * decision. A key nobody can read is not a decision; it is an unknown, and the
 * safe reading of an unknown here is off.
 */
final class UnreadableKeyTest extends DatabaseTestCase
{
    private EnrichmentSettings $settings;
    private NoteWriter $writer;
    private KbFixture $kb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = self::getContainer()->get(EnrichmentSettings::class);
        $this->writer = self::getContainer()->get(NoteWriter::class);
        $this->kb = new KbFixture(self::getContainer());

        $key = $this->settings->saveKey(AiProviders::OPENAI, 'OpenAI', 'sk-valid-key')['credential'];
        $this->settings->save(enabled: true, credential: $key);
    }

    /** The invariant: nobody else's money, ever, on an unknown. */
    public function testAnUnreadableKeyTurnsServerSideTextOffRatherThanBillingTheOperator(): void
    {
        $this->corruptTheStoredKey();

        $credentials = $this->settings->forVault();

        self::assertFalse($credentials->textEnabled, 'Text stayed on with a key nobody can read');
        self::assertFalse($credentials->isOwnAccount());
        self::assertSame([], array_intersect_key($credentials->payload(), ['api_key' => null]));
    }

    /**
     * And the consequence that matters at the wire: no text call is made, so
     * ml-processor is never handed a request it would answer on the operator's
     * account. Asserted at the call rather than at the credentials object,
     * because that is where the money is.
     */
    public function testNoTextCallIsMadeOnACorruptedKey(): void
    {
        $this->corruptTheStoredKey();

        $this->ml->calls = [];
        $this->kb->note($this->writer, 'A note written while the key is unreadable');

        self::assertSame(0, $this->ml->callCount('/summarize'), 'A summary was bought on the operator’s account');
        self::assertNotSame(
            0,
            $this->ml->callCount('/create-embeddings'),
            'Embeddings must be unaffected — they are the operator’s by decision, for every account'
        );
    }

    /**
     * It also has to be discoverable. This is a state nobody can diagnose from
     * the outside: the row is still listed, the switch still says on, and
     * summaries simply stop. So it is reported to the edition's
     * {@see \App\Service\ServiceHealth} rather than only failing quietly in a
     * safer direction.
     */
    public function testAnUnreadableKeyIsReported(): void
    {
        $this->corruptTheStoredKey();
        $this->settings->forVault();

        self::assertArrayHasKey('ai-credential-unreadable-'.$this->kb->tenant->handle(), $this->failing());
    }

    /**
     * An account that has NOT stored a key is not a broken box, and must not raise
     * one — an alerter that reports ordinary states stops being read, which is
     * the failure cluster B exists to avoid.
     *
     * **What this test used to say, and why it says less now.** It set a
     * PROVIDER with no key behind it and asserted text came out off. Since
     * 2026-08-23 a role names a KEY rather than a provider, so that state
     * cannot be written down at all: there is no field left in which to name
     * something that does not exist. What remains is a vault enabled with no
     * key chosen, which the API refuses to create and which behaves exactly as
     * it did before — nothing on the request, so whatever the box itself is
     * configured with pays. That is a self-hosted deployment mode rather than
     * an accident, and changing it is a spend decision (CLAUDE.md §Spend), not
     * something to fold into a schema change.
     */
    public function testATeamWithNoStoredKeyIsOffButNotAFault(): void
    {
        $bare = new Tenant(self::getContainer(), 'Bare');
        $bare->enter();
        $this->settings->save(enabled: true);

        $credentials = $this->settings->forVault();

        self::assertFalse($credentials->isOwnAccount(), 'Nothing of theirs is being spent');
        self::assertNull($credentials->credentialId);
        self::assertSame([], $this->failing(), 'An unconfigured account was reported as a fault');
    }

    /**
     * Putting the key back ends the failure — but the shape of "putting it
     * back" CHANGED on 2026-08-23 and this is where that shows.
     *
     * A key used to be replaced in place, so pasting it again was one action
     * and the role never noticed. Keys are immutable now (the operator's
     * instruction: a saved key can be deleted, not edited), so recovering from
     * an APP_SECRET rotation is add-a-new-key and point the role at it. That
     * is more steps in a rare disaster, and it is the trade for never having a
     * form where a blank password box silently means "leave it alone".
     */
    public function testPointingTheRoleAtAReadableKeyEndsTheFailure(): void
    {
        $this->corruptTheStoredKey();
        $this->settings->forVault();
        self::assertNotSame([], $this->failing());

        $fresh = $this->settings->saveKey(AiProviders::OPENAI, 'OpenAI again', 'sk-valid-key')['credential'];
        $this->settings->save(enabled: true, credential: $fresh);
        $this->forgetCachedCredentials();
        $credentials = $this->settings->forVault();

        self::assertTrue($credentials->textEnabled);
        self::assertTrue($credentials->isOwnAccount());

        self::assertSame([], $this->failing(), 'The failure stayed reported after the key was fixed');
    }

    /** The settings screen can say so — the only place a person would look. */
    public function testTheSettingsScreenIsToldTheKeyIsUnreadable(): void
    {
        $credential = $this->credential();
        self::assertTrue($this->settings->isReadable($credential));

        $this->corruptTheStoredKey();

        self::assertFalse($this->settings->isReadable($this->credential()));
    }

    /**
     * What `APP_SECRET` rotation does to a stored key, without rotating the
     * suite's own secret: the ciphertext stays well-formed base64 and stops
     * authenticating, which is exactly what a different derived key produces.
     */
    private function corruptTheStoredKey(): void
    {
        $credential = $this->credential();
        $raw = base64_decode($credential->getApiKeyEnc(), true);
        self::assertIsString($raw);
        // Flip a bit in the ciphertext body, past the iv and the GCM tag.
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0xFF);

        $this->em->getConnection()->executeStatement(
            'UPDATE ai_credentials SET api_key_enc = :enc WHERE id = :id',
            ['enc' => base64_encode($raw), 'id' => $credential->getId()]
        );
        // clear() detaches everything the fixture is holding — KbFixture::refresh()
        // is what it provides for exactly this.
        $this->em->clear();
        $this->kb->refresh();
        $this->forgetCachedCredentials();
    }

    /** The key the role is actually running on. */
    private function credential(): AiCredential
    {
        $credential = $this->settings->load()->getCredential();
        self::assertNotNull($credential);

        return $credential;
    }

    /**
     * `forVault()` memoises per request, and a test is one long request.
     * Forgetting is how a second HTTP request would see it.
     */
    private function forgetCachedCredentials(): void
    {
        $this->settings->forget();
    }

    /** @return array<string, array{detail: string, occurrences: int}> */
    private function failing(): array
    {
        return self::getContainer()->get(ServiceHealthRecorder::class)->failing;
    }
}
