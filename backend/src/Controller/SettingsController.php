<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AiCredential;
use App\Service\AccountLimits;
use App\Service\AiProviders;
use App\Service\EmbeddingModel;
use App\Service\EmbeddingSpace;
use App\Service\EnrichmentBacklog;
use App\Service\EnrichmentSettings;
use App\Service\SpendLimiter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The vault's AI settings: whether the server writes text at all, which provider
 * and model do it, and the keys it holds.
 *
 * Session-only, like token management. These settings decide what gets spent
 * and on whose account, so a connected assistant must not be able to switch
 * server-side enrichment on, and above all must not be able to read back a key
 * the user typed into a browser.
 *
 * Keys are write-only across this boundary. `POST` takes one and verifies it
 * against the provider before storing it; every read returns the provider, the
 * last four characters, and the dates. There is no endpoint that reveals a key,
 * on purpose: a settings screen has to prove which key is in place, not what
 * it is.
 */
class SettingsController extends ApiController
{
    private const KEY_MAX = 400;

    public function __construct(
        private readonly EnrichmentSettings $settings,
        private readonly AccountLimits $limits,
        private readonly SpendLimiter $limiter,
        private readonly EmbeddingSpace $space,
    ) {
    }

    #[Route('/api/settings/ai', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Settings');

