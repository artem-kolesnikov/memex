<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VaultSettingsRepository;
use App\Service\AiProviders;
use App\Service\EmbeddingModel;
use Doctrine\ORM\Mapping as ORM;

/**
 * The vault's one settings row: what its owner has decided about AI running
 * on the server, how memex writes notes for them, and how the interface looks
 * to them. The schema creates it with the vault.
 *
 * Three AI decisions live here, and they are separate on purpose:
 *
 *  - **aiEnabled** — whether the SERVER may write summaries, titles and tag
 *    suggestions. Default false: a new vault describes nothing on its own and
 *    waits for the owner's assistant. It does not gate embeddings.
 *  - **aiCredential** — WHICH KEY pays for that text; the provider is read off
 *    the key, since two keys of one provider are two different bills.
 *  - **embedCredential** — whose key buys embeddings. Null = the operator's,
 *    under the cap. Only an OpenAI key can, and it is used only while the
 *    vault's embedding model is OpenAI's.
 *
 * **embeddingModel** names the vector space every stored vector is in;
 * App\Service\EmbeddingSpace changes it, never a setter alone.
 */
#[ORM\Entity(repositoryClass: VaultSettingsRepository::class)]
#[ORM\Table(name: 'settings')]
class VaultSettings
{
    public const ID = 1;

    /** The sentinel `iconKey` meaning "the bytes are in this row". */
    public const ICON_UPLOAD = 'upload';

    /** Longest edge of the owner's stored avatar, and the ceiling on the re-encoded image. */
    public const ICON_SIZE = 500;
    public const MAX_ICON_BYTES = 512 * 1024;

    #[ORM\Id]
    #[ORM\Column]
    private int $id = self::ID;

    #[ORM\Column(name: 'ai_enabled', options: ['default' => false])]
    private bool $aiEnabled = false;

    #[ORM\ManyToOne(targetEntity: AiCredential::class)]
    #[ORM\JoinColumn(name: 'ai_credential_id', nullable: true, onDelete: 'SET NULL')]
    private ?AiCredential $aiCredential = null;

    #[ORM\Column(name: 'ai_model', length: 64, nullable: true)]
    private ?string $model = null;

    #[ORM\ManyToOne(targetEntity: AiCredential::class)]
    #[ORM\JoinColumn(name: 'embed_credential_id', nullable: true, onDelete: 'SET NULL')]
    private ?AiCredential $embedCredential = null;

    #[ORM\Column(name: 'embedding_model', length: 40, options: ['default' => 'text-embedding-3-large'])]
    private string $embeddingModel = 'text-embedding-3-large';

    /** The owner's Personalization choices that differ from memex's defaults. Null means every default. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $personalization = null;

    /** Accent and background, per theme. Shape and vocabulary: App\Service\Appearance. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $appearance = null;

    /** How the owner wants the map drawn. Vocabulary: App\Service\MapSettings. */
    #[ORM\Column(name: 'map_settings', type: 'json', nullable: true)]
    private ?array $mapSettings = null;

    /** Interface language, one of the codes App\Service\Locales has installed. Null is English. */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $locale = null;

    /** The owner's face: an uploaded image, or null for the default silhouette. */
    #[ORM\Column(name: 'icon_key', length: 64, nullable: true)]
    private ?string $iconKey = null;

    #[ORM\Column(name: 'icon_blob', type: 'blob', nullable: true)]
    private mixed $iconBlob = null;

    /**
     * When the owner finished, or walked away from, the first-run wizard.
     * Which step it opens on is derived from live state
     * ({@see \App\Service\WelcomeProgress}); this only says whether signing in
     * should take them there at all.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $welcomeCompletedAt = null;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isAiEnabled(): bool
    {
        return $this->aiEnabled;
    }

    public function setAiEnabled(bool $enabled): void
    {
        $this->aiEnabled = $enabled;
        $this->touch();
    }

    public function getCredential(): ?AiCredential
    {
        return $this->aiCredential;
    }

    public function setCredential(?AiCredential $credential): void
    {
        if ($credential !== null && !AiProviders::isKnown($credential->getProvider())) {
            throw new \InvalidArgumentException('A key from '.$credential->getProvider().' cannot pay for that.');
        }
        $this->aiCredential = $credential;
        $this->touch();
    }

    /** The provider that key belongs to, or null when no key is chosen. */
    public function getProvider(): ?string
    {
        return $this->aiCredential?->getProvider();
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): void
    {
        $this->model = $model;
        $this->touch();
    }

    public function getEmbeddingModel(): EmbeddingModel
    {
        return EmbeddingModel::from($this->embeddingModel);
    }

    public function getEmbedCredential(): ?AiCredential
    {
        return $this->embedCredential;
    }

    public function setEmbedCredential(?AiCredential $credential): void
    {
        if ($credential !== null && $credential->getProvider() !== AiProviders::OPENAI) {
            throw new \InvalidArgumentException('Embeddings run on OpenAI, so only an OpenAI key can pay for them.');
        }
        $this->embedCredential = $credential;
        $this->touch();
    }

    public function getPersonalization(): ?array
    {
        return $this->personalization;
    }

    public function setPersonalization(?array $personalization): void
    {
        $this->personalization = $personalization;
        $this->touch();
    }

    public function getAppearance(): ?array
    {
        return $this->appearance;
    }

    public function setAppearance(?array $appearance): void
    {
        $this->appearance = $appearance;
        $this->touch();
    }

    public function getMapSettings(): ?array
    {
        return $this->mapSettings;
    }

    public function setMapSettings(?array $mapSettings): void
    {
        $this->mapSettings = $mapSettings;
        $this->touch();
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): void
    {
        $this->locale = $locale;
        $this->touch();
    }

    public function getIconKey(): ?string
    {
        return $this->iconKey;
    }

    public function setUploadedIcon(string $bytes): void
    {
        $this->iconKey = self::ICON_UPLOAD;
        $this->iconBlob = $bytes;
        $this->touch();
    }

    public function clearUploadedIcon(): void
    {
        $this->iconKey = null;
        $this->iconBlob = null;
        $this->touch();
    }

    public function getIconBytes(): ?string
    {
        if ($this->iconBlob === null) {
            return null;
        }
        if (\is_resource($this->iconBlob)) {
            rewind($this->iconBlob);

            return (string) stream_get_contents($this->iconBlob);
        }

        return (string) $this->iconBlob;
    }

    public function hasUploadedIcon(): bool
    {
        return $this->iconKey === self::ICON_UPLOAD && $this->iconBlob !== null;
    }

    public function hasFinishedWelcome(): bool
    {
        return $this->welcomeCompletedAt !== null;
    }

    public function finishWelcome(): void
    {
        $this->welcomeCompletedAt ??= new \DateTimeImmutable();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
