<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A saved notes-list filter, pinned to the sidebar under Workspace.
 *
 * Tags are held by id rather than name so a rename follows the preset; the
 * names are resolved on read.
 */
#[ORM\Entity]
#[ORM\Table(name: 'search_presets')]
#[ORM\UniqueConstraint(name: 'uniq_search_presets_name', columns: ['name'])]
class SearchPreset
{
    public const MAX_NAME = 60;
    public const MAX_QUERY = 500;
    public const MAX_PER_TEAM = 50;
    public const STATUSES = ['verified', 'pending', 'flagged', 'undescribed'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::MAX_NAME)]
    private string $name;

    #[ORM\Column(length: 32)]
    private string $icon = 'filter';

    #[ORM\Column(length: self::MAX_QUERY)]
    private string $query = '';

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $tagIds = [];

    #[ORM\Column(length: 16)]
    private string $status = '';

    #[ORM\Column(nullable: true)]
    private ?int $addedBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name)
    {
        $this->name = $name;
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

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function setIcon(string $icon): void
    {
        $this->icon = $icon;
        $this->touch();
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    /** @return list<int> */
    public function getTagIds(): array
    {
        return $this->tagIds;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getAddedBy(): ?int
    {
        return $this->addedBy;
    }

    /** @param list<int> $tagIds */
    public function setCriteria(string $query, array $tagIds, string $status, ?int $addedBy): void
    {
        $this->query = $query;
        $this->tagIds = $tagIds;
        $this->status = $status;
        $this->addedBy = $addedBy;
        $this->touch();
    }

    /**
     * A tag this preset filters on left the vocabulary: follow it into the tag
     * it merged into, or drop it when it was removed outright.
     */
    public function retireTag(int $tagId, ?int $into): void
    {
        if (!in_array($tagId, $this->tagIds, true)) {
            return;
        }
        $ids = [];
        foreach ($this->tagIds as $id) {
            $next = $id === $tagId ? $into : $id;
            if ($next !== null) {
                $ids[$next] = $next;
            }
        }
        $this->tagIds = array_values($ids);
        $this->touch();
    }

    public function isEmpty(): bool
    {
        return $this->query === '' && $this->tagIds === [] && $this->status === '' && $this->addedBy === null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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
