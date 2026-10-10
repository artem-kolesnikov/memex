<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A connection: an agent or API client holding a bearer token. The token's
 * sha256 hash lives in the directory, which routes it here. A token the owner
 * made for an assistant (mxt_<hex>) is also kept here encrypted, so Settings
 * can copy it again; one an OAuth client holds is not. Every write made with
 * a token is attributed to it, and token-authored notes land review-gated —
 * EXCEPT tokens with the 'curator' role: their safe writes (create/edit) apply
 * immediately and are audit-logged instead of held. Deletes and merges stay
 * held for every role (PRODUCT.md §Curator role: "guarded — no unapproved
 * deletes; more freedom otherwise").
 */
#[ORM\Entity]
#[ORM\Table(name: 'api_tokens')]
class ApiToken
{
    public const ROLE_AGENT = 'agent';
    public const ROLE_CURATOR = 'curator';

    /** Long enough for "Claude · research on the laptop", short enough for a table cell. */
    public const MAX_DISPLAY_NAME = 80;

    /** A description is a sentence or two for a person, not a second note. */
    public const MAX_DESCRIPTION = 500;

    /**
     * The stored PNG's ceiling. A 256x256 icon re-encoded by GD lands well
     * under this; the limit is here so a hostile upload cannot fill a column.
     */
    public const MAX_ICON_BYTES = 128 * 1024;

    /** The longest side an uploaded icon is scaled down to before storing. */
    public const ICON_SIZE = 256;

    /** The sentinel `iconKey` meaning "the bytes are in this row". */
    public const ICON_UPLOAD = 'upload';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /**
     * When this connection last opened the memex docs, by fetching the seeded
     * note or loading the `memex-docs` skill. The first-run wizard's last
     * step asks the person to make that happen and checks for it here.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $guideReadAt = null;

    #[ORM\Column(name: 'skills_seen', length: 16, nullable: true)]
    private ?string $skillsSeen = null;

    #[ORM\Column(name: 'personalization_seen', length: 16, nullable: true)]
    private ?string $personalizationSeen = null;

    #[ORM\Column(length: 16, options: ['default' => self::ROLE_AGENT])]
    private string $role = self::ROLE_AGENT;

    /**
     * What the OWNER calls this connection, when they have said.
     *
     * Beside {@see $name} rather than replacing it: `name` is what the client
     * called itself when it registered, and it is part of the record of how
     * this token came to exist. Two live tokens on the operator's own box are
     * both called `oauth: Google`, which is why this exists.
     */
    #[ORM\Column(name: 'display_name', length: self::MAX_DISPLAY_NAME, nullable: true)]
    private ?string $displayName = null;

    /** Free text for a person. Nothing in the system reads it. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * `builtin:<id>` for one of the shipped marks, or `upload` when the bytes
     * are in {@see $iconBlob}. One column rather than a key plus a flag,
     * because a key and a flag can disagree with each other.
     */
    #[ORM\Column(name: 'icon_key', length: 40, nullable: true)]
    private ?string $iconKey = null;

    /** A re-encoded PNG, at most {@see self::MAX_ICON_BYTES}. Null unless `iconKey` is `upload`. */
    #[ORM\Column(name: 'icon_blob', type: 'blob', nullable: true)]
    private mixed $iconBlob = null;

    /**
     * The brief this connection curates under. Null means Standard; the
     * scorecard compares agent against agent only when they can be told apart
     * by the instructions they were served.
     */
    #[ORM\ManyToOne(targetEntity: CurationPreset::class)]
    #[ORM\JoinColumn(name: 'curation_preset_id', nullable: true, onDelete: 'SET NULL')]
    private ?CurationPreset $curationPreset = null;

    /**
     * The token itself, encrypted with {@see \App\Service\CredentialCipher}.
     * Null for a token memex was not given to keep: one an OAuth client holds,
     * one made before tokens were kept, and one revoked.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $secret = null;

    public function __construct(string $name)
    {
        $this->name = $name;
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

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function revoke(): void
    {
        $this->revokedAt = new \DateTimeImmutable();
        $this->secret = null;
    }

    public function getSecret(): ?string
    {
        return $this->secret;
    }

    public function keepSecret(?string $encrypted): void
    {
        $this->secret = $encrypted;
    }

    public function touch(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
    }

    public function markGuideRead(): void
    {
        $this->guideReadAt = new \DateTimeImmutable();
    }

    public function getGuideReadAt(): ?\DateTimeImmutable
    {
        return $this->guideReadAt;
    }

    public function getSkillsSeen(): ?string
    {
        return $this->skillsSeen;
    }

    public function markSkillsSeen(string $hash): void
    {
        $this->skillsSeen = $hash;
    }

    public function getPersonalizationSeen(): ?string
    {
        return $this->personalizationSeen;
    }

    public function markPersonalizationSeen(string $hash): void
    {
        $this->personalizationSeen = $hash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    /** What to call this connection: the owner's name for it, or the client's. */
    public function displayName(): string
    {
        return $this->displayName ?? $this->name;
    }

    /** Null unless somebody has renamed it — the UI shows which of the two it has. */
    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): void
    {
        $displayName = $displayName === null ? null : trim($displayName);
        $this->displayName = ($displayName === null || $displayName === '')
            ? null
            : mb_substr($displayName, 0, self::MAX_DISPLAY_NAME);
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $description = $description === null ? null : trim($description);
        $this->description = ($description === null || $description === '')
            ? null
            : mb_substr($description, 0, self::MAX_DESCRIPTION);
    }

    public function getIconKey(): ?string
    {
        return $this->iconKey;
    }

    /** A built-in mark. Clears any uploaded bytes, which become unreachable. */
    public function setBuiltinIcon(?string $key): void
    {
        $this->iconKey = $key;
        $this->iconBlob = null;
    }

    public function setUploadedIcon(string $png): void
    {
        $this->iconKey = self::ICON_UPLOAD;
        $this->iconBlob = $png;
    }

    /**
     * The stored PNG, or null.
     *
     * Doctrine's blob type hands the column back as a STREAM RESOURCE rather
     * than a string. Read once, here, so no caller has to remember.
     */
    public function getIconPng(): ?string
    {
        if ($this->iconBlob === null) {
            return null;
        }
        if (is_resource($this->iconBlob)) {
            rewind($this->iconBlob);

            return (string) stream_get_contents($this->iconBlob);
        }

        return (string) $this->iconBlob;
    }

    public function hasUploadedIcon(): bool
    {
        return $this->iconKey === self::ICON_UPLOAD && $this->iconBlob !== null;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function isCurator(): bool
    {
        return $this->role === self::ROLE_CURATOR;
    }

    public function setRole(string $role): void
    {
        if (!in_array($role, [self::ROLE_AGENT, self::ROLE_CURATOR], true)) {
            throw new \InvalidArgumentException('Unknown token role: '.$role);
        }
        $this->role = $role;
    }

    public function getCurationPreset(): ?CurationPreset
    {
        return $this->curationPreset;
    }

    public function setCurationPreset(?CurationPreset $preset): void
    {
        $this->curationPreset = $preset;
    }
}
