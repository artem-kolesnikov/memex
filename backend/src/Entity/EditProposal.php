<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\NotePatch;
use Doctrine\ORM\Mapping as ORM;

/**
 * A held-for-review agent change to an existing note (PRODUCT.md §Review
 * gate: "the proposal is held, not applied, until approved"). Five types:
 * 'edit' (null fields = leave untouched; tags is a full replacement list),
 * 'create' (a whole new note), 'report' (a comment saying what is wrong, and
 * no change at all), 'delete' (retire the note), 'merge' (fold `note` into
 * `mergeIntoNote`; proposedBodyMd, when present, is the keeper's body). A row's
 * existence IS the pending state — approve applies and deletes, reject just
 * deletes (mirrors how pending notes are rejected).
 */
#[ORM\Entity]
#[ORM\Table(name: 'edit_proposals')]
#[ORM\Index(name: 'idx_edit_proposals_note', columns: ['note_id'])]
// The review inbox's own query, and therefore a hot path.
#[ORM\Index(name: 'idx_edit_proposals_status', columns: ['status'])]
class EditProposal
{
    public const TYPE_EDIT = 'edit';
    public const TYPE_DELETE = 'delete';
    public const TYPE_MERGE = 'merge';
    public const TYPE_REPORT = 'report';
    public const STATUS_HELD = 'held';
    public const STATUS_APPLIED = 'applied';
    /** The audit record of a curator's create, which applied at once. */
    public const TYPE_CREATE = 'create';
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Note::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Note $note;

    /** Always token-attributed: only agent writes are held for review. */
    /** Null = memex itself — see {@see getProposedByToken()}. */
    #[ORM\ManyToOne(targetEntity: ApiToken::class)]
    #[ORM\JoinColumn(name: 'proposed_by_token_id', nullable: true)]
    private ?ApiToken $proposedByToken = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $proposedTitle;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $proposedBodyMd;

    /** @var string[]|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $proposedTags;

    /**
     * The description the proposer wrote for this note. Present because memex
     * writes no summaries of its own for content an assistant brought: the
     * assistant is holding the text and describes it, and that description has
     * to survive review like every other part of the change. Without this
     * column an agent-role token could propose a summary and watch it vanish
     * on approval, because applying regenerated one.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $proposedSummary = null;

    /**
     * An edit expressed as anchored replacements rather than a whole body:
     * `[{find, replace}, …]`, applied in order by {@see \App\Service\NotePatch}.
     *
     * **Stored unresolved on purpose.** Resolving it to a body when the
     * proposal is filed would make it a full-body edit again, with the same
     * overwrite hazard: two proposals held on one note would each carry a
     * snapshot, and approving the second would silently revert the first. Kept
     * as operations, it is applied to the note as it stands AT APPROVAL, so
     * changes to different sentences compose and one whose anchor has moved is
     * refused where somebody can see it.
     *
     * Mutually exclusive with proposedBodyMd — a proposal says how it changes
     * the text one way or the other, never both.
     *
     * @var array<int, array{find: string, replace: string}>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $proposedPatch = null;

    /**
     * The description this change replaced, captured when it applied. Without
     * it the activity log renders a curator's summary edit as text arriving
     * from nowhere, and a direct curator edit is the one write whose only
     * record is that log.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $prevSummary = null;

    /**
     * Six or seven words: what this edit does and why.
     *
     * Distinct from {@see $comment}, which is prose written to be read once
     * somebody has decided to look. This is what makes that decision — the
     * inbox and the note's history both list rows by it, so neither screen
     * makes you open a row to find out whether the row is worth opening.
     * Null on every proposal written before 2026-08-23 and on anything that
     * did not send one.
     */
    #[ORM\Column(name: 'change_title', length: 120, nullable: true)]
    private ?string $changeTitle = null;

    /** The agent's rationale — shown in the review inbox. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment;

    #[ORM\Column(length: 16, options: ['default' => self::TYPE_EDIT])]
    private string $type = self::TYPE_EDIT;

    /** Merge keeper: `note` is absorbed into this one on approval. */
    #[ORM\ManyToOne(targetEntity: Note::class)]
    #[ORM\JoinColumn(name: 'merge_into_note_id', nullable: true, onDelete: 'CASCADE')]
    private ?Note $mergeIntoNote = null;

