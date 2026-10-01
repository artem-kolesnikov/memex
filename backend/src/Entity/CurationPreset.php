<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\CurationBriefFields;
use Doctrine\ORM\Mapping as ORM;

/**
 * A named set of curation settings — what the desk calls a brief.
 *
 * The version counter is not bookkeeping: a run records the version it was
 * served, which is what lets the scorecard tell a bad agent apart from a bad
 * brief. It advances on every content change, never on a rename.
 *
 * The `Default` profile is seeded per team and locked: it is what an unedited
 * account is served and what every assistant falls back to, so it cannot be
 * renamed, edited or deleted.
 */
#[ORM\Entity]
#[ORM\Table(name: 'curation_presets')]
#[ORM\UniqueConstraint(name: 'uniq_curation_presets_name', columns: ['name'])]
// At most one shipped profile per vault, whatever it is named — the name index
// cannot express it, and `CurationCharter::standard()` looks up by this flag.
#[ORM\UniqueConstraint(name: 'uniq_curation_presets_standard', columns: ['is_standard'], options: ['where' => 'is_standard'])]
class CurationPreset
{
    public const DEFAULT_NAME = 'Default';
    public const MAX_NAME = 60;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::MAX_NAME)]
    private string $name;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $fields;

    #[ORM\Column(options: ['default' => 1])]
    private int $version = 1;

    /** The seeded preset, which cannot be deleted and which restore resets. */
    #[ORM\Column(name: 'is_standard', options: ['default' => false])]
    private bool $standard = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @param array<string, mixed> $fields */
    public function __construct(string $name, array $fields, bool $standard = false)
    {
        $this->name = $name;
        $this->fields = $fields;
        $this->standard = $standard;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** @return array<string, mixed> */
    public function getFields(): array
    {
        return $this->fields;
    }

    /** @param array<string, mixed> $fields */
    public function setFields(array $fields): void
    {
        if ($fields === $this->fields) {
            return;
        }
        $this->fields = $fields;
        ++$this->version;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function isStandard(): bool
    {
        return $this->standard;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isShipped(): bool
    {
        return $this->fields === CurationBriefFields::defaults();
    }
}
