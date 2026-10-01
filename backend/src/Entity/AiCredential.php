<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One AI provider key belonging to this vault.
 *
 * The key itself is ciphertext (App\Service\CredentialCipher) and never leaves
 * the server: the settings screen shows the name, the provider, the last four
 * characters, when it was added, when it was last used, and whether it has been
 * verified.
 *
 * `verifiedAt` is set by a real call to the provider's own API (listing models,
 * which costs nothing), so the green check on the screen means "this key
 * answered", not "this key looks well formed".
 *
 * ## Why there can be several per provider, and why each has a name
 *
 * Until 2026-08-23 a vault held at most ONE key per provider, and an automation
 * role pointed at a PROVIDER. That made "which key pays for this" a question
 * with only three possible answers, and the operator's instruction is the
 * opposite: keep as many keys as you like, and memex uses none of them until
 * you name one for a particular job.
 *
 * Which is why the name is not decoration. Two OpenAI keys — a personal
 * account and a company one, or a spending-capped project and an uncapped one
 * — are indistinguishable by provider and last-four, and choosing between them
 * from a dropdown that says "OpenAI" twice is choosing at random. The name is
 * what makes the choice a choice.
 *
 * A key is never edited, only deleted and re-added. There is nothing on it to
 * edit that is worth the risk: the secret cannot be shown, so an "edit" form
 * would be a name field beside a blank password box that silently means
 * "leave it alone" — which is exactly the shape that gets a key replaced by
 * accident.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_credentials')]
class AiCredential
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $provider;

    /** What the owner calls this key. The only thing telling two of a provider apart. */
    #[ORM\Column(length: 60)]
    private string $name;

    #[ORM\Column(name: 'api_key_enc', type: 'text')]
    private string $apiKeyEnc;

    #[ORM\Column(name: 'api_key_hint', length: 8)]
    private string $apiKeyHint;

    #[ORM\Column(name: 'verified_at', nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(name: 'last_used_at', nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $provider, string $name, string $apiKeyEnc, string $apiKeyHint)
    {
        $this->provider = $provider;
        $this->name = $name;
        $this->apiKeyEnc = $apiKeyEnc;
        $this->apiKeyHint = $apiKeyHint;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getApiKeyEnc(): string
    {
        return $this->apiKeyEnc;
    }

    public function getApiKeyHint(): string
    {
        return $this->apiKeyHint;
    }

    /** Replacing a key clears the verification: the new one has not answered yet. */
    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function markVerified(): void
    {
        $this->verifiedAt = new \DateTimeImmutable();
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
