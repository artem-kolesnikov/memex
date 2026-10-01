<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Talks to a provider's own API for the two things that are not inference:
 * checking that a key works, and asking which models it can reach.
 *
 * Both are free calls — listing models spends no tokens — which is what makes
 * "save and verify" honest rather than expensive. Inference still goes through
 * ml-processor; this is metadata only, and it lives in the backend because
 * that is where the key already is.
 *
 * The model list comes from the provider rather than from a list we maintain,
 * so it cannot go stale and cannot offer a model this particular account has
 * no access to. It is filtered to models that generate text: an embedding or
 * moderation model in a "which model writes my summaries" dropdown is a
 * support question waiting to happen.
 */
class ProviderGateway
{
    private const TIMEOUT = 15;
    private const ANTHROPIC_VERSION = '2023-06-01';

    /**
     * What the box's OpenAI key is checked against, and it is not a choice:
     * these are the model and width `services/ml-processor` actually sends and
     * every row of `note_embeddings` is stored in. A key verified against
     * anything else would be verified against an operation this installation
     * never performs.
     */
    private const EMBEDDING_MODEL = 'text-embedding-3-large';
    private const EMBEDDING_DIMENSIONS = 1536;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    )
    {
    }

    /**
     * @return array{ok: bool, error: ?string, models: array<int, array{id: string, label: string}>}
     *         ok=false carries the provider's own message, because "invalid key"
     *         and "no credit on this account" need different fixes and only the
     *         provider knows which one it is.
     */
    public function verify(string $provider, string $apiKey): array
    {
        try {
            [$url, $options] = $this->listRequest($provider, $apiKey);
            $response = $this->httpClient->request('GET', $url, $options + ['timeout' => self::TIMEOUT]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);

            if ($status !== 200) {
                return ['ok' => false, 'error' => $this->errorMessage($data, $status, $apiKey), 'models' => []];
            }

            return ['ok' => true, 'error' => null, 'models' => $this->models($provider, $data)];
        } catch (\Throwable $e) {
            $this->logger->warning('A provider could not be reached to check a key', ['provider' => $provider, 'detail' => self::withoutTheKey($e->getMessage(), $apiKey)]);

            return ['ok' => false, 'error' => 'Could not reach '.AiProviders::label($provider).'.', 'models' => []];
        }
    }

    /**
     * Can this OpenAI key actually buy an embedding?
     *
     * **Listing models does not answer that, and assuming it did was a defect**
     * (found in review, 2026-08-25). OpenAI keys carry per-endpoint
     * permissions: a key can have Models=read and Embeddings=none, and a
     * project can have `text-embedding-3-large` disabled or its billing
     * exhausted. Every one of those lists models happily and then fails on the
     * first note anybody saves.
     *
     * That gap matters here and nowhere else. An ACCOUNT's key is verified with
     * the free model list because an account's key writes TEXT and the model it
     * will use is chosen from that very list — the list IS the capability
     * check. The BOX's OpenAI key does exactly one thing, embeddings, for every
     * knowledge base on the server, and the failure is silent: enrichment
     * degrades by design, so a key that cannot embed produces no error
     * anywhere, just notes that are never findable by meaning.
     *
     * So this one is verified by doing the actual operation. **It costs money**
     * — one token of text-embedding-3-large, on the order of $0.0000001, and
     * the smallest request the API accepts. That is a real call and it is
     * named rather than hidden: the operator chose paid verification over
     * none when offered the trade.
     *
     * The model and dimensions are the ones `NoteEnricher` stores, not a cheaper
     * pair, because a key entitled to some other embedding model is not
     * entitled to the one this box's vectors are in.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function verifyOpenAiEmbeddings(string $apiKey): array
    {
        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/embeddings', [
                'headers' => ['Authorization' => 'Bearer '.$apiKey],
                'json' => ['model' => self::EMBEDDING_MODEL, 'input' => 'x', 'dimensions' => self::EMBEDDING_DIMENSIONS],
                'timeout' => self::TIMEOUT,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);

            if ($status !== 200) {
                return ['ok' => false, 'error' => $this->errorMessage($data, $status, $apiKey)];
            }

            // A 200 that did not return a vector of the right width is not a
            // working key for this box: every stored vector is this size, and
            // one of another size cannot be compared with them.
            $vector = $data['data'][0]['embedding'] ?? null;
            if (!is_array($vector) || count($vector) !== self::EMBEDDING_DIMENSIONS) {
                return ['ok' => false, 'error' => sprintf(
                    'That key reached OpenAI but did not return a %d-dimension vector, which is what every embedding in this installation is. Nothing was saved.',
                    self::EMBEDDING_DIMENSIONS,
                )];
            }

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach OpenAI: '.self::withoutTheKey($e->getMessage(), $apiKey)];
        }
    }

    /** @return array{string, array<string, mixed>} */
    private function listRequest(string $provider, string $apiKey): array
    {
        return match ($provider) {
            AiProviders::OPENAI => [
                'https://api.openai.com/v1/models',
                ['headers' => ['Authorization' => 'Bearer '.$apiKey]],
            ],
            AiProviders::ANTHROPIC => [
                'https://api.anthropic.com/v1/models?limit=100',
                ['headers' => ['x-api-key' => $apiKey, 'anthropic-version' => self::ANTHROPIC_VERSION]],
            ],
            AiProviders::GOOGLE => [
                'https://generativelanguage.googleapis.com/v1beta/models?pageSize=200',
                ['headers' => ['x-goog-api-key' => $apiKey]],
            ],
            default => throw new \InvalidArgumentException('Unknown provider '.$provider),
        };
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array{id: string, label: string}>
     */
    private function models(string $provider, array $data): array
    {
        $models = [];
        switch ($provider) {
            case AiProviders::OPENAI:
                foreach ($data['data'] ?? [] as $model) {
                    $id = (string) ($model['id'] ?? '');
                    // Chat-capable families only. The account also lists
                    // embedding, audio, image and moderation models, none of
                    // which can write a summary.
                    if (preg_match('/^(gpt-|o[1-9]($|-)|chatgpt-)/', $id) === 1
                        && !preg_match('/(audio|realtime|transcribe|tts|image|search|moderation)/', $id)) {
                        $models[] = ['id' => $id, 'label' => $id];
                    }
                }
                break;

            case AiProviders::ANTHROPIC:
                foreach ($data['data'] ?? [] as $model) {
                    $id = (string) ($model['id'] ?? '');
                    if ($id !== '') {
                        $models[] = ['id' => $id, 'label' => (string) ($model['display_name'] ?? $id)];
                    }
                }
                break;

            case AiProviders::GOOGLE:
                foreach ($data['models'] ?? [] as $model) {
                    // Google lists embedding and tuning models in the same
                    // response; the supported method is what separates them.
                    if (!in_array('generateContent', (array) ($model['supportedGenerationMethods'] ?? []), true)) {
                        continue;
                    }
                    $id = preg_replace('#^models/#', '', (string) ($model['name'] ?? '')) ?? '';
                    if ($id !== '') {
                        $models[] = ['id' => $id, 'label' => (string) ($model['displayName'] ?? $id)];
                    }
                }
                break;
        }

        usort($models, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return $models;
    }

    /**
     * The provider's own words about why it said no — with the key taken back
     * out of them.
     *
     * The message is quoted rather than replaced because "invalid key" and "no
     * credit on this account" need different fixes and only the provider knows
     * which one it is. But it is a string this application did not write, and
     * it goes straight to a browser: OpenAI redacts the key in its own message
     * (`sk-defin****************-key`), and nothing obliges it, a future
     * version of it, or an intermediary to keep doing so.
     *
     * So the submitted key is removed here before the message goes anywhere
     * (found in review, 2026-08-25). It costs nothing when the provider was
     * already careful, and it is the difference between a toast and a leaked
     * credential when it was not. The last four survive, matching every other
     * place a key is shown in this application.
     *
     * @param array<string, mixed> $data
     */
    private function errorMessage(array $data, int $status, string $apiKey): string
    {
        $message = $data['error']['message'] ?? $data['error']['status'] ?? null;
        if (is_string($message) && $message !== '') {
            return self::withoutTheKey($message, $apiKey);
        }

        return 'The provider refused the key (HTTP '.$status.').';
    }

    /**
     * Replace any occurrence of the submitted key with its last four.
     *
     * Deliberately not a pattern for "things that look like keys": the one
     * secret certain to be dangerous here is the one just submitted, and it is
     * in hand. A regex for `sk-...` would miss Google's shape and give false
     * confidence about the rest.
     */
    public static function withoutTheKey(string $message, string $apiKey): string
    {
        if (mb_strlen($apiKey) < 8) {
            return $message;
        }

        return str_replace($apiKey, '…'.mb_substr($apiKey, -4), $message);
    }
}
