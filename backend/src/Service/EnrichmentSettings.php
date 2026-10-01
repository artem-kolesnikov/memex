<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AiCredential;
use App\Entity\VaultSettings;
use App\Storage\VaultContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The vault's AI settings: the switch, which provider writes its text, which
 * of that provider's models, and the keys it has stored.
 *
 * Resolved credentials are cached per vault for the life of the request,
 * because one write asks more than once and there is no reason to decrypt
 * twice.
 *
 * A key is only ever stored after {@see ProviderGateway::verify()} has had an
 * answer from the provider, which is what the green check on the settings
 * screen means.
 */
class EnrichmentSettings implements ResetInterface
{
    /**
     * The two sections a key can be added FOR. A key belongs to the knowledge
     * base rather than to one of them, so this says which one asked — not which
     * ones the key could pay for.
     */
    public const SECTION_EMBED = 'embed';
    public const SECTION_TEXT = 'text';
    public const SECTIONS = [self::SECTION_EMBED, self::SECTION_TEXT];

    /** @var array<string, AiCredentials> */
    private array $cache = [];

    /** @var array<string, SectionCredentials> */
    private array $embedCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CredentialCipher $cipher,
        private readonly ProviderGateway $gateway,
        private readonly ServiceHealth $health,
        private readonly VaultContext $context,
        private readonly Owner $owner,
        private readonly AccountLimits $limits,
    ) {
    }

    public function forVault(): AiCredentials
    {
        $vault = $this->context->current()->key;
        if (isset($this->cache[$vault])) {
            return $this->cache[$vault];
        }

        $settings = $this->load();

        // WHICH KEY, not which provider — a vault may hold several for one
        // provider, and they are different accounts with different bills.
        $credential = $settings->getCredential();
        $provider = $credential?->getProvider();
        $key = null;
        if ($credential !== null) {
            $key = $this->cipher->decrypt($credential->getApiKeyEnc());

            // **A key we cannot read switches text OFF rather than falling
            // through to the operator's account** (audit M-8, 2026-08-22).
            //
            // Without this, an unreadable key produced credentials that were
            // ENABLED, named the vault's provider and model, and carried no
            // api_key — and ml-processor treats a missing key as "use the box's
            // own". So an account that had deliberately put its text calls on
            // its own key would have been quietly moved onto the operator's,
            // running a model he never chose, uncapped, and nothing anywhere
            // would have said so. The first symptom is a bill.
            //
            // The realistic cause is `APP_SECRET` rotation: the encryption key
            // is derived from it, so rotating it makes every stored credential
            // undecryptable at once — for every account on the box, in one
            // step, with no error. A restored-from-backup `.env.local` does it
            // too.
            //
            // This is the invariant in CLAUDE.md §Spend read strictly: server
            // side TEXT never runs without the account's own decision, and a
            // key nobody can read is not a decision — it is an unknown. Failing
            // closed costs an account its summaries until they paste the key
            // again, which is visible, reversible and theirs to fix.
            if ($key === null) {
                // A row exists and will not open: that is a broken box, not a
                // user who has not finished setting up. Deduplicated per vault
                // by the partial unique index, so a busy hour is one incident
                // and one alert rather than a storm.
                $this->health->serviceFailed(
                    $this->unreadableProblemKey(),
                    $this->owner->account()->getEmail().'\'s '.$provider.' key could not be decrypted — server-side text is off for them until it is re-entered. APP_SECRET rotation does this to every account at once.',
                );

                return $this->cache[$vault] = new AiCredentials(false, $provider, null, $settings->getModel(), $credential->getId());
            }

            // Readable again — clears the incident above, and says so once.
            $this->health->serviceRecovered($this->unreadableProblemKey());
        }

        // **Server-side text runs on the account's OWN key, or on this box's
        // key only when the account is sponsored.** There is no third state,
        // and the rule is applied here rather than trusted to the routes that
        // write it: the switch and the pointer are two columns, and a request
        // that cleared one without the other left the switch on over no key —
        // which resolves to this box, for an account nobody sponsored. `PUT
        // /api/settings/ai` with a credential of 0 and no `enabled` did exactly
        // that (found by Codex, 2026-09-10), and a writer fixed in one place is
        // a writer the next route can reintroduce.
        //
        // A TIER THE OPERATOR SWITCHED ON is the one place text runs without
        // the account having asked (operator, 2026-09-08; per tier since
        // 2026-09-29): he asked, for his money, from control — sponsorship is
        // that switch on the sponsored tier. An account that has chosen its
        // own key keeps it — the second clause only reaches an account with no
        // credential at all, where the box's own configuration pays.
        $included = $credential === null ? $this->limits->includedText() : ['on' => false, 'model' => null];
        $textEnabled = ($settings->isAiEnabled() && $credential !== null) || $included['on'];

        // **A MODEL WITHOUT A KEY IS NOT A CHOICE THE ACCOUNT MAY MAKE.** A
        // model belongs to the account that listed it, and with no key the
        // account is the OPERATOR's — so forwarding one here means his money
        // buying a model he never chose, and ml-processor honours the override.
        // The settings route already refuses to switch text ON with no key,
        // but it accepts a model on its own, and the tier then switches text
        // on behind it. With no key the model is the one the operator chose
        // for the tier. Cleared where the credentials are built rather than
        // only where they are written, because this is the last point before
        // the request leaves.
        $model = $credential === null ? ($included['on'] ? $included['model'] : null) : $settings->getModel();

        return $this->cache[$vault] = new AiCredentials(
            $textEnabled,
            $provider,
            $key,
            $model,
            $credential?->getId(),
        );
    }

    /**
     * Whose key buys this vault's EMBEDDINGS.
     *
     * Separate from {@see forVault()} because the sections are separate
     * decisions and always were: an account can pay for its own embeddings and
     * leave descriptions off. Null = the operator's key pays, under the cap,
     * which is what memex.tools does for anyone who has not said otherwise.
     *
     * The model is NOT chosen here and this returns none: it is the vault's
     * ({@see EmbeddingSpace}), and a key is used only while that is OpenAI's.
     * An own key changes who pays and nothing else.
     *
     * An unreadable key falls back to the operator's key rather than switching
     * embeddings off, and that is the opposite of what {@see forVault()} does
     * with text — deliberately. Failing closed on text protects a decision
     * about money; failing closed here would silently stop a knowledge base
     * being findable by meaning, which nobody would see until they searched.
     */
    public function embeddingCredentials(): SectionCredentials
    {
        // Cached for the life of the request like the text credentials above,
        // and for a second reason here: SpendLimits asks the same question to
        // decide whether the cap applies, immediately before the client asks it
        // to decide what to send. Two decrypts of one key per write is waste,
        // and worse, an answer that could differ between them.
        $vault = $this->context->current()->key;
        if (isset($this->embedCache[$vault])) {
            return $this->embedCache[$vault];
        }

        $credential = $this->load()->getEmbedCredential();
        if ($credential === null) {
            return $this->embedCache[$vault] = SectionCredentials::none();
        }

        $key = $this->cipher->decrypt($credential->getApiKeyEnc());

        // An unreadable key falls back to the operator's, and this is where
        // that costs him money rather than only a feature. Reported as an
        // incident for the same reason forVault() reports one — APP_SECRET
        // rotation does this to every account at once, silently, and the first
        // symptom is otherwise a bill.
        if ($key === null) {
            $this->health->serviceFailed(
                $this->unreadableProblemKey(),
                $this->owner->account()->getEmail().'\'s embedding key could not be decrypted — that section is back on this box\'s own key, and its cap is back with it. APP_SECRET rotation does this to every account at once.',
            );

            return $this->embedCache[$vault] = SectionCredentials::none();
        }

        return $this->embedCache[$vault] = new SectionCredentials($key, $credential->getId());
    }

    /**
     * Everything resolved, dropped after a write that could change it.
     *
     * Public because the cache is scoped to a REQUEST and two things outlive
     * one: a console command working a backlog, and the test suite, where a
     * single container serves what production would serve as separate requests.
     */
    public function forget(): void
    {
        $this->cache = [];
        $this->embedCache = [];
        if ($this->limits instanceof ResetInterface) {
            $this->limits->reset();
        }
    }

    public function reset(): void
    {
        $this->forget();
    }

    /**
     * Can this stored key still be decrypted?
     *
     * For the settings screen, which is otherwise unable to show the one state
     * a user cannot diagnose (audit M-8): the row is present, the switch says
     * on, and nothing happens. Returns no plaintext and nothing derived from
     * it — only whether the box can still read what it holds.
     */
    public function isReadable(AiCredential $credential): bool
    {
        return $this->cipher->decrypt($credential->getApiKeyEnc()) !== null;
    }

    public function load(): VaultSettings
    {
        return $this->em->getRepository(VaultSettings::class)->current();
    }

    /** @return AiCredential[] every key this vault holds, oldest first */
    public function credentials(): array
    {
        return $this->em->getRepository(AiCredential::class)->findBy([], ['id' => 'ASC']);
    }

    public function credential(int $credentialId): ?AiCredential
    {
        return $this->em->find(AiCredential::class, $credentialId);
    }

    /**
     * Applies an enrichment-role change and persists it.
     *
     * @param bool|null               $enabled    null = leave the switch alone
     * @param AiCredential|false|null $credential null = leave alone; false = no key chosen
     * @param string|null             $model      null = leave alone; '' = the provider's default
     */
    public function save(
        ?bool $enabled,
        AiCredential|false|null $credential = null,
        ?string $model = null,
    ): VaultSettings {
        $settings = $this->load();
        if ($enabled !== null) {
            $settings->setAiEnabled($enabled);
        }
        if ($credential !== null) {
            $chosen = $credential === false ? null : $credential;
            // A model belongs to the PROVIDER that listed it, so a change of
            // key can only keep the model when the provider is the same:
            // "gpt-4o-mini" means nothing to Anthropic and would be sent to it
            // verbatim. Two OpenAI keys are the case this distinction was
            // added for — switching between them must not silently discard the
            // model the user picked.
            if ($chosen?->getProvider() !== $settings->getProvider()) {
                $settings->setModel(null);
            }
            $settings->setCredential($chosen);
            // Clearing the key clears the switch, the same rule deleteKey()
            // follows: a role left on over no key is a role running on this
            // box's account. The resolver refuses that state anyway; storing it
            // would leave a screen saying descriptions are on while nothing
            // writes any.
            if ($chosen === null) {
                $settings->setAiEnabled(false);
            }
        }
        if ($model !== null) {
            // No key, no model — see forVault(). Storing one anyway leaves a
            // value that means nothing until a key arrives and something the
            // resolver has to defend against in the meantime.
            $settings->setModel($model === '' || $settings->getCredential() === null ? null : $model);
        }
        $this->em->flush();
        $this->forget();

        return $settings;
    }

    /**
     * Points the two sections that have no switch and no model at a key, or
     * back at the operator's.
     *
     * Separate from {@see save()} because these are separate decisions and
     * carry no model: an own key here changes who pays and nothing else. The
     * entity refuses a key of the wrong kind one level down, where a console
     * command cannot skip it.
     *
     * **Writes only.** Nothing in this method may go on to spend — see
     * `SpendOrderingGuardTest`: the ceiling is granted on the key that will be
     * sent, and a request that changes the key between the check and the call
     * runs uncapped on the operator's account.
     *
     * @param AiCredential|false|null $embed null = leave alone; false = the operator's key pays
     */
    public function saveSections(AiCredential|false|null $embed = null): VaultSettings
    {
        $settings = $this->load();
        if ($embed !== null) {
            $settings->setEmbedCredential($embed === false ? null : $embed);
        }
        $this->em->flush();
        $this->forget();

        return $settings;
    }

    /**
     * Verifies a key against its provider and stores it only if the provider
     * answered. Nothing unverified is ever written, so a key on the settings
     * screen is a key that worked at least once.
     *
     * @return array{ok: bool, error: ?string, credential: ?AiCredential}
     */
    public function saveKey(string $provider, string $name, string $apiKey, ?string $section = null): array
    {
        // Every provider lists its models for nothing, so verification is a
        // real call that buys nothing.
        $result = $this->gateway->verify($provider, $apiKey);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'], 'credential' => null];
        }

        // Always a new row. A key is never edited in place — see
        // AiCredential's note: the secret cannot be shown, so an edit form
        // is a name beside a blank password box that silently means "leave it
        // alone", which is the shape that replaces a key by accident.
        $credential = new AiCredential(
            $provider,
            $name,
            $this->cipher->encrypt($apiKey),
            mb_substr($apiKey, -4),
        );
        $credential->markVerified();

        $this->em->persist($credential);
        $this->em->flush();

        $this->adopt($credential, $section);
        $this->forget();

        return ['ok' => true, 'error' => null, 'credential' => $credential];
    }

    /**
     * The section the key was added FOR takes it, if it has none of its own.
     *
     * Each section offers one key and uses it (operator, 2026-09-10). A key
     * added and then not used is the state the "Paid by" selector used to
     * leave people in: the screen said a key was there and the bill still came
     * to this box.
     *
     * **The section is the caller's, not the provider's.** Keying off the
     * provider adopted an OpenAI key added under Semantic search into
     * descriptions as well, which starts writing text nobody asked for on a
     * screen that never mentioned it (found by Codex, 2026-09-10). A section
     * that already has a key is left alone either way, because replacing it
     * would be an unasked-for change of who pays.
     */
    private function adopt(AiCredential $credential, ?string $section): void
    {
        if ($section === null) {
            return;
        }

        $settings = $this->load();
        $provider = $credential->getProvider();

        // The only embedding model a key buys is OpenAI's, so no other text
        // vendor can pay for this section however good its key is.
        if ($section === self::SECTION_EMBED && $provider === AiProviders::OPENAI && $settings->getEmbedCredential() === null) {
            $settings->setEmbedCredential($credential);
        }
        if ($section === self::SECTION_TEXT && $settings->getCredential() === null) {
            $settings->setCredential($credential);
            // Switched ON as well as pointed, which is the one place this
            // writes more than a pointer. It is the account's own key and
            // therefore its own bill, and a description key that describes
            // nothing until a second switch is found is the same silence in
            // the other direction.
            $settings->setAiEnabled(true);
        }

        $this->em->flush();
    }

    public function deleteKey(int $credentialId): bool
    {
        $credential = $this->credential($credentialId);
        if ($credential === null) {
            return false;
        }

        // A role with no key would silently fall back to the operator's
        // account, which is the one thing this screen must never do quietly.
        // The foreign key nulls the pointer on its own; it cannot switch the
        // ROLE off, and a role left on over a deleted key is a state the user
        // cannot see is broken.
        $settings = $this->load();
        if ($settings->getCredential()?->getId() === $credentialId) {
            $settings->setCredential(null);
            $settings->setModel(null);
            $settings->setAiEnabled(false);
        }
        // The embedding section has no switch to turn off: null already means
        // "the operator's key, under the cap", which is the state an account
        // was in before it brought a key. Nulling it here rather than leaving
        // it to the foreign key is what keeps the entity in the identity map
        // honest — ON DELETE SET NULL fires in the database and Doctrine would
        // go on holding a pointer to a row that is gone.
        if ($settings->getEmbedCredential()?->getId() === $credentialId) {
            $settings->setEmbedCredential(null);
        }
        // The settings row goes first: it is what releases the reference, and
        // removing the key while a managed VaultSettings still points at it
        // makes Doctrine order the two statements the wrong way round.
        $this->em->flush();
        $this->em->remove($credential);
        $this->em->flush();
        $this->forget();

        return true;
    }

    /**
     * The models one stored key can actually reach, asked of the provider.
     *
     * By KEY rather than by provider, because two keys for one provider do not
     * necessarily reach the same models: an organisation's key sees what that
     * organisation has been granted.
     *
     * @return array{ok: bool, error: ?string, models: array<int, array{id: string, label: string}>}
     */
    public function models(int $credentialId): array
    {
        $credential = $this->credential($credentialId);
        if ($credential === null) {
            return ['ok' => false, 'error' => 'No such key.', 'models' => []];
        }
        $key = $this->cipher->decrypt($credential->getApiKeyEnc());
        if ($key === null) {
            return ['ok' => false, 'error' => 'This key can no longer be read on the server. Enter it again.', 'models' => []];
        }

        $result = $this->gateway->verify($credential->getProvider(), $key);
        // Listing models IS the verification call, so a successful listing is
        // proof the key still works.
        if ($result['ok']) {
            $credential->markVerified();
            $this->em->flush();
        }

        return $result;
    }

    /**
     * Records that one of the vault's own keys just paid for something. This
     * is the only answer to "did that summary use my key" that does not
     * involve logging the key, and it is why the settings screen can show a
     * last-used date.
     */
    public function markUsed(?int $credentialId): void
    {
        if ($credentialId === null) {
            return;
        }
        $credential = $this->credential($credentialId);
        if ($credential === null) {
            return;
        }
        $credential->markUsed();
        $this->em->flush();
    }

    private function unreadableProblemKey(): string
    {
        return 'ai-credential-unreadable-'.$this->context->current()->handle;
    }
}