    /**
     * 'held' = awaiting operator review (the classic gate). 'applied' = a
     * curator-token write that applied immediately; the row survives as the
     * AUDIT record, with prev* snapshotting the state it replaced so the
     * activity view can show a real diff after the fact.
     */
    #[ORM\Column(length: 16, options: ['default' => self::STATUS_HELD])]
    private string $status = self::STATUS_HELD;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $appliedAt = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $prevTitle = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $prevBodyMd = null;

    /** @var string[]|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $prevTags = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * When the author last folded a further edit into this draft.
     *
     * A held proposal is the author's working copy, not a filing: a second
     * edit from the same connection revises this row rather than queueing
     * another card, so `createdAt` alone stops describing what the operator is
     * looking at. Null on a draft nobody has revised.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revisedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $revision = 0;

    /**
     * @param string[]|null $proposedTags
     */
    public function __construct(
        Note $note,
        ?ApiToken $proposedByToken,
        ?string $proposedTitle,
        ?string $proposedBodyMd,
        ?array $proposedTags,
        ?string $comment,
    ) {
        $this->note = $note;
        $this->proposedByToken = $proposedByToken;
        $this->proposedTitle = $proposedTitle;
        $this->proposedBodyMd = $proposedBodyMd;
        $this->proposedTags = $proposedTags;
        $this->comment = $comment;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function withChangeTitle(?string $title): self
    {
        $title = $title === null ? null : trim($title);
        $this->changeTitle = ($title === null || $title === '') ? null : mb_substr($title, 0, 120);

        return $this;
    }

    public function getChangeTitle(): ?string
    {
        return $this->changeTitle;
    }

    public static function forDelete(Note $note, ApiToken $token, ?string $comment): self
    {
        $proposal = new self($note, $token, null, null, null, $comment);
        $proposal->type = self::TYPE_DELETE;

        return $proposal;
    }

    /**
     * A staleness report: an agent that knows a note is wrong but not what
     * replaces it. It is a proposal because it belongs in the same queue and
     * under the same review, and its own type because approving it must not
     * claim to have changed a note it never touched.
     */
    public static function forReport(Note $note, ApiToken $token, string $comment): self
    {
        $proposal = new self($note, $token, null, null, null, $comment);
        $proposal->type = self::TYPE_REPORT;

        return $proposal;
    }

    public static function forMerge(Note $absorb, Note $into, ApiToken $token, ?string $mergedBodyMd, ?string $comment): self
    {
        $proposal = new self($absorb, $token, null, $mergedBodyMd, null, $comment);
        $proposal->type = self::TYPE_MERGE;
        $proposal->mergeIntoNote = $into;

        return $proposal;
    }

    /**
     * Audit record for a curator create that already happened: unlike a held
     * create, the note exists — the row points at it and is born applied.
     *
     * @param string[] $tags
     */
    public static function forAppliedCreate(Note $note, ApiToken $token, string $title, string $bodyMd, array $tags): self
    {
        $proposal = new self($note, $token, $title, $bodyMd, $tags, null);
        $proposal->type = self::TYPE_CREATE;
        $proposal->markApplied(null, null, null);

        return $proposal;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Snapshot the pre-apply state and flip this row into an audit record.
     *
     * @param string[]|null $prevTags
     */
    public function markApplied(?string $prevTitle, ?string $prevBodyMd, ?array $prevTags, ?string $prevSummary = null): void
    {
        $this->status = self::STATUS_APPLIED;
        $this->prevSummary = $prevSummary;
        $this->appliedAt = new \DateTimeImmutable();
        $this->prevTitle = $prevTitle;
        $this->prevBodyMd = $prevBodyMd;
        $this->prevTags = $prevTags;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isApplied(): bool
    {
        return $this->status === self::STATUS_APPLIED;
    }

    public function getAppliedAt(): ?\DateTimeImmutable
    {
        return $this->appliedAt;
    }

    public function getPrevTitle(): ?string
    {
        return $this->prevTitle;
    }

    public function getPrevBodyMd(): ?string
    {
        return $this->prevBodyMd;
    }

    /** @return string[]|null */
    public function getPrevTags(): ?array
    {
        return $this->prevTags;
    }

    public function getMergeIntoNote(): ?Note
    {
        return $this->mergeIntoNote;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNote(): Note
    {
        return $this->note;
    }

    /**
     * The connection that filed this, or NULL when memex filed it itself.
     *
     * Null has exactly one meaning and could not have any other: every other
     * way of creating a proposal goes through a bearer token, so nothing else
     * in the system can produce a row without one. What produces them is the
     * scheduled enrichment pass (2026-08-23) — the server acting on a team's
     * own decision with a team's own key, belonging to no connection.
     *
     * Prefer {@see authorName()}, {@see actor()} and {@see describedBy()} to
     * reading this and branching: they are the three questions the apply path
     * and the inbox actually ask, and answering them here is what stops the
     * null leaking into call sites that would each have to remember.
     */
    public function getProposedByToken(): ?ApiToken
    {
        return $this->proposedByToken;
    }

    public function getProposedTitle(): ?string
    {
        return $this->proposedTitle;
    }

    public function getProposedBodyMd(): ?string
    {
        return $this->proposedBodyMd;
    }

    /**
     * Record what an anchored patch actually resolved to, at the moment it was
     * applied.
     *
     * A patch is stored unresolved on purpose — that is what lets two held
     * edits land on the same note without destroying each other. But once it
     * HAS been applied, "the operations somebody wrote" is no longer the whole
     * truth of what happened, and it is the only truth this row kept: the
     * activity log then had a proposal with a null body, a null title, null
     * tags and a null summary, so the row offered "(show diff)" and expanded
     * to nothing at all.
     *
     * Re-resolving the patch at read time cannot fix that — it would resolve
     * against the note as it stands TODAY, which is not what was applied.
     * So the resolution is written down here, once, by the apply path.
     *
     * The patch itself is kept beside it: it records how the edit was written,
     * which the inbox still reads while the proposal is pending.
     */
    public function recordResolvedBody(string $body): void
    {
        $this->proposedBodyMd = $body;
    }

    /** The operator's rewrite at approval. Replaces whatever the proposal said, patch included. */
    /**
     * Replace what this proposal would do with what the operator wants done.
     *
     * Every field the proposal can carry, not the body alone: a proposal that
     * files the right text under the wrong tags, or with a summary that
     * describes something else, is one the operator can only reject and
     * re-type otherwise — which is the gap the amend box existed to close and
     * closed for one field out of four.
     *
     * A null argument leaves that field as filed. Passing the body clears the
     * patch: an amendment is the operator's own text, so there is no anchor
     * left to resolve.
     *
     * @param string[]|null $tags a full replacement list, as everywhere else
     */
    public function amend(?string $title, ?string $body, ?array $tags, ?string $summary): void
    {
        if (!in_array($this->type, [self::TYPE_EDIT, self::TYPE_MERGE], true)) {
            // A delete proposes no content and a report proposes no change at
            // all, so there is nothing on either to edit. Approving them is a
            // yes or a no, and the note itself is editable where notes are.
            throw new \InvalidArgumentException('Only an edit or a merge carries text to amend before approval');
        }
        if ($this->type === self::TYPE_MERGE && $title !== null) {
            // Approving a merge produces a new version of the note that
            // survives, and the operator may take over any of it — body, tags
            // or description (operator, 2026-09-07). Not the TITLE: the card
            // is headed by the keeper's own name, which the merge never
            // proposes to change, so a title here would be an edit nothing on
            // screen asked about.
            throw new \InvalidArgumentException('A merge cannot be amended in the title of the note that is kept');
        }
        $changed = false;
        if ($title !== null) {
            $changed = $changed || $title !== $this->effectiveTitle();
            $this->proposedTitle = $title;
        }
        if ($body !== null) {
            $changed = $changed || $body !== $this->effectiveBody();
            $this->proposedBodyMd = $body;
            $this->proposedPatch = null;
        }
        if ($tags !== null) {
            // As a SET. `amendedFields` sends a full replacement list, so
            // removing a tag and adding it back reorders it and nothing else —
            // an operator who changed their mind twice is not an operator who
            // rewrote the proposal (Codex, 2026-09-07).
            $changed = $changed || self::asSet($tags) !== self::asSet($this->effectiveTags());
            $this->proposedTags = $tags;
        }
        if ($summary !== null) {
            // Blank is ABSENT, the same reading NoteWriter::normaliseSummary()
            // applies on the way in. Compared raw, an echo carrying `""` for a
            // note that has no description was a rewrite that wrote nothing
            // (Codex, 2026-09-07).
            $changed = $changed || self::blankIsAbsent($summary) !== self::blankIsAbsent($this->effectiveSummary());
            $this->proposedSummary = $summary;
        }
        $this->amended = $this->amended || $changed;
    }

    /**
     * The document this proposal would leave behind, field by field — what the
     * unlocked pane is seeded from, and therefore what an amendment is measured
     * against.
     *
     * Here rather than in the controller because it is the pair to `amend()`:
     * a payload that echoes the proposed text back unchanged recorded "applied
     * their own text" on the revision and in the journal, so the only thing
     * telling an amendment from an approval was the client's own diff (Codex,
     * 2026-09-07). One enforcement point, the way the review gate has one.
     *
     * A merge's fields belong to the note that SURVIVES; an edit's to the note
     * it targets.
     */
    private function subject(): ?Note
    {
        return $this->type === self::TYPE_MERGE ? $this->mergeIntoNote : $this->note;
    }

    private function effectiveTitle(): ?string
    {
        return $this->proposedTitle ?? $this->subject()?->getTitle();
    }

    private function effectiveBody(): ?string
    {
        $subject = $this->subject();
        if ($this->type !== self::TYPE_MERGE && $this->proposedPatch !== null && $subject !== null) {
            try {
                return NotePatch::apply($subject->getBodyMd(), $this->proposedPatch);
            } catch (\Throwable) {
                // A moved anchor: the approval is about to be refused anyway,
                // and guessing here would be the one reading that could call a
                // real amendment an echo.
                return null;
            }
        }

        return $this->proposedBodyMd ?? $subject?->getBodyMd();
    }

    /** @return string[] */
    private function effectiveTags(): array
    {
        if ($this->proposedTags !== null) {
            return $this->proposedTags;
        }
        $keeper = $this->subject();
        $own = $keeper === null ? [] : array_map(static fn (Tag $tag): string => $tag->getName(), $keeper->getTags()->toArray());
        if ($this->type !== self::TYPE_MERGE || $this->note === null) {
            return $own;
        }

        // What approving the merge would produce: the keeper's tags plus the
        // ones the absorbed note brings.
        return array_values(array_unique(array_merge(
            $own,
            array_map(static fn (Tag $tag): string => $tag->getName(), $this->note->getTags()->toArray()),
        )));
    }

    private function effectiveSummary(): ?string
    {
        // A merge proposes no description of its own — the card shows the
        // keeper's, unchanged — so anything sent for one is the operator's.
        return $this->type === self::TYPE_MERGE
            ? $this->subject()?->getSummary()
            : ($this->proposedSummary ?? $this->subject()?->getSummary());
    }

    private static function blankIsAbsent(?string $summary): ?string
    {
        $summary = $summary === null ? null : trim($summary);

        return $summary === '' ? null : $summary;
    }

    /**
     * @param string[] $tags
     * @return string[]
     */
    private static function asSet(array $tags): array
    {
        $set = array_values(array_unique($tags));
        sort($set);

        return $set;
    }

    /**
     * The AUTHOR's own further edit, folded into the draft they already have
     * in review.
     *
     * The operator sees one document per note per connection, because that is
     * how many documents there are: until a verdict lands the note belongs to
     * whoever is drafting it, and their second thoughts are versions of one
     * change rather than a queue of rival changes. Four full-body proposals
     * accumulated on one note on 2026-09-08, each a snapshot of the note as it
     * stood hours earlier, so approving the newest and then any older one
     * would have reverted the note — with nothing on any card saying the four
     * overlapped.
     *
     * Null leaves a field as it stands, the same reading an edit has
     * everywhere else. The exception is the BODY, where the two ways of saying
     * what the text becomes have to resolve to one: a whole body replaces
     * whatever was there, anchors sent against anchors accumulate in order,
     * and anchors sent against a held body replace it, because they were
     * written against the note rather than against the draft.
     *
     * Distinct from {@see amend()}, which is the OPERATOR taking over the text
     * at approval and marks the revision as theirs. This is the author still
     * writing, so it marks nothing.
     *
     * @param array<int, array{find: string, replace: string}>|null $patch
     * @param string[]|null $tags
     */
    public function revise(
        ?string $title,
        ?string $bodyMd,
        ?array $patch,
        ?array $tags,
        ?string $summary,
        ?string $comment,
        ?string $changeTitle,
    ): void {
        if ($title !== null) {
            $this->proposedTitle = $title;
        }
        if ($bodyMd !== null) {
            $this->proposedBodyMd = $bodyMd;
            $this->proposedPatch = null;
        } elseif ($patch !== null) {
            $this->proposedPatch = $this->proposedBodyMd === null
                ? array_merge($this->proposedPatch ?? [], $patch)
                : $patch;
            $this->proposedBodyMd = null;
        }
        if ($tags !== null) {
            $this->proposedTags = $tags;
        }
        if ($summary !== null) {
            $this->proposedSummary = $summary;
        }
        if ($comment !== null && $comment !== '') {
            $this->comment = $comment;
        }
        if ($changeTitle !== null && trim($changeTitle) !== '') {
            $this->withChangeTitle($changeTitle);
        }
        ++$this->revision;
        $this->revisedAt = new \DateTimeImmutable();
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getRevisedAt(): ?\DateTimeImmutable
    {
        return $this->revisedAt;
    }

    public function isAmended(): bool
    {
        return $this->amended;
    }

    /**
     * The operator replaced the proposed text before approving.
     *
     * Not a column: a proposal is amended and applied inside one request, and
     * the row is removed on apply. What outlives it is the mark this puts on
     * the note's revision.
     */
    private bool $amended = false;

    /** @return array<int, array{find: string, replace: string}>|null */
    public function getProposedPatch(): ?array
    {
        return $this->proposedPatch;
    }

    /** @param array<int, array{find: string, replace: string}>|null $patch */
    public function withProposedPatch(?array $patch): self
    {
        $this->proposedPatch = $patch;

        return $this;
    }

    /** What the inbox calls the author: a connection's chosen name, or `memex`. */
    public function authorName(): string
    {
        return $this->proposedByToken?->displayName() ?? Note::SUMMARY_BY_MEMEX;
    }

    /**
     * The `notes.last_actor` this proposal produces when it is applied.
     *
     * `Note::actorFor(null, null)` answers `human`, which is right for somebody
     * typing and wrong here: approving is a gate, not authorship, and the
     * writer was memex.
     */
    public function actor(): string
    {
        return $this->proposedByToken === null
            ? Note::ACTOR_MEMEX
            : Note::actorFor(null, $this->proposedByToken);
    }

    /** Who gets credited with a description this proposal carries. */
    public function describedBy(): string
    {
        return $this->proposedByToken?->getName() ?? Note::SUMMARY_BY_MEMEX;
    }

    public function getProposedSummary(): ?string
    {
        return $this->proposedSummary;
    }

    public function getPrevSummary(): ?string
    {
        return $this->prevSummary;
    }

    public function withProposedSummary(?string $summary): self
    {
        $this->proposedSummary = $summary;

        return $this;
    }

    /** @return string[]|null */
    public function getProposedTags(): ?array
    {
        return $this->proposedTags;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
