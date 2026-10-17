<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\NotePatch;
use Doctrine\ORM\Mapping as ORM;

/**
 * A held-for-review agent change to an existing note (PRODUCT.md §Review
 * gate: "the proposal is held, not applied, until approved"). Five types:
 * 'edit' (null fields = leave untouched; tags is a full replacement list,
 * properties a change set),
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
     * The properties this edit sets, by name, and null for each it removes
     * ({@see \App\Service\NoteProperties::changes()}); a name it does not
     * mention is kept. A change set rather than a whole set, so two
     * connections' waiting changes to different properties both land, and
     * approving one never undoes a property changed since. On a merge, only
     * the owner's amendment sets it: the keeper's properties as a whole.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $proposedProperties = null;

    /** The properties this change replaced, for an applied row's audit. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $prevProperties = null;

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
     * The note as its author read it: every field of the note this draft was
     * written against, captured when it was filed and again whenever the
     * author revises its text (operator, 2026-10-13).
     *
     * Without it memex could not tell a draft written against the note as it
     * stands from one written against text somebody has since changed. On
     * 2026-10-13 a curator rewrote the sentence an agent's held patch quoted;
     * the patch could then be neither approved nor revised, and nothing said
     * why. With it the draft is STALE ({@see staleFields()}), the inbox shows
     * what changed since, and the author's next edit replaces it.
     *
     * A merge's base is the note that survives, plus the absorbed note's body
     * its merged text was built from. Null on a row filed before 2026-10-13,
     * and on deletes and reports, which carry no text to go stale.
     *
     * @var array{version: int, title: string, body_md: string, tags: string[], summary: ?string, properties?: array<string, mixed>, absorbed_body_md?: string}|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $base = null;

    /**
     * Not a column: the edit as its author filed it, kept by {@see amend()} for
     * the approval in the same request, which records the author's change and
     * then the owner's words as two revisions.
     *
     * @var array{title: ?string, body: ?string, patch: ?array, tags: ?array, summary: ?string, properties: ?array, owner: array{title: ?string, body_md: ?string, tags: ?array, summary: ?string, properties: ?array}}|null
     */
    private ?array $filed = null;

    /** Not a column: the stale draft this one replaced, for the reply to its author. */
    private ?array $replacedStale = null;

    /** @var array<int, array<string, mixed>> Not a column: other drafts this write left stale. */
    private array $leftStale = [];

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
        $proposal->rebase();

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
     * @param array<string, mixed>|null $prevProperties
     */
    public function markApplied(?string $prevTitle, ?string $prevBodyMd, ?array $prevTags, ?string $prevSummary = null, ?array $prevProperties = null): void
    {
        $this->status = self::STATUS_APPLIED;
        $this->prevProperties = $prevProperties === [] ? null : $prevProperties;
        $this->base = null;
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
    /**
     * Apply this edit as the operations that make it, each narrowed to the
     * words it changes ({@see \App\Service\SurgicalEdit}): what it does to the
     * note it was written against is unchanged, and approving it later lays
     * only those words onto the note as it then stands.
     *
     * A whole body stays beside its operations, as sent: it is the author's
     * own wording when the operations no longer fit, and it keeps a body's
     * revision rule, that anchors sent next replace it ({@see revise()}).
     *
     * @param array<int, array{find: string, replace: string}> $operations
     */
    public function narrowTo(array $operations): void
    {
        $this->proposedPatch = $operations;
    }

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
     * @param array<string, mixed>|null $properties the note's whole set as the owner leaves it
     */
    public function amend(?string $title, ?string $body, ?array $tags, ?string $summary, ?array $properties = null): void
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
        $this->filed ??= [
            'title' => $this->proposedTitle,
            'body' => $this->proposedBodyMd,
            'patch' => $this->proposedPatch,
            'tags' => $this->proposedTags,
            'summary' => $this->proposedSummary,
            'properties' => $this->proposedProperties,
            'owner' => ['title' => null, 'body_md' => null, 'tags' => null, 'summary' => null, 'properties' => null],
        ];
        $this->filed['owner'] = [
            'title' => $title ?? $this->filed['owner']['title'],
            'body_md' => $body ?? $this->filed['owner']['body_md'],
            'tags' => $tags ?? $this->filed['owner']['tags'],
            'summary' => $summary ?? $this->filed['owner']['summary'],
            'properties' => $properties ?? $this->filed['owner']['properties'],
        ];
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
        if ($properties !== null) {
            // As a set of names: an echo that only moved a row is no rewrite.
            $changed = $changed || !\App\Service\NoteProperties::same($properties, $this->effectiveProperties());
            $this->proposedProperties = $this->type === self::TYPE_MERGE
                ? $properties
                : \App\Service\NoteProperties::diff($this->subject()?->getProperties() ?? [], $properties);
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
        $keeper = $this->subject();
        $own = $keeper === null ? [] : array_map(static fn (Tag $tag): string => $tag->getName(), $keeper->getTags()->toArray());
        if ($this->proposedTags !== null) {
            // What the owner was shown: an edit's tags as what they add and
            // remove, on the note as it is now.
            return $this->type === self::TYPE_EDIT ? ($this->tagsAgainst($own) ?? $own) : $this->proposedTags;
        }
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

    /**
     * The properties approving this would leave: an edit's changes on the note
     * as it is now; a merge's, the keeper's with the absorbed note's beside
     * them, or the owner's own set.
     *
     * @return array<string, mixed>
     */
    public function effectiveProperties(): array
    {
        $own = $this->subject()?->getProperties() ?? [];
        if ($this->type === self::TYPE_MERGE) {
            return $this->proposedProperties ?? self::mergedProperties($own, $this->note->getProperties());
        }

        return $this->proposedProperties === null ? $own : \App\Service\NoteProperties::apply($own, $this->proposedProperties);
    }

    /**
     * A merge keeps the keeper's properties and takes the absorbed note's for
     * the names the keeper lacks.
     *
     * @param array<string, mixed> $keeper
     * @param array<string, mixed> $absorbed
     * @return array<string, mixed>
     */
    public static function mergedProperties(array $keeper, array $absorbed): array
    {
        return $keeper + $absorbed;
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
     * @param array<string, mixed>|null $properties changes, laid over the ones already held
     */
    public function revise(
        ?string $title,
        ?string $bodyMd,
        ?array $patch,
        ?array $tags,
        ?string $summary,
        ?string $comment,
        ?string $changeTitle,
        ?array $properties = null,
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
        if ($properties !== null) {
            $held = $this->proposedProperties ?? [];
            foreach ($properties as $name => $value) {
                $held[$name] = $value;
            }
            $this->proposedProperties = $held;
        }
        if ($comment !== null && $comment !== '') {
            $this->comment = $comment;
        }
        if ($changeTitle !== null && trim($changeTitle) !== '') {
            $this->withChangeTitle($changeTitle);
        }
        // New text is written against the note in front of its author. A
        // revision of the reasoning alone is not: it reread nothing.
        if ($title !== null || $bodyMd !== null || $patch !== null || $tags !== null || $summary !== null || $properties !== null) {
            // Tags this revision leaves alone keep what they add and remove,
            // against the note as it is now rather than as it was first read.
            $read = $this->base['tags'] ?? null;
            $kept = $tags === null && $this->proposedTags !== null && is_array($read)
                ? [array_values(array_diff($this->proposedTags, $read)), array_values(array_diff($read, $this->proposedTags))]
                : null;
            $this->rebase();
            if ($kept !== null && is_array($this->base['tags'] ?? null)) {
                [$added, $removed] = $kept;
                $this->proposedTags = array_values(array_unique([...array_diff($this->base['tags'], $removed), ...$added]));
            }
        }
        ++$this->revision;
        $this->revisedAt = new \DateTimeImmutable();
    }

    /** Take the note as it now stands as what this draft is written against. */
    public function rebase(): void
    {
        $subject = $this->subject();
        if ($subject === null || !in_array($this->type, [self::TYPE_EDIT, self::TYPE_MERGE], true)) {
            return;
        }
        $base = ['version' => $subject->getVersion()] + self::fieldsOf($subject);
        if ($this->type === self::TYPE_MERGE) {
            $base['absorbed_body_md'] = $this->note->getBodyMd();
        }
        $this->base = $base;
    }

    /** @return array{version: int, title: string, body_md: string, tags: string[], summary: ?string, properties?: array<string, mixed>, absorbed_body_md?: string}|null */
    public function getBase(): ?array
    {
        return $this->base;
    }

    /**
     * The fields this draft changes that have changed in the note since it was
     * written: `title`, `body`, `tags`, `summary`, and for a merge that brings
     * its own text `absorbed_body`, the absorbed note's text it was built from.
     *
     * By content rather than by version: the version moves for a change to
     * any field, and a tag added beside a body edit has not made that edit
     * stale. Empty for a draft with no base, which cannot be judged and is
     * not called stale on a guess.
     *
     * @return string[]
     */
    public function staleFields(): array
    {
        $subject = $this->subject();
        if ($this->base === null || $subject === null || $this->status !== self::STATUS_HELD) {
            return [];
        }
        $base = $this->base;
        $now = self::fieldsOf($subject);
        $stale = [];
        if ($this->proposedTitle !== null && $now['title'] !== $base['title']) {
            $stale[] = 'title';
        }
        if (($this->proposedBodyMd !== null || $this->proposedPatch !== null) && $now['body_md'] !== $base['body_md']
            && !$this->bodyUntouchedSince($base['body_md'], $now['body_md'])) {
            $stale[] = 'body';
        }
        // Tags are not here: an edit applies what it added and removed
        // ({@see tagsAgainst()}), so a tag changed since does not undo anything.
        if ($this->type === self::TYPE_EDIT && $this->proposedSummary !== null
            && self::blankIsAbsent($now['summary']) !== self::blankIsAbsent($base['summary'])) {
            $stale[] = 'summary';
        }
        // A property this edit changes that someone else has changed since; a
        // draft from before properties recorded none, and is not judged.
        if ($this->type === self::TYPE_EDIT && $this->proposedProperties !== null && \is_array($base['properties'] ?? null)) {
            foreach (array_keys($this->proposedProperties) as $name) {
                if (!\App\Service\NoteProperties::same($now['properties'][$name] ?? null, $base['properties'][$name] ?? null)) {
                    $stale[] = 'properties';
                    break;
                }
            }
        }
        if ($this->type === self::TYPE_MERGE && $this->proposedBodyMd !== null
            && array_key_exists('absorbed_body_md', $base) && $this->note->getBodyMd() !== $base['absorbed_body_md']) {
            $stale[] = 'absorbed_body';
        }

        return $stale;
    }

    /**
     * Whether the note changed only where this edit does not: every sentence
     * its anchors hold is still in the note as written, and the anchors still
     * fit. Another connection's approved edit to a different sentence, or the
     * owner's, does not make it out of date (operator, 2026-10-14). A whole
     * body holds every sentence, so any change does.
     */
    private function bodyUntouchedSince(string $read, string $now): bool
    {
        if ($this->type !== self::TYPE_EDIT || $this->proposedPatch === null) {
            return false;
        }
        try {
            NotePatch::apply($now, $this->proposedPatch);
        } catch (\Throwable) {
            return false;
        }
        foreach (\App\Service\SurgicalEdit::sentencesAt($read, \App\Service\SurgicalEdit::claims($read, $this->proposedPatch)) as $sentence) {
            if ($sentence !== '' && !str_contains($now, $sentence)) {
                return false;
            }
        }

        return true;
    }

    public function isStale(): bool
    {
        return $this->staleFields() !== [];
    }

    /** @param array<string, mixed> $draft the stale draft this one replaced, as its author last saw it */
    public function replacing(array $draft): self
    {
        $this->replacedStale = $draft;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getReplacedStale(): ?array
    {
        return $this->replacedStale;
    }

    /** @param array<int, array<string, mixed>> $drafts other authors' drafts this write left stale */
    public function leaving(array $drafts): self
    {
        $this->leftStale = $drafts;

        return $this;
    }

    /** @return array<int, array<string, mixed>> */
    public function getLeftStale(): array
    {
        return $this->leftStale;
    }

    /** @return array{title: string, body_md: string, tags: string[], summary: ?string, properties: array<string, mixed>} */
    private static function fieldsOf(Note $note): array
    {
        return [
            'title' => $note->getTitle(),
            'body_md' => $note->getBodyMd(),
            'tags' => array_values(array_map(static fn (Tag $tag): string => $tag->getName(), $note->getTags()->toArray())),
            'summary' => $note->getSummary(),
            'properties' => $note->getProperties(),
        ];
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

    /** @return array<string, mixed>|null */
    public function getProposedProperties(): ?array
    {
        return $this->proposedProperties;
    }

    /** @param array<string, mixed>|null $changes */
    public function withProposedProperties(?array $changes): self
    {
        $this->proposedProperties = $changes === [] ? null : $changes;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getPrevProperties(): ?array
    {
        return $this->prevProperties;
    }

    /**
     * The edit as its author filed it, when the owner amended it in this
     * request; null otherwise.
     *
     * @return array{title: ?string, body: ?string, patch: ?array, tags: ?array, summary: ?string, properties: ?array, owner: array{title: ?string, body_md: ?string, tags: ?array, summary: ?string, properties: ?array}}|null
     */
    public function filedBeforeAmendment(): ?array
    {
        return $this->amended ? $this->filed : null;
    }

    /**
     * The tags to give a note that now carries $current: what this edit added
     * and removed since its author read the note, so two connections' waiting
     * tag changes both land instead of the later list replacing the earlier
     * (operator, 2026-10-14). A draft that recorded no base is the full list
     * it always was.
     *
     * @param string[] $current
     * @param string[]|null $proposed defaults to this edit's own list
     * @return string[]|null
     */
    public function tagsAgainst(array $current, ?array $proposed = null): ?array
    {
        $proposed ??= $this->proposedTags;
        $read = $this->base['tags'] ?? null;
        if ($proposed === null || !is_array($read)) {
            return $proposed;
        }
        $removed = array_diff($read, $proposed);
        $added = array_diff($proposed, $read);

        return array_values(array_unique([...array_diff($current, $removed), ...$added]));
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