        return $this->json($this->present());
    }

    #[Route('/api/settings/ai', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Settings');

        $parsed = $this->parseRole($request->toArray(), $this->settings->load()->getCredential());
        if ($parsed instanceof JsonResponse) {
            return $parsed;
        }

        // No cadence: enrichment fires on SAVE (operator, 2026-08-23), so
        // there is no clock for this role to keep, and since 2026-08-27 there
        // is no second role that kept one either.
        $this->settings->save(
            $parsed['enabled'],
            $parsed['credential'],
            $parsed['model'],
        );

        return $this->json($this->present());
    }

    /**
     * Validates the enrichment role's payload.
     *
     * It was shared by two roles until 2026-08-27, so they could not drift
     * into accepting different things. Curation stopped being a role
     * (operator, 2026-08-25), and the cadence half of this parser went with
     * it: enrichment fires on SAVE and has had no clock since 2026-08-23, so
     * a `schedule` here would have no reader.
     *
     * @param array<string, mixed>  $data
     * @param AiCredential|null $stored what the role points at now, for the switch-on check
     *
     * @return array{enabled: ?bool, credential: AiCredential|false|null, model: ?string}|JsonResponse
     */
    private function parseRole(array $data, ?AiCredential $stored): array|JsonResponse
    {
        $enabled = null;
        if (array_key_exists('enabled', $data)) {
            if (!is_bool($data['enabled'])) {
                return $this->json($this->json400('enabled must be true or false'), Response::HTTP_BAD_REQUEST);
            }
            $enabled = $data['enabled'];
        }

        // Three states, and the middle one is why these are not plain nulls:
        // absent leaves the setting alone, an empty value CLEARS it, and a
        // value sets it.
        $credential = null;
        if (array_key_exists('credential_id', $data)) {
            $id = $this->credentialId($data['credential_id']);
            if ($id instanceof JsonResponse) {
                return $id;
            }
            if ($id === 0) {
                $credential = false;
            } else {
                $credential = $this->settings->credential($id);
                if ($credential === null) {
                    return $this->json($this->json400('No such key'), Response::HTTP_BAD_REQUEST);
                }
            }
        }

        $model = null;
        if (array_key_exists('model', $data)) {
            $model = trim((string) $data['model']);
            // The model comes from a list the provider returned, so anything
            // that is not one of its ids is a client bug or a stale tab.
            if ($model !== '' && mb_strlen($model) > 120) {
                return $this->json($this->json400('That is not a model id'), Response::HTTP_BAD_REQUEST);
            }
        }

        // Switching a role on with nothing to run it on would silently spend
        // the operator's money, which is the failure this whole screen exists
        // to prevent.
        $effective = $credential === null ? $stored : ($credential === false ? null : $credential);
        if ($enabled === true && $effective === null) {
            return $this->json(
                $this->json400('Choose the key this should run on before switching it on.'),
                Response::HTTP_BAD_REQUEST
            );
        }

        return [
            'enabled' => $enabled,
            'credential' => $credential,
            'model' => $model,
        ];
    }

    /**
     * Whose key pays for embeddings.
     *
     * Its own route rather than a widening of `PUT /api/settings/ai` because
     * the two are different shapes: text has a switch and a model, and this
     * has neither. An own key here changes who pays and nothing else; the
     * embedding model is changed on its own route below.
     *
     * **This route writes and does not spend**, and that is a rule rather than
     * a coincidence: the cap is lifted for a section on the account's own key,
     * so a request that repointed a section and then bought something would be
     * checked against one key and sent with another. `SpendOrderingGuardTest`
     * refuses that shape in CI.
     */
    #[Route('/api/settings/ai/sections', methods: ['PUT'])]
    public function updateSections(Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Settings');
        $data = $request->toArray();

        $embed = $this->parseSectionKey($data, 'embed_credential_id', AiProviders::OPENAI);
        if ($embed instanceof JsonResponse) {
            return $embed;
        }

        $this->settings->saveSections($embed);

        return $this->json($this->present());
    }

    /**
     * The vault's embedding model, from those this server offers. A change
     * empties every vector, and the sweep embeds each note again with the new
     * one. Writes only, like the route above.
     */
    #[Route('/api/settings/ai/search-model', methods: ['PUT'])]
    public function updateSearchModel(Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Settings');

        $model = EmbeddingModel::tryFrom((string) ($request->toArray()['model'] ?? ''));
        if ($model === null || !\in_array($model, $this->space->offered(), true)) {
            return $this->json($this->json400('This server does not offer that search model.'), Response::HTTP_BAD_REQUEST);
        }
        if (!$this->space->canEmbed($model)) {
            return $this->json($this->json400('Add an OpenAI key for search before switching to OpenAI.'), Response::HTTP_BAD_REQUEST);
        }
        if ($model !== $this->space->model()) {
            $this->space->switchTo($model);
        }

        return $this->json($this->present());
    }

    /**
     * One section pointer, in the same three states the text role uses: absent
     * leaves it alone, 0 hands the section back to this box's key, an id sets
     * it.
     *
     * The provider is checked here so the answer is a sentence on the form
     * rather than an exception from {@see \App\Entity\VaultSettings}, which
     * refuses a key of the wrong kind.
     *
     * @param array<string, mixed> $data
     *
     * @return AiCredential|false|null|JsonResponse
     */
    private function parseSectionKey(array $data, string $field, string $required): AiCredential|false|null|JsonResponse
    {
        if (!array_key_exists($field, $data)) {
            return null;
        }

        // **Validated before it is interpreted, and that order is the whole
        // of it** (Codex, 2026-09-09). `(int) "invalid-key"` is 0, and 0 is
        // this parser's word for "hand the section back to this box" — so a
        // stale tab or a typo moved a vault's embeddings off the key it was
        // paying with, onto the operator's, under the cap, and answered 200.
        // `null`, `[]` and `0.5` did the same. An id memex cannot read is a
        // refusal, never a decision about who pays.
        $id = $this->credentialId($data[$field]);
        if ($id instanceof JsonResponse) {
            return $id;
        }
        if ($id === 0) {
            return false;
        }

        $credential = $this->settings->credential($id);
        if ($credential === null) {
            return $this->json($this->json400('No such key'), Response::HTTP_BAD_REQUEST);
        }
        if ($credential->getProvider() !== $required) {
            // Not "wrong kind": Anthropic sells no embeddings at all, and the
            // only bought model is OpenAI's, so the reason is worth saying.
            return $this->json(
                $this->json400('Search that a key pays for runs on OpenAI\'s text-embedding-3-large, so this section needs an OpenAI key. A '.AiProviders::label($credential->getProvider()).' key cannot pay for it.'),
                Response::HTTP_BAD_REQUEST
            );
        }

        return $credential;
    }

    /**
     * One key id, validated BEFORE it is interpreted.
     *
     * `(int) "invalid-key"` is 0, and 0 is every three-state parser here's word
     * for "clear this" — so a stale tab or a typo moved a vault off the key it
     * was paying with and onto this box's, under the cap, and answered 200.
     * `null`, `[]` and `0.5` did the same. Worst on the TEXT role, where
     * clearing the credential is exactly what lets sponsorship switch the
     * operator's key back on (Codex, 2026-09-09).
     *
     * Shared by both parsers rather than written twice: the first fix repaired
     * the section route alone and left the sibling reading `credential_id`
     * with the identical defect.
     *
     * An all-digit STRING is accepted because a hand-written client sends one
     * and means it; anything else is a refusal.
     */
    private function credentialId(mixed $value): int|JsonResponse
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return $this->json($this->json400('That is not a key'), Response::HTTP_BAD_REQUEST);
        }

        return (int) $value;
    }

    /** Verify a key against its provider, then store it. Never one without the other. */
    #[Route('/api/settings/ai/keys', methods: ['POST'])]
    public function addKey(Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Settings');
        $data = $request->toArray();

        $provider = trim((string) ($data['provider'] ?? ''));
        if (!AiProviders::isKnown($provider)) {
            return $this->json($this->json400('Unknown provider'), Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 60) {
            return $this->json($this->json400('Give this key a name, up to 60 characters'), Response::HTTP_BAD_REQUEST);
        }

        $apiKey = trim((string) ($data['api_key'] ?? ''));
        if ($apiKey === '' || mb_strlen($apiKey) > self::KEY_MAX || preg_match('/[\s[:cntrl:]]/', $apiKey) === 1) {
            return $this->json($this->json400('That does not look like an API key'), Response::HTTP_BAD_REQUEST);
        }
        if (!AiProviders::looksLikeKey($provider, $apiKey)) {
            // Caught before the round trip because the fix is obvious and the
            // provider's own error for it is not.
            return $this->json(
                $this->json400('A '.AiProviders::label($provider).' key does not look like that. Check you copied it from the right console.'),
                Response::HTTP_BAD_REQUEST
            );
        }

        // Which section asked. Only that one starts using the key — a key added
        // under Semantic search must not switch descriptions on as well.
        $section = $data['section'] ?? null;
        if ($section !== null && !in_array($section, EnrichmentSettings::SECTIONS, true)) {
            return $this->json($this->json400('Unknown section'), Response::HTTP_BAD_REQUEST);
        }

        $result = $this->settings->saveKey($provider, $name, $apiKey, $section);
        if (!$result['ok']) {
            // The provider's own words: "invalid key" and "no credit on this
            // account" need different fixes, and only it knows which happened.
            return $this->json(['error' => $result['error']], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->present());
    }

    #[Route('/api/settings/ai/keys/{id}', methods: ['DELETE'], requirements: ['id' => '\\d+'])]
    public function deleteKey(int $id, Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Settings');
        if (!$this->settings->deleteKey($id)) {
            throw $this->createNotFoundException('No such key');
        }

        return $this->json($this->present());
    }

    /**
     * The models one stored key can reach, asked of the provider itself.
     *
     * Keyed by the KEY rather than by the provider: two keys for one provider
     * do not necessarily see the same models, because an organisation's key
     * sees what that organisation has been granted.
     */
    #[Route('/api/settings/ai/models/{id}', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function models(int $id, Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Settings');
        $credential = $this->settings->credential($id);
        if ($credential === null) {
            throw $this->createNotFoundException('No such key');
        }
        $result = $this->settings->models($id);
        if (!$result['ok']) {
            return $this->json(['error' => $result['error']], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'provider' => $credential->getProvider(),
            'default_model' => (string) AiProviders::defaultModel($credential->getProvider()),
            'models' => $result['models'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(): array
    {
        $settings = $this->settings->load();

        $allowance = $this->limits->spend();
        $included = $this->limits->includedText();

        return [
            // Whether the SERVER writes summaries, titles and tag suggestions.
            'enabled' => $settings->isAiEnabled(),
            'credential_id' => $settings->getCredential()?->getId(),
            'provider' => $settings->getProvider(),
            'model' => $settings->getModel(),
            // The section that has no switch and no model. Null means this
            // box's own key pays, under the cap below.
            'embed_credential_id' => $settings->getEmbedCredential()?->getId(),
            // The vault's embedding model, those this server offers, whether
            // it can embed right now, and how many notes it has embedded.
            'search' => [
                'model' => $this->space->model()->value,
                'models' => array_map(static fn (EmbeddingModel $m): string => $m->value, $this->space->offered()),
                'ready' => $this->space->canEmbed(),
            ] + $this->space->progress(),
            // Whether this server's key writes descriptions for this account,
            // shown because the pane would otherwise say descriptions are off
            // while they run, with the model that key writes them with.
            'included' => $included['on'],
            'included_model' => $included['model'],
            // What this knowledge base may make the box buy, so the caps
            // paragraph states the numbers in force rather than the seeded
            // ones. `own_*` is why a section shows no ceiling at all, and null
            // is nothing capped.
            'limits' => $allowance->isUnlimited() ? null : [
                'tier' => $allowance->tier,
                'embed_hourly' => $allowance->embedHourly,
                'embed_daily' => $allowance->embedDaily,
                'search_hourly' => $allowance->searchHourly,
                'search_daily' => $allowance->searchDaily,
                'analyze_hourly' => $allowance->analyzeHourly,
                'analyze_daily' => $allowance->analyzeDaily,
                'text_hourly' => $allowance->textHourly,
                'text_daily' => $allowance->textDaily,
                'own_text_key' => $allowance->ownTextKey,
                'own_embed_key' => $allowance->ownEmbedKey,
                // What is left of today, so each section can show a counter
                // rather than a rule. Null where no ceiling applies.
                'left_today' => $this->limiter->dailyLeft(),
            ],
            'keys' => array_map(fn (AiCredential $c) => [
                'id' => $c->getId(),
                'name' => $c->getName(),
                'provider' => $c->getProvider(),
                'provider_label' => AiProviders::label($c->getProvider()),
                // Enough to recognise the key, never enough to use it.
                'hint' => $c->getApiKeyHint(),
                'verified_at' => $c->getVerifiedAt()?->format(DATE_ATOM),
                'last_used_at' => $c->getLastUsedAt()?->format(DATE_ATOM),
                'created_at' => $c->getCreatedAt()->format(DATE_ATOM),
                // False when the stored key will not decrypt (audit M-8), which
                // in practice means APP_SECRET was rotated or `.env.local` came
                // back from a backup. Server-side text is off for this account
                // until the key is pasted again, and there is no other way to
                // find that out: the row is still here, the switch still says
                // on, and every summary silently stops happening.
                'readable' => $this->settings->isReadable($c),
            ], $this->settings->credentials()),
            'providers' => AiProviders::catalogue(),
        ];
    }
}
