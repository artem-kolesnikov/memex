<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A [[wiki-link]] parsed from a note's body. Links resolve by title within the
 * team; unresolved links keep raw_target and resolve later if a matching note
 * appears (PRODUCT.md §Data model).
 */
#[ORM\Entity]
#[ORM\Table(name: 'note_links')]
#[ORM\UniqueConstraint(name: 'uniq_note_link_target', columns: ['from_note_id', 'raw_target'])]
#[ORM\Index(name: 'idx_note_links_to', columns: ['to_note_id'])]
class NoteLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Note::class)]
    #[ORM\JoinColumn(name: 'from_note_id', nullable: false, onDelete: 'CASCADE')]
    private Note $fromNote;

    #[ORM\ManyToOne(targetEntity: Note::class)]
    #[ORM\JoinColumn(name: 'to_note_id', nullable: true, onDelete: 'SET NULL')]
    private ?Note $toNote;

    #[ORM\Column(length: 500)]
    private string $rawTarget;

    public function __construct(Note $fromNote, ?Note $toNote, string $rawTarget)
    {
        $this->fromNote = $fromNote;
        $this->toNote = $toNote;
        $this->rawTarget = $rawTarget;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFromNote(): Note
    {
        return $this->fromNote;
    }

    public function getToNote(): ?Note
    {
        return $this->toNote;
    }

    public function getRawTarget(): string
    {
        return $this->rawTarget;
    }
}
