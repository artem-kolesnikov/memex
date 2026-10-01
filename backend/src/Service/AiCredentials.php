<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What the vault's settings mean for one outbound text call.
 *
 * Every MlClient text method takes one of these, with no default value, so a
 * new call site cannot forget that server-side AI is a decision the user made.
 *
 * Embeddings take none. Their model is the vault's vector space
 * ({@see EmbeddingSpace}), changed only by embedding the whole knowledge base
 * again, never per call; on OpenAI's, only who pays varies
 * ({@see SectionCredentials}).
 */
final readonly class AiCredentials
{
    /**
     * @param bool        $textEnabled server-side summaries/titles/tags are allowed
     * @param string|null $provider    openai|anthropic|google; null = the box's own configuration
     * @param string|null $apiKey      the account's own key; null = the box's key pays
     * @param string|null $model       the account's model choice; null = the provider's default here
     * @param int|null    $credentialId WHICH stored key this is, so "last used" marks the one
     *                                  that actually paid. A vault may hold several for one
     *                                  provider, so the provider does not identify it.
     */
    public function __construct(
        public bool $textEnabled,
        public ?string $provider = null,
        public ?string $apiKey = null,
        public ?string $model = null,
        public ?int $credentialId = null,
    ) {
    }

    /** True when the account is paying for its own text calls. */
    public function isOwnAccount(): bool
    {
        return $this->apiKey !== null;
    }

    /** @return array<string, string> the credential fields to send with a request */
    public function payload(): array
    {
        $payload = [];
        if ($this->provider !== null) {
            $payload['provider'] = $this->provider;
        }
        if ($this->apiKey !== null) {
            $payload['api_key'] = $this->apiKey;
        }
        if ($this->model !== null) {
            $payload['model'] = $this->model;
        }

        return $payload;
    }
}
