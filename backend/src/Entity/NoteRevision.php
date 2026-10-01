<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\LineDiff;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a note held **before** the edit that replaced it.
 *
 * Deletion has been reversible since limbo shipped; this is the same promise
 * for editing, which until 2026-08-16 could only be walked back by restoring a
 * database dump. Written from inside `NoteWriter::update()` — the only call
 * site of `setTitle`/`setBodyMd` in the codebase — so no future write path can
 * quietly opt out of it.
 *
 * `noteId` is a **plain integer**, not a relation, and that is load-bearing:
 * retiring a note moves its row out of `notes` entirely, so a mapped
 * association would cascade the history away at exactly the moment somebody
 * might want it, and a restore (which reuses the original id) would come back
 * with nothing behind it. `deleted_notes` carries the id for the same reason.
 *
 * @see \App\Service\NoteRevisions for the writing, pruning and restoring
 */
#[ORM\Entity]
#[ORM\Table(name: 'note_revisions')]
#[ORM\Index(name: 'idx_note_revisions_note', columns: ['note_id', 'replaced_at'])]
#[ORM\Index(name: 'idx_note_revisions_replaced', columns: ['replaced_at'])]
class NoteRevision
{
    /**
     * How many states a note keeps (operator, 2026-08-16).
     *
     * Bounded rather than unlimited because the cost is concrete here: the
     * vault holds a 97,000-character note, and an unattended curator editing it
     * nightly would add tens of megabytes a year for that note alone. Twenty is
     * three weeks of nightly passes — long enough that a bad edit is still
     * reachable when someone notices it a fortnight later.
     */
    public const KEEP_PER_NOTE = 20;

    /** A direct write: the editor, or a token whose writes apply immediately. */
    public const OP_EDIT = 'edit';
    /** A held proposal the operator approved. */
    public const OP_PROPOSAL = 'proposal';
    /** The keeper of a merge, taking on the absorbed note's text. */
    public const OP_MERGE = 'merge';
    /** An older version put back from this very list. */
    public const OP_RESTORE = 'restore';

