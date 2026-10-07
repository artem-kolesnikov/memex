<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ProviderSpend;
use App\Storage\VaultContext;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Client for the ml-processor service (localhost:8201). Enrichment calls are
 * best-effort with capped timeouts: a down/slow ml-processor must degrade the
 * feature (no summary, keyword-only search), never break the request.
 *
 * Every TEXT method takes the vault's AiCredentials, and takes them without a
 * default. That is deliberate: server-side text generation is a per-account
 * setting (step 3c), and a call site that could omit the credentials is a call
 * site that can spend somebody else's money. The switch is enforced here, once,
 * rather than at each caller. The credentials also carry which provider and
 * model to use, and whose key pays.
 *
 * The EMBEDDING methods take nothing of the kind, and resolve the bound vault's
 * model and key themselves. The asymmetry is the point: text is a DECISION the
 * account made, so it is passed in and cannot be forgotten, while an embedding
 * happens whatever anyone decided. The model is the vault's
 * ({@see EmbeddingSpace}), because a second model is a second vector space. On
 * OpenAI's, an account that has brought a key for that section pays for its
 * own and everyone else is on this box's key, under the cap; the local model
 * is bought from nobody.
 */
class MlClient
{
    // Interactive-request budget for query embedding (mm2: fail fast into the
    // keyword fallback) vs. the more patient enrichment budget.
    private const QUERY_TIMEOUT = 8;
    private const ENRICH_TIMEOUT = 60;

    /**
     * The problem key for "ml-processor is not answering usefully".
     *
     * Deliberately the systemd UNIT name, and deliberately the same key the
     * `OnFailure=` hook records: the two observers see the same problem from
     * opposite ends, so they must not open two incidents about it.
     */
    public const SERVICE_KEY = 'service:memex-ml-processor';

