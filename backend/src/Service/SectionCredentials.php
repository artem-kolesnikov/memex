<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Whose key pays for the vault's embeddings.
 *
 * The counterpart of {@see AiCredentials} for the section that has no switch
 * and no model: an own key here changes who pays and nothing else. The model
 * is never carried, deliberately — it is the vault's ({@see EmbeddingSpace}).
 *
 * It is a value rather than a bare string because two things have to travel
 * together and both are load-bearing. `apiKey` is what goes to the service, and
 * `credentialId` is WHICH stored key it was, so "last used" marks the one that
 * actually paid — a vault may hold several keys for one provider, and they are
 * different accounts with different bills.
 *
 * **None means the operator's key pays, under the cap.** A key that cannot be
 * decrypted resolves to none for the same reason, which is what keeps
 * {@see SpendLimits} honest: the exemption follows the key that will actually
 * be sent, never the pointer on its own.
 */
final readonly class SectionCredentials
{
    public function __construct(
        public ?string $apiKey = null,
        public ?int $credentialId = null,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    /** True when the account's own key is what leaves for the vendor. */
    public function isOwn(): bool
    {
        return $this->apiKey !== null;
    }
}
