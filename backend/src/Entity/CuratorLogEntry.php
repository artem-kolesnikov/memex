<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One row of the Curator log — the single chronological record of everything
 * the curator does (operator-directed 2026-08-06: "all curator activities
 * listed there, not in MD files"). Rows are written by the system at each
 * curator event (auto-applied writes, held proposals filed, operator
 * decisions on them) and by the curator itself via the MCP `log` verb
 * (run summaries, questions, observations, tooling gaps) — which replaced
 * the journal notes. Append-only by design; token/note names are copied so
 * rows outlive what they reference.
 */
#[ORM\Entity]
#[ORM\Table(name: 'curator_log')]
#[ORM\Index(name: 'idx_curator_log_created', columns: ['created_at'])]
// "When was this note last curated?" is derived from this log rather than
// stored on the note — so the per-note lookup is a hot path for queue
// producers, not an occasional query.
#[ORM\Index(name: 'idx_curator_log_note', columns: ['note_id'])]
// Partial: precedent rows are a small fraction of the log and are read as a
// set ("what has the operator made standing?").
#[ORM\Index(name: 'idx_curator_log_precedent', columns: ['created_at'], options: ['where' => 'is_precedent'])]
#[ORM\Index(name: 'idx_curator_log_retired_note', columns: ['retired_note_id'], options: ['where' => 'retired_note_id IS NOT NULL'])]
// Partial: `examined` rows are the only ones that carry a run, and the digest
// reads them a whole run at a time. Declared here as well as in the migration
// because an index Doctrine does not know about is one it proposes to DROP —
// which is what SchemaToolSafetyTest exists to catch, and did.
#[ORM\Index(name: 'idx_curator_log_run', columns: ['run_id'], options: ['where' => 'run_id IS NOT NULL'])]
// Partial, and for the same reason: the "curation only" filter asks for
// stamped rows alone, and on a log that is mostly ad-hoc agent work the
// stamped half is the small one.
#[ORM\Index(name: 'idx_curator_log_curation_run', columns: ['curation_run_id'], options: ['where' => 'curation_run_id IS NOT NULL'])]
class CuratorLogEntry
{
    // System-written actions
    public const ACTION_CREATE = 'create';
    public const ACTION_EDIT = 'edit';
    public const ACTION_EDIT_PROPOSED = 'edit-proposed';
    public const ACTION_DELETE_PROPOSED = 'delete-proposed';
    public const ACTION_MERGE_PROPOSED = 'merge-proposed';
    public const ACTION_REPORTED = 'reported';
    public const ACTION_APPROVED = 'approved';
    public const ACTION_REJECTED = 'rejected';
    /**
     * The operator flagging a note for priority curation, and the answer that
     * closes it (2026-08-17). These sit in the log rather than only in
     * `curation_flags` because the log is what a run actually reads at
     * bootstrap: a flag raised overnight reaches the curator the same way a
     * verdict does, whether or not the pass ever gets as far as the queue
     * verbs. `flag-resolved` is written by the system when the curator calls
     * `resolve_curation_flag`, or when the operator withdraws the flag — which
     * is why it is not in AGENT_ACTIONS.
     */
    public const ACTION_FLAG_RAISED = 'flag-raised';
    public const ACTION_FLAG_RESOLVED = 'flag-resolved';
    /**
     * The owner reshaping their own vocabulary (2026-08-23): a tag taken off
     * every note that carried it, or moved into another tag.
     *
     * These are in the log because they are the largest single edit anybody
     * can make here — one click rewrites every note with that word — and
     * unlike every other bulk change in memex they cannot be undone. The row
     * is what the owner has afterwards to check the count against.
     *
     * They carry no note: the act is about the vocabulary, and attaching one
     * of ninety notes would be arbitrary.
     */
    public const ACTION_TAG_REMOVED = 'tag-removed';
    public const ACTION_TAG_MERGED = 'tag-merged';
    /**
     * A scheduled enrichment pass, one row per RUN (2026-08-23).
     *
     * Per run rather than per note: twenty-five rows a night would bury
     * everything else the journal is read for. The row is what makes an
     * unattended pass visible at all — it says how many notes were looked at
     * and how many proposals are waiting, which is the answer to "did anything
     * happen overnight" without opening the inbox.
     *
     * Written as `memex`, with no token, because a pass belongs to no
     * connection — the same authorship the proposals themselves carry.
     */
    public const ACTION_ENRICHMENT_RUN = 'enrichment-run';
    /**
     * RETIRED 2026-08-24, the same day it shipped. One curation task,
     * finished — written by the `curation_done` verb of the one-task curation
     * surface, which the operator withdrew: curation is a deliberate in-depth
     * pass over the collection, not a fix-one-and-stop loop, and two curation
     * surfaces contradicted each other in the instructions agents are served.
     *
     * The constant stays because rows written before the withdrawal are still
     * in the log and must keep rendering and keep counting as curation work.
     * Nothing writes it any more; {@see self::ACTION_EXAMINED} is what a pass
     * writes now.
     */
    public const ACTION_CURATION_TASK = 'curation-task';
    /**
     * "A pass read this note and it needed nothing" (2026-08-24).
     *
     * The gap this closes was found by auditing a real unattended run: the log
     * records only what the curator WROTE, so a note read and passed over left
     * no trace at all. Every reader of "when was this note last curated" —
     * the candidate cooldown, blast radius' already-handled test,
     * `last_curated` — therefore could not tell a note nobody had ever opened
     * from one three passes had opened and approved of. 76 notes read
     * `last_curated_at: null` for that reason.
     *
     * It is also the counter the escalating cooldown runs on. A note whose
     * newest log rows are an unbroken run of these is one successive passes
     * keep finding nothing wrong with, and it earns a longer rest each time
     * (see {@see \App\Service\CurationQueue::REST_LADDER}). Any row that is
     * NOT this one breaks the streak, which is the intended meaning: the
     * moment a note needs work, it goes back to the front of the rotation.
     *
     * Written only by the `log` verb, from a run-summary's `examined` list, so
     * a pass records its whole sweep in the call it was already making.
     */
    public const ACTION_EXAMINED = 'examined';
    /**
     * Curator-written actions (MCP `log` verb). Deliberately NO 'question':
     * nothing polls this log on the operator's behalf, so an open question
     * would sit unanswered (operator-caught design flaw, 2026-08-06).
     * Uncertainty is expressed as a HELD proposal instead — the operator's
     * approve/reject verdict is the answer, and it flows back into this log
     * for the curator's next run, now carrying the operator's own reasoning
     * (`operatorComment`, 2026-08-09) rather than a bare yes or no.
     */
    public const AGENT_ACTIONS = ['run-summary', 'observation', 'tooling-gap'];

    /**
     * Rows that record work being ASKED FOR rather than done, and therefore do
     * not answer "when was this note last curated".
     *
     * Found the hour flags shipped (2026-08-17): the operator flagged a note,
     * and the queue immediately reported `last_curated_at` equal to the second
     * they pressed the button — because `flag-raised` is a `curator_log` row
     * with a `note_id`, and every "last curated" query in this codebase is
     * `MAX(created_at)` over exactly that. The flag itself was still returned
     * (an open flag ignores the cooldown, which is the only reason the bug did
     * not also hide the note it was raised about), but two other readers were
     * silently wrong: `blast_radius` treats "curated since the neighbour moved"
     * as handled, and `last_curated` is the verb the curator uses to decide what
     * it has already seen. Asking for attention is not receiving it.
     *
     * `flag-resolved` deliberately stays IN: the curator answering a flag is
     * work, and it is the record of the note having been dealt with. An
     * operator withdrawing a flag writes the same action and is the one
     * imprecision here — it puts a note on cooldown for a week — which is
     * harmless in a way the inverse would not be.
     */
    public const NOT_CURATION_ACTIONS = [
        self::ACTION_FLAG_RAISED,
        // The owner's own vocabulary edits. They touch many notes and none in
        // particular, so counting one as "the curator worked this note" would
        // be wrong in both directions. In practice they carry no note either,
        // so this is the meaning being written down rather than a behaviour
        // being changed.
        self::ACTION_TAG_REMOVED,
        self::ACTION_TAG_MERGED,
        // A pass proposes; it does not curate. Counting a run as "the curator
        // worked this note" would put every note it looked at on cooldown,
        // and the row carries no note in any case.
        self::ACTION_ENRICHMENT_RUN,
    ];

    /**
     * SQL predicate keeping only rows that mean "the curator worked this note".
     * One definition, because three queries ask the same question and a fourth
     * will: candidates' cooldown, blast radius' already-handled test, and the
     * `last_curated` verb.
     */
    public static function curationWorkOnly(string $alias): string
    {
        return $alias.".action NOT IN ('".implode("', '", self::NOT_CURATION_ACTIONS)."')";
    }

    /** Every action a row can carry — the filter vocabulary for reading the log. */
    public const ACTIONS = [
        self::ACTION_CREATE,
        self::ACTION_EDIT,
        self::ACTION_EDIT_PROPOSED,
        self::ACTION_DELETE_PROPOSED,
        self::ACTION_MERGE_PROPOSED,
        self::ACTION_APPROVED,
        self::ACTION_REJECTED,
        self::ACTION_FLAG_RAISED,
        self::ACTION_FLAG_RESOLVED,
        self::ACTION_TAG_REMOVED,
        self::ACTION_TAG_MERGED,
        self::ACTION_ENRICHMENT_RUN,
        self::ACTION_CURATION_TASK,
        self::ACTION_EXAMINED,
        ...self::AGENT_ACTIONS,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Copied, not FK'd — the log outlives token rotation. */
    #[ORM\Column(length: 120)]
    private string $tokenName;

    /**
     * The connection this row is about, so the log can show what it is called
     * NOW rather than what it was called then (operator, 2026-08-23).
     *
     * `tokenName` stays as the string it always was and is the fallback: rows
     * written before this column existed have no id, and a token destroyed
     * with a closing account leaves one behind.
     */
    #[ORM\ManyToOne(targetEntity: ApiToken::class)]
    #[ORM\JoinColumn(name: 'token_id', nullable: true, onDelete: 'SET NULL')]
    private ?ApiToken $token = null;

    #[ORM\Column(length: 32)]
    private string $action;

    #[ORM\Column(type: 'text')]
    private string $description;

    /** The note this row is about, when still alive; title copy survives deletion. */
    #[ORM\ManyToOne(targetEntity: Note::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Note $note = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $noteTitle = null;

    /**
     * Which note `note_id` USED to mean, while that note sits in limbo.
     *
     * Not a relation and not an FK — see Version20260822000027. Its entire job
     * is to hold an id during the window when no such note exists, which is
     * the one thing a foreign key will not do.
     */
    #[ORM\Column(nullable: true)]
    private ?int $retiredNoteId = null;

    /** Applied proposal behind this row → the UI can expand to its diff. */
    #[ORM\ManyToOne(targetEntity: EditProposal::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EditProposal $proposal = null;

    /**
     * The operator's reasoning on an approve/reject, in their own words —
     * distinct from `description`, which states the FACT of the decision. The
     * curator reads this as the answer to what it filed; it is the only part
     * of a verdict that can teach anything.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $operatorComment = null;

    /**
     * The operator marked that reasoning as generalising beyond this case.
     * Deliberately explicit rather than inferred: left to guess which comments
     * were general, the curator guesses generously — the first watched pass
     * turned one approval into "the standing pattern" (2026-08-08).
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $isPrecedent = false;

    /**
     * What a run-summary SAYS it did — `{proposed: 3, edited: 7, held: 3}` —
     * against which the digest checks the rows the server actually wrote in
     * that run's window (ruled 2026-08-26).
     *
     * Null on every row that is not a run-summary, and on run-summaries whose
     * pass filed nothing. **Null is not zero here**, and the digest depends on
     * the difference: a pass that claims nothing carries no tripwire, while a
     * pass that claims `edited: 0` has made a statement the log can contradict.
     *
     * The keys are the charter's vocabulary rather than the schema's, which is
     * why this is one JSON column and not three integers — see
     * {@see \DoctrineMigrations\Version20260827000047}. They are validated at
     * the one place a claim can enter,
     * {@see \App\Service\McpServer::parseClaims()}.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $claims = null;

    /**
     * The run-summary this row was written FOR — set on the `examined` rows a
     * run-summary produces, null on everything else.
     *
     * It exists because those rows are persisted after the summary, in the
     * same request and so in the same second, with a higher id: any window
     * ending at the summary excludes all of them, and any window ending at the
     * NEXT summary files them under the following pass. Both were measured
     * against the real vault before this column existed (see
     * {@see \DoctrineMigrations\Version20260827000047}).
     *
     * The link was already there in prose — *"…needed nothing (log entry
     * 656)"*. This is the same sentence as data, and it is server-set, so a
     * pass cannot claim a row it did not cause.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'run_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $run = null;

    /**
     * The curation pass this row belongs to — the run-summary that declared
     * it — or null for work that was not part of a pass.
     *
     * This is what separates CURATION from ordinary agent work (operator,
     * 2026-08-29: *"curation is when charter is used, agentic is ongoing work
     * and includes anything"*). Nothing records a charter load and the same
     * connection writes both kinds of row minutes apart, so the marker is the
     * pass's own declaration: a run-summary carrying `started_at` draws a
     * window, and {@see \App\Service\CurationDigest::stampRun()} writes this
     * column over the rows inside it.
     *
     * Distinct from {@see self::$run}, which means the narrower "this row was
     * generated FOR that summary" and is only ever set on `examined` rows.
     *
     * An UNBOUNDED pass — one that did not state its start — stamps only its
     * summary and its `examined` rows, never its writes. Its fallback window
     * is everything the connection wrote since its last pass, which on a
     * connection also driven by hand is mostly not curation at all; the
     * consequence, deliberate, is that the filter under-reports such a pass
     * rather than claiming the operator's afternoon.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'curation_run_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $curationRun = null;

    /**
     * Every note this row touched, when `note_id` cannot say — a tag taken off
     * ninety notes, a tag merged into another.
     *
     * A copied list rather than a join table or foreign keys, for the reason
     * `noteTitle` is a copy: the row is a record of what was touched at the
     * time and has to outlive the notes. Ids that no longer resolve are
     * dropped when the row is read.
     *
     * @var list<int>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $affectedNoteIds = null;

    /**
     * When the pass behind this run-summary began, as the pass reported it.
     *
     * The server cannot derive this: nothing distinguishes a curator
     * connection's scheduled pass from the same connection being driven by
     * hand an hour earlier, and C-9 refused a run id in the tool schema. So
     * the boundary is the pass's own statement, BOUNDED rather than trusted —
     * clamped forward to that connection's previous run-summary by
     * {@see \App\Service\CurationDigest}, so no pass can reach back over
     * another's work.
     *
     * Null is the ordinary case for a pass that does not send one, and it is
     * not a defect: the digest then shows the fallback window and fires no
     * tripwire, because a claim must not be contradicted by rows the server
     * could not attribute to the run.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $runStartedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $tokenName, string $action, string $description)
    {
        $this->tokenName = $tokenName;
        $this->action = $action;
        $this->description = $description;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function withNote(?Note $note, ?string $titleOverride = null): self
    {
        $this->note = $note;
        $this->noteTitle = $titleOverride ?? $note?->getTitle();

        return $this;
    }

    public function getRetiredNoteId(): ?int
    {
        return $this->retiredNoteId;
    }

    /** Set as the note goes into limbo; cleared as it comes back out. */
    public function rememberRetiredNote(?int $noteId): self
    {
        $this->retiredNoteId = $noteId;

        return $this;
    }

    /**
     * @param array<string, int>|null $claims validated by the caller; an empty
     *                                        array is stored as null, because
     *                                        "claimed nothing" and "filed an
     *                                        empty object" mean the same thing
     *                                        to the digest and only one of them
     *                                        should be able to exist
     */
    public function withClaims(?array $claims): self
    {
        $this->claims = ($claims === null || $claims === []) ? null : $claims;

        return $this;
    }

    /** @return array<string, int>|null */
    public function getClaims(): ?array
    {
        return $this->claims;
    }

    /** The run-summary this row was written for — server-set, never claimed. */
    public function withRun(?self $run): self
    {
        $this->run = $run;

        return $this;
    }

    public function getRun(): ?self
    {
        return $this->run;
    }

    /** The pass this row belongs to — server-stamped from the run's window. */
    public function withCurationRun(?self $run): self
    {
        $this->curationRun = $run;

        return $this;
    }

    public function getCurationRun(): ?self
    {
        return $this->curationRun;
    }

    /**
     * @param list<int> $ids the notes this row touched; an empty list is
     *                       stored as null, because "touched nothing" and
     *                       "recorded an empty list" are the same thing to
     *                       every reader and only one of them should exist
     */
    public function withAffectedNotes(array $ids): self
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id) => $id > 0)));
        $this->affectedNoteIds = $ids === [] ? null : $ids;

        return $this;
    }

    /** @return list<int>|null */
    public function getAffectedNoteIds(): ?array
    {
        return $this->affectedNoteIds;
    }

    public function withRunStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->runStartedAt = $startedAt;

        return $this;
    }

    public function getRunStartedAt(): ?\DateTimeImmutable
    {
        return $this->runStartedAt;
    }

    public function withProposal(?EditProposal $proposal): self
    {
        $this->proposal = $proposal;

        return $this;
    }

    /**
     * Attach the operator's reasoning to a verdict row. An empty or
     * whitespace-only comment is stored as null — "" and "no comment given"
     * are the same thing to every reader, and only one of them should exist.
     * A precedent flag without a comment is dropped for the same reason: it
     * would mark reasoning that is not there.
     */
    public function withOperatorVerdict(?string $comment, bool $isPrecedent = false): self
    {
        $comment = $comment === null ? null : trim($comment);
        $this->operatorComment = ($comment === null || $comment === '') ? null : $comment;
        $this->isPrecedent = $this->operatorComment !== null && $isPrecedent;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function withToken(?ApiToken $token): self
    {
        $this->token = $token;

        return $this;
    }

    /** What to call the writer: its current name if the token survives, else the recorded string. */
    public function writerName(): string
    {
        if ($this->token === null) {
            return $this->tokenName;
        }

        return $this->token->displayName();
    }

    public function getToken(): ?ApiToken
    {
        return $this->token;
    }

    public function getTokenName(): string
    {
        return $this->tokenName;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getNote(): ?Note
    {
        return $this->note;
    }

    public function getNoteTitle(): ?string
    {
        return $this->noteTitle;
    }

    public function getProposal(): ?EditProposal
    {
        return $this->proposal;
    }

    public function getOperatorComment(): ?string
    {
        return $this->operatorComment;
    }

    public function isPrecedent(): bool
    {
        return $this->isPrecedent;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
