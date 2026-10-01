<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A tag the owner deliberately removed from their vocabulary.
 *
 * The row exists so that removing a tag MEANS something afterwards. Without
 * it, deleting `inbox` from ninety notes is undone by the next enrichment
 * pass, which reads the note, thinks "this looks like an inbox item", and
 * suggests the word back — and the owner deletes it again next week. That is
 * the same shape as the note tombstone in `deleted_notes`: a deletion that
 * leaves no record is a deletion the system is free to reverse.
 *
 * **What it suppresses is SUGGESTION, not use.** The distinction is the whole
 * design:
 *
 *  - Server-side enrichment drops a retired name from the tags it invents
 *    ({@see \App\Service\NoteEnricher}), so the machine stops proposing it.
 *  - `list_tags` reports retired names to connected assistants, so one that
 *    is choosing words knows which ones were rejected on purpose.
 *  - Nothing stops a PERSON typing the word again. A tag the owner is
 *    forbidden to use in their own knowledge base would be an absurd thing to
 *    have built, and the record is about what the machine should stop
 *    guessing, not about a banned word.
 *
 * **It clears the moment the tag genuinely comes back.** {@see
 * \App\Service\NoteWriter::resolveTags()} is the one place a tag row is ever
 * created, and creating one drops the tombstone for that name: the tag is
 * demonstrably in the vocabulary again, and going on suppressing it would
 * make `list_tags` lie about what the vocabulary contains.
 *
 * The known hole, named rather than papered over: a curator-role token's
 * writes apply without review, so a curator that proposes a retired name
 * anyway both re-creates the tag and clears its own suppression. It is told
 * which names were retired and why; the journal records what it did. Closing
 * that properly means a role-aware exception in tag resolution, which is a
 * bigger rule than this one is worth today.
 */
#[ORM\Entity]
#[ORM\Table(name: 'retired_tags')]
#[ORM\UniqueConstraint(name: 'uniq_retired_tag_name', columns: ['name'])]
class RetiredTag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $name;

    /** How many notes carried it when it went, so the journal can be checked later. */
    #[ORM\Column(name: 'note_count')]
    private int $noteCount;

    /**
     * The tag its notes were moved to, when this was a merge rather than a
     * removal. Kept as a NAME: the target can itself be retired later, and a
     * foreign key would either block that or take this row with it.
     */
    #[ORM\Column(name: 'merged_into', length: 64, nullable: true)]
    private ?string $mergedInto = null;

    #[ORM\Column(name: 'retired_at')]
    private \DateTimeImmutable $retiredAt;

    public function __construct(string $name, int $noteCount, ?string $mergedInto = null)
    {
        $this->name = $name;
        $this->noteCount = $noteCount;
        $this->mergedInto = $mergedInto;
        $this->retiredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getNoteCount(): int
    {
        return $this->noteCount;
    }

    public function getMergedInto(): ?string
    {
        return $this->mergedInto;
    }

    public function getRetiredAt(): \DateTimeImmutable
    {
        return $this->retiredAt;
    }
}