    public const OPERATIONS = [
        self::OP_EDIT,
        self::OP_PROPOSAL,
        self::OP_MERGE,
        self::OP_RESTORE,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'note_id')]
    private int $noteId;

    #[ORM\Column(length: 500)]
    private string $title;

    /**
     * The body, when this row is an ANCHOR: a revision written before diffs
     * existed, or one whose patch came out bigger than the text it describes.
     * Null when {@see $bodyDiff} carries the content instead. Exactly one of
     * the two is set.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bodyMd;

    /**
     * The body as a REVERSE patch: the operations that turn the state which
     * followed this one back into this one. JSON, in `LineDiff`'s format.
     *
     * Backwards because the chain has to be anchored on something that is not
     * pruned, and the only such thing is the note as it stands now — see
     * Version20260822000031 for the argument in full. Resolved by
     * `NoteRevisions`, never by reading this field directly.
     */
    #[ORM\Column(name: 'body_diff', type: 'text', nullable: true)]
    private ?string $bodyDiff = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $summary;

    /** @var string[] Tag names as they stood, resolved to ids only on restore. */
    #[ORM\Column(type: 'json')]
    private array $tags;

    /** One of Note::ACTOR_* — who made the edit that replaced this state. */
    #[ORM\Column(name: 'replaced_by', length: 16)]
    private string $replacedBy;

    /**
     * WHICH write path replaced this state — {@see self::OPERATIONS}.
     *
     * `replacedBy` says only what KIND of actor wrote, and a role is not a
     * process: 231 of the operator's 268 rows read "replaced by curator"
     * because the token happened to hold curator rights, on edits that had
     * nothing to do with a curation pass (operator, 2026-08-27). Set at each
     * call site of `NoteWriter::update()`, which is the only place that knows.
     *
     * Null for every row written before 2026-08-27 that the migration's
     * backfill could not match.
     */
    #[ORM\Column(length: 24, nullable: true)]
    private ?string $operation = null;

    /**
     * WHICH assistant, where `replacedBy` says only what kind — resolved
     * through the token every time so renaming a connection renames it here
     * too. Null with `replacedBy` human means the owner.
     */
    #[ORM\ManyToOne(targetEntity: ApiToken::class)]
    #[ORM\JoinColumn(name: 'replaced_by_token_id', nullable: true, onDelete: 'SET NULL')]
    private ?ApiToken $replacedByToken = null;

    /**
     * What that edit said it was doing, in six or seven words — copied from
     * the proposal when the edit landed rather than joined to it, because a
     * proposal can be pruned and a revision is meant to be readable in a year.
     * Null for every revision written before 2026-08-23 and for edits made
     * directly in the web editor, which carry no headline.
     */
    #[ORM\Column(name: 'change_title', length: 120, nullable: true)]
    private ?string $changeTitle = null;

    /**
     * The write that replaced this state carried the operator's own text,
     * not the text the contributor filed.
     *
     * Only an approval can set it. Approving is a gate rather than authorship,
     * so an amended proposal still credits the proposer in `replaced_by` — and
     * without this column that is the whole story the history tells, which
     * makes a body the operator rewrote wholesale indistinguishable from the
     * one the agent sent.
     */
    #[ORM\Column(name: 'amended_by_operator', options: ['default' => false])]
    private bool $amendedByOperator = false;

    #[ORM\Column(name: 'replaced_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $replacedAt;

    /**
     * What the note's own `updated_at` read while this state was live.
     *
     * Distinct from `replacedAt`, and the distinction is the useful one: this
     * says how old the content was, that says when it was lost. A note edited
     * once in January and again in August has a revision whose content is seven
     * months old and whose row is minutes old.
     */
    #[ORM\Column(name: 'note_updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $noteUpdatedAt;

    /**
     * Capture a note's current state, before the caller mutates it.
     *
     * Takes the live entity rather than loose fields so a new column on `Note`
     * cannot be added to the write path and silently left out of its history.
     */
    /**
     * @param string|null $changeTitle what the edit that replaced this state
     *                                 said it was doing, in six or seven words
     */
    public static function capture(
        Note $note,
        string $replacedBy,
        ?string $changeTitle = null,
        ?string $operation = null,
        ?ApiToken $replacedByToken = null,
    ): self {
        $revision = new self();
        $revision->noteId = (int) $note->getId();
        $revision->title = $note->getTitle();
        $revision->bodyMd = $note->getBodyMd();
        $revision->summary = $note->getSummary();
        $revision->tags = array_values(array_map(
            static fn (Tag $tag): string => $tag->getName(),
            $note->getTags()->toArray()
        ));
        $revision->replacedBy = $replacedBy;
        // Refused rather than nulled: an unknown value here would be a typo in
        // one of the five call sites, and nulling it silently renders exactly
        // like a row the backfill could not match — a current bug wearing the
        // legacy fallback's clothes (Codex, 2026-08-27).
        if ($operation !== null && !in_array($operation, self::OPERATIONS, true)) {
            throw new \InvalidArgumentException('Unknown revision operation: '.$operation);
        }
        $revision->operation = $operation;
        $revision->replacedByToken = $replacedByToken;
        $changeTitle = $changeTitle === null ? null : trim($changeTitle);
        $revision->changeTitle = ($changeTitle === null || $changeTitle === '') ? null : mb_substr($changeTitle, 0, 120);
        $revision->replacedAt = new \DateTimeImmutable();
        $revision->noteUpdatedAt = $note->getUpdatedAt();

        return $revision;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChangeTitle(): ?string
    {
        return $this->changeTitle;
    }

    public function isAmendedByOperator(): bool
    {
        return $this->amendedByOperator;
    }

    public function markAmendedByOperator(): self
    {
        $this->amendedByOperator = true;

        return $this;
    }

    public function getNoteId(): int
    {
        return $this->noteId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Optional reverse-delta encoding, including the exact successor identity.
     * Normal writes keep full snapshots; retain the body when this envelope is larger.
     */
    public function compressAgainst(?string $successor): void
    {
        if ($this->bodyMd === null || $successor === null) {
            return;
        }
        $patch = json_encode([
            'source_sha256' => hash('sha256', $successor),
            'ops' => LineDiff::diff($successor, $this->bodyMd),
        ], JSON_UNESCAPED_UNICODE);
        if ($patch === false || strlen($patch) >= strlen($this->bodyMd)) {
            return;
        }
        $this->bodyDiff = $patch;
        $this->bodyMd = null;
    }

    /**
     * The stored patch, decoded, or null if this row is an anchor.
     *
     * @return list<array{0: int, 1: int|list<string>}>|null
     */
    public function getBodyDiffOps(): ?array
    {
        if ($this->bodyDiff === null) {
            return null;
        }
        $ops = json_decode($this->bodyDiff, true);

        return is_array($ops) ? ($ops['ops'] ?? $ops) : null;
    }

    public function getBodyDiffSourceHash(): ?string
    {
        $patch = $this->bodyDiff === null ? null : json_decode($this->bodyDiff, true);

        return is_array($patch) && isset($patch['source_sha256']) ? (string) $patch['source_sha256'] : null;
    }

    /** Stored full body, or null when the revision uses a legacy/optional delta. */
    public function getBodyMd(): ?string
    {
        return $this->bodyMd;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    /** @return string[] */
    public function getTags(): array
    {
        return $this->tags;
    }

    public function getReplacedBy(): string
    {
        return $this->replacedBy;
    }

    public function getOperation(): ?string
    {
        return $this->operation;
    }

    public function getReplacedByToken(): ?ApiToken
    {
        return $this->replacedByToken;
    }

    public function getReplacedAt(): \DateTimeImmutable
    {
        return $this->replacedAt;
    }

    public function getNoteUpdatedAt(): \DateTimeImmutable
    {
        return $this->noteUpdatedAt;
    }

    /**
     * Whether this state differs from what the note holds now.
     *
     * The guard against history made of noise: `update()` is called with nulls
     * for "leave alone", and several paths (a merge that only moves tags, an
     * enrichment re-run) reach it without changing title, body or tags at all.
     * A revision per such call would push real states out of a bounded window
     * with copies of the present.
     */
    public function differsFrom(Note $note): bool
    {
        // The summary counts, and leaving it out had a consequence rather than
        // being an omission: an amendment that rewrote only the description
        // wrote no revision at all, so `amended_by_operator` — which lives on
        // the revision — had nowhere to land and the operator's rewrite went
        // unrecorded (Codex, 2026-09-07).
        if ($this->title !== $note->getTitle()
            || $this->bodyMd !== $note->getBodyMd()
            || $this->summary !== $note->getSummary()
        ) {
            return true;
        }
        $now = array_map(static fn (Tag $tag): string => $tag->getName(), $note->getTags()->toArray());
        sort($now);
        $then = $this->tags;
        sort($then);

        return $now !== $then;
    }
}
