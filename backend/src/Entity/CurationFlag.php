<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The operator, pointing at one note and saying "this one next, and here is
 * what is wrong with it".
 *
 * Everything else in `CurationQueue` is a predicate the database can check —
 * untagged, unsummarised, disconnected. Those find *structural* defects, which
 * is most of what goes wrong and none of what only a human knows: that a note
 * is subtly out of date, that its framing misleads, that two paragraphs
 * contradict something said in a meeting the vault never recorded. There is no
 * query for "this is wrong in a way you cannot see from the schema", so this
 * table is where that sentence goes.
 *
 * Three shapes, each load-bearing:
 *
 * - **The comment is the point, not the flag.** A bare "look at this" ranks a
 *   note without saying what to do, and the curator would re-derive an opinion
 *   the operator already has. The comment is required, and the run procedure
 *   tells the curator it outranks the curator's own reading of the note.
 * - **One open flag per note** (unique partial index, `resolved_at IS NULL`).
 *   Re-flagging a flagged note rewords the standing instruction rather than
 *   stacking a second one — the curator should be handed one instruction per
 *   note, not a queue within the queue. Resolved rows stay as history, so the
 *   note keeps the record of every concern raised about it and what came back.
 * - **The curator resolves it, and must say what it did.** A flag bypasses the
 *   cooldown, so an unresolved one would be handed to every run forever; the
 *   `resolve_curation_flag` verb is what takes it out of the queue, and it
 *   demands a resolution that lands in the Curator log. Disagreement is a legal
 *   resolution — silently dropping it is not, and the operator reads either.
 *
 * FK to `notes` with ON DELETE CASCADE, deliberately unlike `note_revisions`:
 * history is worth surviving limbo, a standing instruction is not. A retired
 * note is not a note anyone should be told to go fix, and if it comes back the
 * operator can say so again in one click.
 */
#[ORM\Entity]
#[ORM\Table(name: 'curation_flags')]
// The queue's read: the OPEN flags. Partial-index territory, but the
// unique index below already covers open rows; this one serves the operator's
// "what have I flagged" listing, which wants them ordered.
#[ORM\Index(name: 'idx_curation_flags_open', columns: ['resolved_at', 'created_at'])]
// THE INVARIANT, not an optimisation (Version20260817000015 says so in as
// many words): one open flag per note, enforced by the database because
// application code cannot enforce it against a concurrent writer. It was
// undeclared here, so any schema tool would have dropped the only thing
// actually holding the rule.
#[ORM\UniqueConstraint(name: 'uniq_curation_flags_open_note', columns: ['note_id'], options: ['where' => 'resolved_at IS NULL'])]
class CurationFlag
{
    /**
     * The same bound as an operator's verdict comment (ApiController), for the
     * same reason: this is prose someone types, and the curator re-reads it on
     * every run until it is resolved.
     */
    public const COMMENT_MAX = 5000;

    /** What `resolved_by` says when the operator withdrew the flag themselves. */
    public const RESOLVED_BY_OPERATOR = 'operator';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Note::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Note $note;

    /** What is wrong with the note and what to pay attention to. */
    #[ORM\Column(type: 'text')]
    private string $comment;

    /** The flagger's name, copied at flag time, so the queue reads it without a lookup. */
    #[ORM\Column(length: 120)]
    private string $flaggedByName;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Set when the comment is reworded, so "flagged since" stays honest. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /** Token name of the curator that resolved it, or `operator`. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $resolvedBy = null;

    /** What was done about it — required of the curator, copied into the log. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $resolution = null;

    public function __construct(Note $note, string $flaggedByName, string $comment)
    {
        $this->note = $note;
        $this->flaggedByName = $flaggedByName;
        $this->comment = $comment;
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Replace the standing instruction; the flag keeps its original age. */
    public function reword(string $comment): self
    {
        $this->comment = $comment;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function resolve(string $by, ?string $resolution): self
    {
        $this->resolvedAt = new \DateTimeImmutable();
        $this->resolvedBy = $by;
        $resolution = $resolution === null ? null : trim($resolution);
        $this->resolution = ($resolution === null || $resolution === '') ? null : $resolution;

        return $this;
    }

    public function isOpen(): bool
    {
        return $this->resolvedAt === null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNote(): Note
    {
        return $this->note;
    }

    public function getComment(): string
    {
        return $this->comment;
    }

    public function getFlaggedByName(): string
    {
        return $this->flaggedByName;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getResolvedBy(): ?string
    {
        return $this->resolvedBy;
    }

    public function getResolution(): ?string
    {
        return $this->resolution;
    }

    /**
     * The shape every surface shows: the API's note detail, MCP's `get`, and
     * the queue candidate. One definition so the website and the curator are
     * reading the same fields under the same names.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'comment' => $this->comment,
            'flagged_by' => $this->flaggedByName,
            'flagged_at' => $this->createdAt->format(DATE_ATOM),
            'reworded_at' => $this->updatedAt?->format(DATE_ATOM),
        ];
    }
}