    /**
     * A provider failing on this box's key, which ml-processor answers 502:
     * one key per budget, because a text call failing and an embedding call
     * succeeding must not close each other's incident. A provider failing on
     * an account's own key is that account's to fix and alerts nobody.
     */
    public const BOX_TEXT_KEY = 'dependency:openai-text';
    public const BOX_EMBEDDING_KEY = 'dependency:openai-embeddings';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $mlProcessorHost,
        private readonly ServiceHealth $health,
        private readonly SpendLedger $ledger,
        private readonly EnrichmentSettings $settings,
        private readonly VaultContext $context,
        private readonly EmbeddingSpace $space,
    ) {
    }

    /**
     * Write down what the call just cost, from the `usage` block the service
     * returns beside every answer.
     *
     * It lives here, and not at the five call sites, for the same reason the
     * text methods take {@see AiCredentials} with no default: this is the ONE
     * place in the backend that talks to a paid provider, so it is the one
     * place that cannot forget. A new method on this class that spends money
     * and does not call this is a visible omission; a new caller that forgets
     * to account for its own spending would not be.
     *
     * Skipped when no vault is bound, which only a test calling the client
     * directly does: there is nowhere to write the row.
     */
    private function account(string $surface, string $operation, array $data, ?AiCredentials $creds, bool $succeeded = true): void
    {
        if ($this->context->resolve() === null) {
            return;
        }
        $usage = $data['usage'] ?? null;
        $this->ledger->recordModelUse(
            $surface,
            $operation,
            is_array($usage) ? $usage : null,
            $creds?->credentialId,
            $succeeded,
        );
    }

    /**
     * A call that was BILLED and returned nothing usable.
     *
     * ml-processor answers 500 with the provider's usage in the body when it
     * has one — an Anthropic reply with no text part, an OpenAI completion that
     * stopped at its token cap, tag suggestions that were not valid JSON. The
     * provider charged for every one of those, so returning early without
     * reading the body loses the spend, which was the first version's defect
     * (found by Codex, 2026-08-27).
     *
     * Wrapped in its own try: a body that is not JSON at all — a crash page, a
     * proxy error — must not turn a degraded feature into an exception.
     */
    private function accountForFailure(ResponseInterface $response, string $surface, string $operation, ?AiCredentials $creds): void
    {
        try {
            $this->account($surface, $operation, $response->toArray(false), $creds, succeeded: false);
        } catch (\Throwable) {
            // Nothing reported, nothing recorded. There is no honest guess here.
        }
    }

    /**
     * An outage is not empty data, and until 2026-08-22 this class could not
     * tell the difference (audit B-2).
     *
     * Every method here catches `\Throwable` and returns null so that a slow
     * or dead ml-processor degrades the feature instead of breaking the
     * request. That rule is right and it stays. What was missing is that the
     * null said nothing: a note with no summary because the provider was down
     * looked exactly like a note with no summary because nobody wanted one,
     * and the difference was invisible everywhere — the log, the screen, the
     * enrichment backlog.
     *
     * So failures are now recorded, and the return value is unchanged. Two
     * things count as failure and only two:
     *
     * - the request threw (unreachable, timed out, connection reset)
     * - the response carried a non-success status: the service said no, or,
     *   as a 502, the provider behind it did ({@see providerFailed()})
     *
     * A 2xx whose body is unusable is NOT a failure here — an empty summary is
     * a legitimate answer, and treating data as an outage is how an alerter
     * starts crying wolf. The one exception is the embedding vector, whose
     * dimension is a contract rather than a judgement: the model's, or the row
     * cannot be stored, so a wrong one is the service being broken.
     */
    private function reportDown(string $what, string $detail): void
    {
        $this->health->serviceFailed(
            self::SERVICE_KEY,
            sprintf('%s: %s', $what, $detail),
        );
    }

    /** @param string|null $boxKey the box key's problem key; null when the account's own key paid */
    private function reportFailure(ResponseInterface $response, string $what, ?string $boxKey): void
    {
        $status = $response->getStatusCode();
        if ($status !== 502) {
            $this->reportDown($what, 'HTTP '.$status);

            return;
        }
        if ($boxKey !== null) {
            $this->health->serviceFailed($boxKey, sprintf('%s: %s', $what, $this->providerFailed($response)));
        }
    }

    /** What ml-processor said the provider did: its name and status, or what was wrong with its answer. */
    private function providerFailed(ResponseInterface $response): string
    {
        try {
            $error = $response->toArray(false)['error'] ?? null;
        } catch (\Throwable) {
            $error = null;
        }

        return is_string($error) && $error !== '' ? mb_substr($error, 0, 200) : 'HTTP 502';
    }

    private function reportWorking(?string $boxKey): void
    {
        $this->health->serviceRecovered(self::SERVICE_KEY);
        if ($boxKey !== null) {
            $this->health->serviceRecovered($boxKey);
        }
    }

    private static function textKey(AiCredentials $creds): ?string
    {
        return $creds->isOwnAccount() ? null : self::BOX_TEXT_KEY;
    }

    /** @return float[]|null the vault model's vector, or null to signal "use keyword fallback" */
    public function embedQuery(string $text): ?array
    {
        return $this->embed($text, self::QUERY_TIMEOUT, 'embed_query', 'query');
    }

    /**
     * A draft, to be compared with the notes it may duplicate: embedded as a
     * document, like them, on the short interactive budget.
     *
     * @return float[]|null
     */
    public function embedDraft(string $text): ?array
    {
        return $this->embed($text, self::QUERY_TIMEOUT, 'embed_query', 'document');
    }

    public const EMBED_BATCH = 256;

    /** @return float[]|null */
    public function embedContent(string $text): ?array
    {
        return $this->embed($text, self::ENRICH_TIMEOUT, 'embed_content', 'document');
    }

    /**
     * Several texts in one request, one vector each, in the order given — the
     * chunks of one note. Null if any of them did not come back as a vector,
     * so a caller never stores half a note.
     *
     * @param list<string> $texts
     * @return list<list<float>>|null
     */
    public function embedContents(array $texts): ?array
    {
        $vectors = [];
        // ml-processor refuses a batch past this size; a note with hundreds of
        // tiny sections is several requests rather than a refusal.
        foreach (array_chunk(array_values($texts), self::EMBED_BATCH) as $batch) {
            $answer = $this->embed($batch, self::ENRICH_TIMEOUT, 'embed_content', 'document');
            if ($answer === null) {
                return null;
            }
            $vectors = [...$vectors, ...array_values($answer)];
        }

        return $vectors;
    }

    public function summarize(string $content, AiCredentials $creds): ?string
    {
        if (!$creds->textEnabled) {
            return null;
        }
        try {
            $response = $this->httpClient->request('POST', $this->url('/api/v1/summarize'), [
                'json' => ['content' => $content] + $creds->payload(),
                'timeout' => self::ENRICH_TIMEOUT,
            ]);
            if ($response->getStatusCode() !== 201) {
                $this->reportFailure($response, 'summarize', self::textKey($creds));
                $this->accountForFailure($response, ProviderSpend::SURFACE_TEXT, 'summarize', $creds);

                return null;
            }
            $data = $response->toArray(false);
            $this->reportWorking(self::textKey($creds));
            $this->account(ProviderSpend::SURFACE_TEXT, 'summarize', $data, $creds);
            $summary = $data['summary'] ?? null;

            return is_string($summary) && $summary !== '' ? $summary : null;
        } catch (\Throwable $e) {
            $this->reportDown('summarize', $e->getMessage());

            return null;
        }
    }

    /**
     * A title for a note whose own title is missing or filename-shaped. Callers
     * decide WHETHER to ask (see CaptureController::isWeakTitle) — this is
     * billable output like any other completion.
     */
    public function suggestTitle(string $content, AiCredentials $creds): ?string
    {
        if (!$creds->textEnabled) {
            return null;
        }
        try {
            $response = $this->httpClient->request('POST', $this->url('/api/v1/suggest-title'), [
                'json' => ['content' => $content] + $creds->payload(),
                'timeout' => self::ENRICH_TIMEOUT,
            ]);
            if ($response->getStatusCode() !== 201) {
                $this->reportFailure($response, 'suggest-title', self::textKey($creds));
                $this->accountForFailure($response, ProviderSpend::SURFACE_TEXT, 'suggest_title', $creds);

                return null;
            }
            $data = $response->toArray(false);
            $this->account(ProviderSpend::SURFACE_TEXT, 'suggest_title', $data, $creds);
            $title = $data['title'] ?? null;
            $this->reportWorking(self::textKey($creds));

            return is_string($title) && trim($title) !== '' ? mb_substr(trim($title), 0, 500) : null;
        } catch (\Throwable $e) {
            $this->reportDown('suggest-title', $e->getMessage());

            return null;
        }
    }

    /**
     * @param array<array{id: int, name: string}> $tagVocab
     * @return array{tag_ids: int[], new_tags: string[]}
     */
    public function suggestTags(string $title, string $content, array $tagVocab, AiCredentials $creds): array
    {
        $empty = ['tag_ids' => [], 'new_tags' => []];
        if (!$creds->textEnabled) {
            return $empty;
        }
        try {
            $response = $this->httpClient->request('POST', $this->url('/api/v1/suggest-tags'), [
                'json' => ['title' => $title, 'content' => $content, 'tags' => $tagVocab] + $creds->payload(),
                'timeout' => self::ENRICH_TIMEOUT,
            ]);
            if ($response->getStatusCode() !== 201) {
                $this->reportFailure($response, 'suggest-tags', self::textKey($creds));
                $this->accountForFailure($response, ProviderSpend::SURFACE_TEXT, 'suggest_tags', $creds);

                return $empty;
            }
            $data = $response->toArray(false);
            $this->reportWorking(self::textKey($creds));
            $this->account(ProviderSpend::SURFACE_TEXT, 'suggest_tags', $data, $creds);

            return [
                'tag_ids' => array_values(array_filter((array) ($data['tag_ids'] ?? []), 'is_int')),
                'new_tags' => array_values(array_filter((array) ($data['new_tags'] ?? []), 'is_string')),
            ];
        } catch (\Throwable $e) {
            $this->reportDown('suggest-tags', $e->getMessage());

            return $empty;
        }
    }

    /**
     * The model and key are the bound vault's; with no vault bound, which only
     * a test calling the client directly does, they are OpenAI's on this box's
     * key and nothing is recorded.
     *
     * @param string|list<string> $text a batch answers with one vector per text, in order
     *
     * @return float[]|null
     */
    private function embed(string|array $text, int $timeout, string $operation, string $purpose): ?array
    {
        // Resolved here rather than at the caller for the reason the class note
        // gives about accounting: this is the one place in the backend that
        // spends on embeddings, and a caller that forgot would silently put the
        // bill on the operator — whose ceiling SpendLimits has already lifted
        // because the account holds a key.
        $bound = $this->context->resolve() !== null;
        $model = $bound ? $this->space->model() : EmbeddingModel::OpenAi;
        if ($bound && !$this->space->canEmbed()) {
            return null;
        }
        $paid = $bound && !$model->isLocal();
        $creds = $paid ? $this->settings->embeddingCredentials() : SectionCredentials::none();
        $json = (is_array($text) ? ['contents' => $text] : ['content' => $text]) + ['model' => $model->value, 'purpose' => $purpose];
        if ($creds->isOwn()) {
            $json['api_key'] = $creds->apiKey;
        }
        $boxKey = $model->isLocal() || $creds->isOwn() ? null : self::BOX_EMBEDDING_KEY;

        try {
            $response = $this->httpClient->request('POST', $this->url('/api/v1/create-embeddings'), [
                'json' => $json,
                'timeout' => $timeout,
            ]);
            if ($response->getStatusCode() !== 201) {
                $this->reportFailure($response, 'create-embeddings', $boxKey);
                if ($paid) {
                    $this->accountForFailure(
                        $response,
                        ProviderSpend::SURFACE_EMBEDDING,
                        $operation,
                        new AiCredentials(false, credentialId: $creds->credentialId),
                    );
                }

                return null;
            }
            $data = $response->toArray(false);
            // Recorded BEFORE the dimension check below, deliberately: a vector
            // of the wrong length was still bought and billed, and treating it
            // as an outage must not also make its cost disappear.
            if ($paid) {
                $usage = $data['usage'] ?? null;
                $this->ledger->recordModelUse(
                    ProviderSpend::SURFACE_EMBEDDING,
                    $operation,
                    is_array($usage) ? $usage : null,
                    $creds->credentialId,
                );
                $this->settings->markUsed($creds->credentialId);
            }
            $embeddings = $data['embeddings'] ?? null;
            $dimensions = $model->dimensions();
            $wellFormed = is_array($text)
                ? is_array($embeddings) && count($embeddings) === count($text)
                    && array_keys($embeddings) === range(0, count($text) - 1)
                    && count(array_filter($embeddings, static fn ($v): bool => is_array($v) && count($v) === $dimensions)) === count($text)
                : is_array($embeddings) && count($embeddings) === $dimensions;
            if (!$wellFormed) {
                // The one case where a 2xx counts as the service being broken:
                // the dimension is a contract, not a judgement. A vector of the
                // wrong length cannot be stored and cannot be compared, so
                // silently returning null here is how a whole embedding sweep
                // reports SUCCESS having embedded nothing.
                $this->reportDown('create-embeddings', sprintf(
                    'expected a %d-dim vector, got %s',
                    $dimensions,
                    is_array($embeddings) ? count($embeddings).' values' : get_debug_type($embeddings),
                ));

                return null;
            }
            $this->reportWorking($boxKey);

            return $embeddings;
        } catch (\Throwable $e) {
            $this->reportDown('create-embeddings', $e->getMessage());

            return null;
        }
    }

    private function url(string $path): string
    {
        return rtrim($this->mlProcessorHost, '/').$path;
    }
}
