<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The one uniform record type (PRODUCT.md §Data model): markdown body + metadata,
 * origin recorded in `source`, agent writes review-gated via `status`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notes')]
#[ORM\Index(name: 'idx_notes_status', columns: ['status'])]
#[ORM\Index(name: 'idx_notes_updated', columns: ['updated_at'])]
// Declared here, not only in the migration: an index the entity does not
// know about is one `migrations:diff` would generate a DROP for.
#[ORM\Index(name: 'idx_notes_last_actor', columns: ['last_actor'])]
class Note
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_SCRAPE = 'scrape';
    public const SOURCE_UPLOAD = 'upload';
    public const SOURCE_AGENT = 'agent';
    public const SOURCE_MEMEX = 'memex';
    public const SOURCES = [self::SOURCE_MANUAL, self::SOURCE_SCRAPE, self::SOURCE_UPLOAD, self::SOURCE_AGENT, self::SOURCE_MEMEX];

    /**
     * Who touched this note LAST — distinct from `source`, which records how the
     * note first arrived and never changes. The list view colours its icon by
     * this, so the operator can see at a glance who the current contributor is
     * rather than who created it a year ago.
     */
    public const ACTOR_SCRAPE = 'scrape';
    public const ACTOR_UPLOAD = 'upload';
    public const ACTOR_AGENT = 'agent';
    public const ACTOR_CURATOR = 'curator';
    public const ACTOR_HUMAN = 'human';
    /**
     * memex itself, writing on the owner's own decision with the owner's own key.
     *
     * Added 2026-08-23 with the scheduled enrichment pass. `summary_by` has
     * carried `memex` since descriptions were first attributed; this is the
     * same author finally having a name in `last_actor` too, so a note the
     * pass described does not read as one the operator typed.
     */
    public const ACTOR_MEMEX = 'memex';

    /** `summaryBy` when the server wrote the description instead of an assistant. */
    public const SUMMARY_BY_MEMEX = 'memex';
    public const SUMMARY_BY_OPERATOR = 'operator';

    public const STATUS_VERIFIED = 'verified';
    public const STATUS_PENDING = 'pending';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ApiToken::class)]
    #[ORM\JoinColumn(name: 'created_by_token_id', nullable: true)]
    private ?ApiToken $createdByToken;

    #[ORM\Column(length: 500)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $bodyMd;

    #[ORM\Column(length: 16)]
    private string $source;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sourceUrl;

    #[ORM\Column(length: 16)]
    private string $status;

    /** @see self::ACTOR_* — refreshed on every write, unlike `source`. */
    #[ORM\Column(length: 16, options: ['default' => self::ACTOR_HUMAN])]
    private string $lastActor = self::ACTOR_HUMAN;

    /**
     * WHICH assistant wrote last, where {@see $lastActor} says only what kind.
     *
     * Null for a person's own edit, for an upload, and for every
     * note written before 2026-08-23 — the association was not recorded, so
     * there is nothing to backfill from and the list simply shows no assistant
     * for those. `ON DELETE SET NULL`, so a token that is genuinely destroyed
     * takes no note with it.
     */
    #[ORM\ManyToOne(targetEntity: ApiToken::class)]
    #[ORM\JoinColumn(name: 'last_actor_token_id', nullable: true, onDelete: 'SET NULL')]
    private ?ApiToken $lastActorToken = null;

    /** Vault path (minus extension) for imported notes — a wiki-link identity. */
    #[ORM\Column(length: 600, nullable: true)]
    private ?string $importPath = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $summary = null;

    /**
     * Who wrote the description: `memex` when the server generated it, an API
     * token's name when an assistant supplied one, `operator` when a person
     * typed it.
     *
     * The product promise is that memex runs no AI of its own by default and
     * the user's own assistant does the describing. A promise nobody can check
     * is just a claim, and until this column existed there was no way to look
     * at a note and see which of the two had happened.
     *
     * Null on every note written before 2026-08-20, and deliberately NOT
     * backfilled: almost all of them were server-generated, but "almost" is a
     * guess, and a provenance field that guesses is worse than one that admits
     * it does not know.
     */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $summaryBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $enrichedAt = null;

    /**
     * Bumped by Doctrine on every ORM write, and checked in the UPDATE's WHERE
     * clause — see Version20260822000028. Two writers who both loaded this note
     * can no longer both succeed: the second gets OptimisticLockException,
     * which the controllers turn into a 409, instead of quietly winning.
     *
     * No setter, deliberately. The ORM owns this value; anything that assigns
     * it by hand is defeating the guard rather than using it.
     */
    #[ORM\Version]
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'note_tag')]
    private Collection $tags;

    /**
     * A provenance URL that is not http(s) is not provenance.
     *
     * The note view renders this value as a clickable link, and Vue does not
     * sanitise a bound `:href` — so a `javascript:` URL here is script the
     * operator is invited to run at the exact moment they are checking where
     * an agent's note came from. Nothing behind it catches this: there is no
     * CSP on the origin, and the review gate does not help, because the click
     * happens IN the review.
     *
     * Enforced in the constructor rather than at the API edge, because every
     * note in the system is built through it and a controller is a door
     * somebody adds another of. The two write endpoints reject a bad value
     * with a 400 as well — dropping it silently would leave the caller
     * believing provenance was recorded.
     */
    public static function normaliseSourceUrl(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_URL) ? parse_url($value) : false;

        return $parsed !== false && in_array($parsed['scheme'] ?? '', ['http', 'https'], true)
            ? $value
            : null;
    }

    public function __construct(
        ?ApiToken $createdByToken,
        string $title,
        string $bodyMd,
        string $source,
        ?string $sourceUrl,
        string $status,
    ) {
        $this->createdByToken = $createdByToken;
        $this->title = $title;
        $this->bodyMd = $bodyMd;
        $this->source = $source;
        $this->sourceUrl = self::normaliseSourceUrl($sourceUrl);
        $this->status = $status;
        $this->attribute(self::actorFor($source, $createdByToken), $createdByToken);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->tags = new ArrayCollection();
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** The owner added this note themselves: no assistant, and not memex. */
    public function isAddedByOwner(): bool
    {
        return $this->createdByToken === null && !\in_array($this->source, [self::SOURCE_AGENT, self::SOURCE_MEMEX], true);
    }

    public function getCreatedByToken(): ?ApiToken
    {
        return $this->createdByToken;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        $this->touch();
    }

    public function getBodyMd(): string
    {
        return $this->bodyMd;
    }

    public function setBodyMd(string $bodyMd): void
    {
        $this->bodyMd = $bodyMd;
        $this->touch();
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getLastActor(): string
    {
        return $this->lastActor;
    }

    public function getLastActorToken(): ?ApiToken
    {
        return $this->lastActorToken;
    }

    /** The owner wrote last: no assistant, and not memex. */
    public function isLastWrittenByOwner(): bool
    {
        return $this->lastActorToken === null && self::isOwnerActor($this->lastActor);
    }

    /** Whether an actor kind is the owner's own hand: not an assistant, not memex. */
    public static function isOwnerActor(?string $actor): bool
    {
        return !\in_array($actor, [self::ACTOR_AGENT, self::ACTOR_CURATOR, self::ACTOR_MEMEX], true);
    }

    /**
     * Record who wrote last, the kind and the assistant, in ONE call, because
     * they are one fact: no write path can name an actor without naming who
     * it was. The assistant is kept only when the write really was an
     * assistant's; otherwise the owner wrote it, unless memex did.
     */
    public function attribute(string $actor, ?ApiToken $token): void
    {
        $this->lastActor = $actor;
        // Only when the write really was an assistant's: a person editing
        // through the browser holds no token, and an upload arrived by a path
        // with no assistant behind it even when a token started it.
        $this->lastActorToken = in_array($actor, [self::ACTOR_AGENT, self::ACTOR_CURATOR], true)
            ? $token
            : null;
    }

    /**
     * Who a write should be attributed to.
     *
     * A token identifies an actor directly and outranks the content's origin:
     * an agent uploading a file is agent activity, not an upload the operator
     * performed.
     *
     * `$origin` applies to CREATION ONLY — pass null for an edit. How a note
     * first arrived says nothing about who changed it later, and an operator
     * editing an uploaded note by hand is a human write, not an upload.
     */
    /**
     * Is this title a filename, a slug or a bare identifier rather than a name
     * somebody chose?
     *
     * Lives here rather than in whichever controller needed it first, because
     * three things now ask it: the editor's Analyze, the scheduled enrichment
     * pass, and `CurationQueue::PREDICATES['weak_title']` — which is the same
     * rule in SQL and has to stay in step with this one by hand, because a
     * query cannot call a method.
     *
     * A space is enough to be a real title: "Disaster Recovery" is somebody
     * writing, and a one-word title like "Postgres" is left alone unless it
     * also carries an extension, a separator or a digit.
     */
    public static function isWeakTitle(string $title): bool
    {
        $title = trim($title);
        if ($title === '') {
            return true;
        }
        if (str_contains($title, ' ')) {
            return false;
        }

        return (bool) preg_match('/\.(md|markdown|txt|html?|pdf)$/i', $title)
            || (bool) preg_match('/[-_]/', $title)
            || (bool) preg_match('/\d/', $title);
    }

    public static function actorFor(?string $origin, ?ApiToken $token): string
    {
        if ($token !== null) {
            return $token->isCurator() ? self::ACTOR_CURATOR : self::ACTOR_AGENT;
        }

        return match ($origin) {
            self::SOURCE_SCRAPE => self::ACTOR_SCRAPE,
            self::SOURCE_UPLOAD => self::ACTOR_UPLOAD,
            default => self::ACTOR_HUMAN,
        };
    }

    public function getImportPath(): ?string
    {
        return $this->importPath;
    }

    public function setImportPath(?string $importPath): void
    {
        $this->importPath = $importPath;
    }

    /**
     * The dates a note brings from an export, in place of the moment it was
     * written here. Missing one, it takes the other's; an update before the
     * creation is read as the creation.
     */
    public function carryDates(?\DateTimeImmutable $created, ?\DateTimeImmutable $updated): void
    {
        $utc = new \DateTimeZone('UTC');
        $created = ($created ?? $updated)?->setTimezone($utc);
        $updated = ($updated ?? $created)?->setTimezone($utc);
        if ($created === null || $updated === null) {
            return;
        }
        $this->createdAt = $created;
        $this->updatedAt = max($created, $updated);
    }

    public function approve(): void
    {
        $this->status = self::STATUS_VERIFIED;
        $this->touch();
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    /**
     * @param string|null $by who wrote it — a token name, self::SUMMARY_BY_OPERATOR,
     *                        or self::SUMMARY_BY_MEMEX. Required in practice: a
     *                        description with no author is the thing this
     *                        column exists to stop.
     */
    public function setSummary(?string $summary, ?string $by = null): void
    {
        $this->summary = $summary;
        $this->summaryBy = $summary === null ? null : ($by === null ? null : mb_substr($by, 0, 120));
        $this->enrichedAt = new \DateTimeImmutable();
    }

    public function getSummaryBy(): ?string
    {
        return $this->summaryBy;
    }

    public function getEnrichedAt(): ?\DateTimeImmutable
    {
        return $this->enrichedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, Tag> */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): void
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
            $this->touch();
        }
    }

    public function clearTags(): void
    {
        if (!$this->tags->isEmpty()) {
            $this->touch();
        }
        $this->tags->clear();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
