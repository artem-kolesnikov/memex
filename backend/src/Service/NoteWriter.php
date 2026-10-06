<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;
use App\Entity\CuratorLogEntry;
use App\Entity\EditProposal;
use App\Entity\Note;
use App\Entity\NoteRevision;
use App\Entity\Tag;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The single write path for notes — every way in (UI, upload, import, agent)
 * goes through here so the review gate and enrichment can't be skipped.
 * Token-authored notes land pending (enforced server-side, PRODUCT.md §Review
 * gate) — with ONE role-aware exception, also enforced here: curator-role
 * tokens' safe writes (create/edit) apply immediately and survive as 'applied'
 * audit rows. Deletes and merges are held for every role — the operator's
 * "no unapproved deletes" guardrail is not role-dependent.
 */
class NoteWriter
{
    /** Matches the maxLength advertised on every `summary` field in the MCP schema. */
    public const SUMMARY_MAX_CHARS = 5000;
    private const REPORT_MAX_CHARS = 2000;

    /**
     * Notes an apply path has written and deliberately left undescribed, until
     * whoever owns the outermost transaction has COMMITTED (audit A-3b,
     * 2026-08-22).
     *
     * The defect: every apply path enriched inline, and enriching is a round
     * trip to ml-processor with a 60-second timeout, so a hung provider left
     * the session idle-in-transaction holding row locks on the operator's
     * notes. Nothing on the box bounds that: there is no `statement_timeout`
     * and no `idle_in_transaction_session_timeout` configured anywhere.
     *
     * **Why a queue on the service rather than a local variable**, which is
     * what this looked like at first: an apply path does not own its
     * transaction. `ReviewVerdicts::exactlyOnce()` opens one first, so a
     * `commit()` inside the apply releases a SAVEPOINT and the outer
     * transaction is still open with every lock still held. Enriching "after
     * the commit" there would have changed nothing and looked like a fix.
     * The drain therefore happens where it really is outermost.
     *
     * Splitting is safe in this direction and not the other. Enrichment has
     * always been best-effort — `MlClient` catches everything and returns null,
     * `app:embed` sweeps behind it, and `needs_enrichment` is how an assistant
     * finds what is still undescribed — so a failure here leaves a note applied
     * and undescribed, a state the product already has a name and a queue for.
     * Enriching inside and then rolling back would discard work already paid
     * for.
     *
     * Each entry carries whether that note's BODY changed, because that is
     * what decides whether tags are worth re-reading, and only the caller
     * queuing the work still knows. Losing it here is how an approval of a
     * one-word retitle buys a fresh set of tags for text nobody touched.
     *
     * @var array<int, array{0: Note, 1: bool}>
     */
    private array $deferredEnrichment = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NoteEnricher $enricher,
        private readonly NoteLimbo $limbo,
        private readonly NoteRevisions $revisions,
        private readonly TagAdmin $tags,
        private readonly StorageLimits $storageLimits,
        private readonly GrowthLimits $growth,
        private readonly Journal $journal,
    ) {
    }

    /**
     * The name to record against a description. A token's own name, because
     * "which of my assistants wrote this" is the question a user with several
     * connectors has, and the name is the one they chose. No token means a
     * person was at the keyboard.
     */
    private static function describedBy(?ApiToken $token): string
    {
        return $token?->getName() ?? Note::SUMMARY_BY_OPERATOR;
    }

    /**
     * The one reading of a supplied summary, shared by every caller that takes
     * one (MCP, REST) so they cannot drift apart.
     *
     * Blank is absent, not "a summary that happens to be empty": a
     * whitespace-only argument must never satisfy "this note has been
     * described", because that would leave the note blank forever AND skip the
     * fallback that would have filled it. A non-string is refused rather than
     * coerced — `{"summary": ["a","b"]}` must not become the word "Array".
     */
    public static function normaliseSummary(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, self::SUMMARY_MAX_CHARS);
    }

    /**
     * Invisibles that are disposable at the EDGES of a report.
     *
     * Deliberately not all of `\p{C}`. Tag characters and variation selectors
     * CHANGE a visible glyph — trimming them turns the England flag into a
     * black flag — and the bidi isolates and embeddings place surrounding text,
     * so `RLI مرحبا PDI` trimmed to `مرحبا` renders somewhere else. All of them
     * are still nothing on their own, which is what {@see HAS_CONTENT} decides.
     */
    private const BLANK_EDGE = '[\s\p{Z}\x{200B}-\x{200D}\x{2060}\x{FEFF}\x{115F}\x{1160}\x{3164}\x{FFA0}\x{2800}]';

    /**
     * One character the operator could actually see.
     *
     * A negated class rather than an anchored `(?:blank)*` over the whole
     * string: the quantified form asks PCRE to walk a 100,000-character comment
     * with a backtracking stack and it answers `false` — neither 0 nor 1 — on
     * exhaustion, which is how a wall of variation selectors got filed as a
     * report. `\p{M}` is here and not in BLANK_EDGE: a lone combining mark is
     * content to nobody, but trimming marks off the ends takes the accent off a
     * comment ending in a decomposed é.
     */
    private const HAS_CONTENT = '[^\s\p{Z}\p{C}\p{M}\x{115F}\x{1160}\x{3164}\x{FFA0}\x{2800}]';

    /**
     * What a report actually says, with nothing invisible counting as content.
     *
     * `trim()`'s charlist is ASCII, so a comment of one non-breaking space or
     * one zero-width space survived it and was filed. The cap matches the one
     * {@see normaliseSummary()} already applies: `comment` is an unbounded text
     * column, and a report is a sentence, not a document.
     *
     * **The order matters.** Emptiness is judged AFTER the cap, or 2,000
     * variation selectors followed by one letter passes the test and then has
     * its only letter truncated away.
     */
    private static function normaliseReport(string $comment): string
    {
        $trimmed = preg_replace('/^'.self::BLANK_EDGE.'+|'.self::BLANK_EDGE.'+$/u', '', $comment);
        // preg_replace answers null on a malformed UTF-8 subject, and an
        // unreadable comment is not a report.
        if (!is_string($trimmed)) {
            return '';
        }

        if (mb_strlen($trimmed) > self::REPORT_MAX_CHARS) {
            $trimmed = self::capWholeGraphemes($trimmed);
        }

        // Anything but a definite yes is refused. preg_match answers `false`
        // rather than 0 when PCRE runs out of stack, and a comment that can
        // exhaust PCRE is not one the operator was going to read.
        return preg_match('/'.self::HAS_CONTENT.'/u', $trimmed) === 1 ? $trimmed : '';
    }

    /**
     * The cap, counted in characters a reader would call characters.
     *
     * `\X` is PCRE's own extended grapheme cluster, so this asks the engine
     * where a character ends rather than keeping a list of the things that join
     * one: a hand-written list had `\p{M}` and ZWJ and missed the skin-tone
     * modifiers and the regional indicator pairs, and could not see the case
     * where the cut drops a VISIBLE code point that was still part of the
     * cluster — `👩‍💻` split after its joiner.
     *
     * Only the head of the text is scanned. A cluster is bounded in practice
     * and the slack is generous, but a pathological one longer than the slack
     * is simply not kept, which is the safe direction.
     */
    private static function capWholeGraphemes(string $text): string
    {
        $slack = mb_substr($text, 0, self::REPORT_MAX_CHARS + 256);
        if (preg_match_all('/\X/u', $slack, $clusters) === false) {
            return '';
        }

        $out = '';
        $kept = 0;
        foreach ($clusters[0] as $cluster) {
            $length = mb_strlen($cluster);
            if ($kept + $length > self::REPORT_MAX_CHARS) {
                break;
            }
            $out .= $cluster;
            $kept += $length;
        }

        return $out;
    }

    /**
     * @param string[] $tagNames
     * @param string|null $summary operator-provided summary — authoritative when
     *                             non-empty (enrichment won't overwrite it)
     * @return array{note: Note, suggestions: array{tag_ids: int[], new_tags: string[]}}
     */
    public function create(
        ?ApiToken $token,
        string $title,
        string $bodyMd,
        string $source,
        ?string $sourceUrl,
        array $tagNames,
        ?EmbeddingSpend $enrich,
        ?string $importPath = null,
        ?string $summary = null,
        bool $applyTags = true,
        ?string $summaryBy = null,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
    ): array {
        $this->storageLimits->assertBody($bodyMd);
        $this->growth->assertNoteRoom();
        // Curator-role tokens create straight to verified (their authority IS
        // the review) unless the note would carry instructions for other
        // assistants; other tokens land pending, humans land verified.
        $status = $token !== null && (!$token->isCurator() || self::holdsInstructions($token, null, $tagNames))
            ? Note::STATUS_PENDING
            : Note::STATUS_VERIFIED;
        $note = new Note($token, $title, $bodyMd, $source, $sourceUrl, $status);
        $note->setImportPath($importPath);
        $summary = self::normaliseSummary($summary);
        if ($summary !== null) {
            // $summaryBy is for import: a description that came back in a
            // vault's frontmatter was written by whoever the export says wrote
            // it, and re-attributing it to the token doing the importing would
            // be a fresher-looking lie. Everything else passes null and gets
            // the honest default.
            $note->setSummary($summary, $summaryBy ?? self::describedBy($token));
        }

        // One transaction from tag resolution to the row: resolving a retired
        // tag un-retires it with a raw delete, and a refusal at the row must
        // take that back with it.
        $this->limbo->whileHoldingNotes([], function () use ($note, $token, $title, $bodyMd, $tagNames, $status, $createdAt, $updatedAt): void {
            foreach ($this->resolveTags($tagNames) as $tag) {
                $note->addTag($tag);
            }
            $note->carryDates($createdAt, $updatedAt);

            $this->em->persist($note);
            $this->em->flush();

            if ($token === null) {
                $this->journal->record(
                    (new CuratorLogEntry('operator', CuratorLogEntry::ACTION_CREATE, 'Created “'.$title.'”'))->withNote($note)
                );
            } elseif ($status === Note::STATUS_VERIFIED) {
                // Curator creates leave an audit trail: an already-applied 'create'
                // proposal row (prev* = null — there was nothing before) + a log row.
                $audit = EditProposal::forAppliedCreate($note, $token, $title, $bodyMd, $tagNames);
                $this->em->persist($audit);
                $this->journal->record(
                    (new CuratorLogEntry($token->getName(), CuratorLogEntry::ACTION_CREATE, 'Created “'.$title.'”'))
                        ->withToken($token)->withNote($note)->withProposal($audit)
                );
            } else {
                $this->journal->record(
                    (new CuratorLogEntry($token->getName(), CuratorLogEntry::ACTION_CREATE_PROPOSED, 'Proposed a new note, “'.$title.'” — held for review.'))
                        ->withToken($token)->withNote($note)
                );
            }
            $this->em->flush();
        });

        // Links always sync (import order doesn't matter — syncLinks catch-up
        // resolves earlier notes' links when their target arrives). Enrichment
        // is skipped for bulk import: the embed sweep is the backfill.
        $this->enricher->syncLinks($note);
        $suggestions = $enrich !== null
            ? $this->enricher->enrich($note, $enrich, generateSummary: $note->getSummary() === null, applyTags: $applyTags)
            : ['tag_ids' => [], 'new_tags' => []];
        $this->em->flush();

        return ['note' => $note, 'suggestions' => $suggestions];
    }

    /**
     * @param string[]|null $tagNames null = leave tags untouched
     * @param bool $summaryProvided true when the caller's payload carried a
     *                              summary field: non-empty = authoritative
     *                              (kept verbatim), empty = clear it, which
     *                              regenerates. Absent leaves the summary as it
     *                              is — and since the server only ever writes a
     *                              summary into a note that has none, an edit
     *                              that says nothing about the summary costs
     *                              nothing
     * @return array{note: Note, suggestions: array{tag_ids: int[], new_tags: string[]}}
     */
    public function update(
        Note $note,
        ?string $title,
        ?string $bodyMd,
        ?array $tagNames,
        // Required, and required HERE rather than among the optional tail it
        // used to sit in as `bool $enrich = true`. That default bought an
        // embedding on the operator's key unless a caller remembered to say
        // otherwise, which is precisely the shape CLAUDE.md §Spend forbids for
        // AiCredentials one layer down. Now every caller states whose spend
        // this is, or states that it is deferring. {@see EmbeddingSpend}
        ?EmbeddingSpend $enrich,
        bool $summaryProvided = false,
        ?string $summary = null,
        ?string $actor = null,
        bool $applyTags = true,
        ?string $summaryBy = null,
        ?string $changeTitle = null,
        ?ApiToken $actorToken = null,
        ?string $operation = null,
        bool $amendedByOperator = false,
    ): array {
        $this->limbo->whileHoldingNotes([$note], function () use (
            $note, $title, $bodyMd, $tagNames, $summaryProvided, $summary, $actor,
            $summaryBy, $changeTitle, $actorToken, $operation, $amendedByOperator,
        ): void {
            if ($bodyMd !== null) {
                $this->storageLimits->assertBody($bodyMd, strlen($this->lockedBody($note)));
            }
            // What the note is about to stop being. Taken here, before the first
            // setter, because this method is the codebase's only call site of
            // setTitle/setBodyMd — so a snapshot taken here cannot be bypassed by
            // any present or future write path, which is the same structural
            // argument the review gate rests on. Kept or discarded after the
            // mutation, depending on whether anything actually changed.
            // `$changeTitle` describes the edit ABOUT to happen, and is stored on
            // the snapshot of what that edit replaces — the same place `replacedBy`
            // lives, and for the same reason: a revision row answers "what was here
            // before, and what took it away".
            $previous = $this->revisions->snapshot($note, $actor, $changeTitle, $operation, $actorToken);
            if ($amendedByOperator) {
                $previous->markAmendedByOperator();
            }

            // Attribution follows the write, not the note's origin: `source` says
            // how it arrived, `lastActor` says who touched it most recently.
            //
            // Kind, assistant and person move together through one call, because a
            // caller that names one and forgets the others leaves the note saying
            // something false — see Note::attribute(), which is where that argument
            // is written out.
            if ($actor !== null) {
                $note->attribute($actor, $actorToken);
            }
            $titleBefore = $note->getTitle();
            if ($title !== null) {
                $note->setTitle($title);
            }
            if ($bodyMd !== null) {
                $note->setBodyMd($bodyMd);
            }
            if ($summaryProvided) {
                $normalised = self::normaliseSummary($summary);
                $note->setSummary($normalised, $normalised === null ? null : ($summaryBy ?? Note::SUMMARY_BY_OPERATOR));
            }
            if ($tagNames !== null) {
                $note->clearTags();
                foreach ($this->resolveTags($tagNames) as $tag) {
                    $note->addTag($tag);
                }
            }
            $this->em->flush();

            // After the mutation and before enrichment: the revision records what
            // the *operator or agent* replaced, and enrichment's regenerated
            // summary is a consequence of that edit rather than part of it.
            // A no-op update (a merge that only moved tags, a proposal whose fields
            // are all null) records nothing — see NoteRevisions::record.
            if ($this->revisions->record($previous, $note)) {
                $this->journal->record($this->editEntry($previous, $note, $operation));
                $this->em->flush();
            }

            $this->enricher->syncLinks($note);
            if ($titleBefore !== $note->getTitle()) {
                $this->enricher->relinkReferrers($note, $titleBefore);
            }
            if ($tagNames !== null) {
                $this->gcTags();
            }
        });

        $suggestions = $enrich === null ? ['tag_ids' => [], 'new_tags' => []]
            : $this->finishUpdateEnrichment($note, $bodyMd !== null, $applyTags, $enrich);

        return ['note' => $note, 'suggestions' => $suggestions];
    }

    /** The journal's line for one edit, written by whoever the revision says replaced the text. */
    private function editEntry(NoteRevision $previous, Note $note, ?string $operation): CuratorLogEntry
    {
        $changed = array_keys(array_filter([
            'title' => $previous->getTitle() !== $note->getTitle(),
            'body' => $previous->getBodyMd() !== $note->getBodyMd(),
            'tags' => self::sortedNames($previous->getTags()) !== self::sortedNames(array_map(static fn (Tag $tag): string => $tag->getName(), $note->getTags()->toArray())),
            'summary' => $previous->getSummary() !== $note->getSummary(),
        ]));
        $description = $operation === NoteRevision::OP_RESTORE
            ? 'Restored an earlier version of “'.$note->getTitle().'”'
            : 'Edited '.implode(' + ', $changed).' of “'.$note->getTitle().'”'
                .($previous->getTitle() !== $note->getTitle() ? ' (was “'.$previous->getTitle().'”)' : '');

        $token = $previous->getReplacedByToken();
        $entry = new CuratorLogEntry($token?->getName() ?? 'operator', CuratorLogEntry::ACTION_EDIT, $description);
        if ($token !== null) {
            $entry->withToken($token);
        } elseif ($previous->getReplacedBy() === Note::ACTOR_MEMEX) {
            $entry = (new CuratorLogEntry('memex', CuratorLogEntry::ACTION_EDIT, $description))->byMemex();
        }

        return $entry->withNote($note);
    }

    /**
     * @param string[] $names
     *
     * @return string[]
     */
    private static function sortedNames(array $names): array
    {
        sort($names);

        return $names;
    }

    /** @return array{note: Note, suggestions: array{tag_ids: int[], new_tags: string[]}} */
    public function restoreRevision(Note $note, int $revisionId): array
    {
        $result = $this->limbo->whileHoldingNotes([$note], function () use ($note, $revisionId): array {
            $revision = $this->revisions->find($revisionId);
            if ($revision === null || $revision->getNoteId() !== $note->getId()) {
                throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('No such revision for this note');
            }
            $body = $this->revisions->bodyFor($revision);
            if ($body === null) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('This revision cannot be read back — its content is gone or its history is broken');
            }

            return $this->update(
                $note,
                $revision->getTitle(),
                $body,
                $revision->getTags(),
                null,
                summaryProvided: true,
                summary: $revision->getSummary(),
                actor: Note::ACTOR_HUMAN,
                operation: NoteRevision::OP_RESTORE,
            );
        });
        $result['suggestions'] = $this->finishUpdateEnrichment($result['note'], true, true, EmbeddingSpend::Metered);

        return $result;
    }

    /** @return array{tag_ids: int[], new_tags: string[]} */
    public function finishUpdateEnrichment(Note $note, bool $bodyChanged, bool $applyTags, EmbeddingSpend $enrich): array
    {
        $suggestions = $this->enricher->enrich(
            $note,
            $enrich,
            generateSummary: $note->getSummary() === null,
            applyTags: $applyTags && $bodyChanged,
        );
        $this->em->flush();

        return $suggestions;
    }

    /**
     * Tag garbage collection: a tag with no notes left is vocabulary noise —
     * drop it. Held proposals are unaffected (they carry tag NAMES, resolved
     * only on apply — a re-proposed name simply recreates the tag). A tag a
     * saved filter names stays: the filter holds its id, and a filter on a tag
     * that has no notes today should find the next one tagged, not every note.
     * Runs after every write that can orphan a tag; idempotent.
     */
    public function gcTags(): void
    {
        $this->em->getConnection()->executeStatement(
            'DELETE FROM tags
             WHERE NOT EXISTS (SELECT 1 FROM note_tag nt WHERE nt.tag_id = tags.id)
             AND NOT EXISTS (SELECT 1 FROM search_presets p, json_each(p.tag_ids) j WHERE j.value = tags.id)'
        );
    }

    /**
     * Token-authored edit: held for review for agent-role tokens (the
     * write-path counterpart of create()'s pending status), applied
     * immediately for curator-role tokens — the row then survives as the
     * audit record, prev* snapshotting what the edit replaced. Null = leave
     * that field untouched; tags is a full replacement list when present.
     *
     * $hold lets a CURATOR voluntarily submit to review: the sanctioned way
     * to express uncertainty about a safe change — the operator's verdict is
     * the answer (there is no ask-a-question channel by design).
     *
     * @param string[]|null $tagNames
     */
    /**
     * Does this edit have to go through review because it is a whole body from
     * a connection whose edits would otherwise apply on the spot? (C-9.)
     *
     * Public and static because three places need the same answer and must not
     * drift: {@see self::propose()} enforces it, and the two controllers
     * say WHY the answer came back held. A duplicated condition here would
     * eventually hold an edit while telling the caller it applied.
     *
     * A create is not covered: there is no other version of a note that does
     * not exist yet. Neither are title, tags or summary — they are small,
     * whole-value fields where a concurrent change is visible in the diff
     * rather than swallowed by it.
     */
    public static function wantsAnchoring(?ApiToken $token, ?string $bodyMd): bool
    {
        return $bodyMd !== null && $token?->isCurator() === true;
    }

    public const INSTRUCTIONS_HELD = 'Edit held for review — this note carries instructions other assistants follow '
        .'(it is tagged `skill` or `user-profile`, or this edit adds or removes one of those tags), and a change '
        .'to those always waits for the owner, whatever the connection\'s role.';

    /**
     * Would a curator connection's write be held because it changes what other
     * assistants are told to do? A skill is served to every connection and a
     * profile's standing instructions bind every note an assistant writes, so
     * one connection rewriting either unreviewed would be writing the rules
     * every other connection follows. The owner decides those, always.
     *
     * Agent-role writes are held anyway; this answers for the curator role.
     * Covers the note as it stands and the tags the write would leave it with,
     * so adding or removing either tag counts as well as editing a note that
     * carries one.
     *
     * @param list<string>|null $tagNames the full replacement tag list, or null to leave tags alone
     */
    public static function holdsInstructions(?ApiToken $token, ?Note $note, ?array $tagNames): bool
    {
        return $token?->isCurator() === true && self::touches($note, $tagNames, [SystemTags::SKILL, SystemTags::USER_PROFILE]);
    }

    /**
     * @param list<string>|null $tagNames
     * @param list<string> $names lowercased tag names
     */
    private static function touches(?Note $note, ?array $tagNames, array $names): bool
    {
        if ($note !== null) {
            foreach ($note->getTags() as $tag) {
                if (in_array($tag->getName(), $names, true)) {
                    return true;
                }
            }
        }
        foreach ($tagNames ?? [] as $name) {
            if (in_array(mb_strtolower(trim((string) $name)), $names, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The body as the row holds it, read inside the lock the caller holds,
     * so a size check compares against what is stored and not against an
     * entity loaded before somebody else shrank the note.
     */
    private function lockedBody(Note $note): string
    {
        $body = $this->em->getConnection()->fetchOne('SELECT body_md FROM notes WHERE id = :id', ['id' => $note->getId()]);

        return is_string($body) ? $body : $note->getBodyMd();
    }

    private function whileHoldingNote(Note $note, callable $work, ?Note $keeper = null): mixed
    {
        return $this->limbo->whileHoldingNotes($keeper === null ? [$note] : [$note, $keeper], $work);
    }

    /**
     * The item this connection already has waiting on this note, if any.
     *
     * Keyed on the connection because that is who the draft belongs to. A null
     * token — memex's own enrichment pass — is an author like any other and
     * matches only other passes, which is why this is `IS NULL` rather than a
     * special case.
     *
     * Newest first: rows filed before the one-item rule existed are left where
     * they are rather than swept up, since deleting somebody's held work to
     * tidy a listing is not a migration's business.
     */
    /** @return EditProposal[] newest first */
    private function heldDrafts(Note $note, ?ApiToken $token): array
    {
        return $this->em->getRepository(EditProposal::class)->findBy(
            [
                'note' => $note,
                'proposedByToken' => $token,
                'status' => EditProposal::STATUS_HELD,
            ],
            ['id' => 'DESC'],
        );
    }

    /** The one this author is working, or null. */
    private function heldDraft(Note $note, ?ApiToken $token): ?EditProposal
    {
        return $this->heldDrafts($note, $token)[0] ?? null;
    }

    /**
     * Retire every row this write replaces, and unhook the log entries that
     * point at them.
     *
     * ALL of them rather than the newest, because rows filed before the
     * one-item rule are not swept by its migration: a connection that had four
     * held drafts on one note must not leave three behind when it writes again
     * (Codex, 2026-09-08).
     *
     * The log is unhooked through the ORM, not by SQL: the foreign key is
     * already `ON DELETE SET NULL`, but an entry loaded in this request keeps
     * its reference in memory and the next flush rediscovers the removed row
     * through it.
     *
     * @param EditProposal[] $drafts
     */
    private function retire(array $drafts): void
    {
        foreach ($drafts as $draft) {
            foreach ($this->em->getRepository(CuratorLogEntry::class)->findBy(['proposal' => $draft]) as $entry) {
                $entry->withProposal(null);
            }
            $this->em->remove($draft);
        }
    }

    /**
     * Drop the item this connection has waiting on this note, because it is
     * filing a different one. Deletes and merges and reports carry no fields to
     * fold — they are a different question about the same document, and the
     * operator sees one question per document per author.
     */
    private function supersedeDraft(Note $note, ?ApiToken $token): void
    {
        $this->retire($this->heldDrafts($note, $token));
    }

    /**
     * Every anchor that approving this draft would run, in order: the ones it
     * already holds plus the ones just sent.
     *
     * A draft carrying a whole BODY has no anchors to compose with — the new
     * operations were written against the note, not against that body — so
     * they stand alone and replace it.
     *
     * @param array<int, array{find: string, replace: string}> $patch
     * @return array<int, array{find: string, replace: string}>
     */
    private static function composedPatch(?EditProposal $draft, array $patch): array
    {
        if ($draft === null || $draft->getProposedBodyMd() !== null) {
            return $patch;
        }

        return array_merge($draft->getProposedPatch() ?? [], $patch);
    }

    /**
     * A connection's own prose, repaired where it arrived JSON-escaped.
     *
     * Every proposal writer passes through here — MCP and the website's REST
     * routes alike — which is the point: the first version of this repair sat
     * in {@see \App\Controller\McpController} and left `DELETE /api/notes/7`
     * serving `\n` to the inbox (Codex, 2026-09-16). Only a TOKEN's text is
     * touched: a person typing into a browser means the backslashes they type.
     */
    private static function agentProse(?string $comment, ?ApiToken $token): ?string
    {
        return $comment === null || $token === null ? $comment : ProseEscapes::repair($comment);
    }

    public function propose(
        Note $note,
        ?ApiToken $token,
        ?string $title,
        ?string $bodyMd,
        ?array $tagNames,
        ?string $comment = null,
        bool $hold = false,
        bool $applyTags = true,
        ?string $summary = null,
        ?array $patch = null,
        ?string $changeTitle = null,
    ): EditProposal {
        $comment = self::agentProse($comment, $token);
        if ($title === null && $bodyMd === null && $tagNames === null && $summary === null && $patch === null) {
            // An agent that knows a note is stale but not what replaces it has
            // one honest thing to say, and forcing it to invent a plausible
            // correction to be heard is worse than the gap. It becomes a
            // REPORT rather than an empty edit: same queue, same review, but
            // approving it cannot claim to have changed the note.
            $said = $comment !== null ? self::normaliseReport($comment) : '';
            if ($said === '') {
                throw new \InvalidArgumentException('An edit proposal must change at least one of title, body_md, patch, tags, summary — or carry a comment saying what is wrong with the note.');
            }
            if ($token === null) {
                // memex's own enrichment pass, which describes notes and has
                // nothing to report.
                throw new \InvalidArgumentException('A comment-only proposal is a report from a connection, and this write carries no token.');
            }

            // An author who already has something in review on this note is
            // rewriting ITS rationale, not filing a report about the note.
            // Reading it the other way would supersede what they are still
            // writing, and losing drafted text to a follow-up sentence is the
            // worst thing a comment could do. ANY kind, not edits alone: a
            // merge carries the keeper's body, and explaining that merge in a
            // second call replaced it with a bodiless report (Codex,
            // 2026-09-08).
            return $this->whileHoldingNote($note, function () use ($note, $token, $said, $changeTitle): EditProposal {
                $drafted = $this->heldDraft($note, $token);
                if ($drafted === null) {
                    return $this->fileReport($note, $token, $said);
                }
                $drafted->revise(null, null, null, null, null, $said, $changeTitle);
                $this->journal->record(
                    (new CuratorLogEntry(
                        $token->getName(),
                        self::proposedAction($drafted),
                        'Revised the reasoning on its held '.self::kindOf($drafted).' of “'.$note->getTitle().'”: '.$said,
                    ))->withToken($token)->withNote($note)
                        ->withAffectedNotes(array_filter([(int) $note->getId(), (int) $drafted->getMergeIntoNote()?->getId()]))
                );
                $this->em->flush();

                return $drafted;
            });
        }
        if ($bodyMd !== null && $patch !== null) {
            // Two answers to the same question. Refused rather than picking one,
            // because whichever we picked would silently discard the other.
            throw new \InvalidArgumentException('Send either body_md or patch, not both — they are two ways of saying what the text becomes.');
        }

        // **A curator's edit applies immediately, so a whole body from one is
        // held instead** (C-9, operator 2026-08-26: several delegated curators
        // may run concurrently and memex must have a mechanism for it).
        //
        // Held rather than refused, because refusing would throw away work
        // already done and the operator can approve it in one click after
        // reading what it does. Anchor the edit and it applies on the spot.
        $anchorHold = self::wantsAnchoring($token, $bodyMd);
        $instructionHold = self::holdsInstructions($token, $note, $tagNames);
        if ($anchorHold || $instructionHold) {
            $hold = true;
        }
        $applies = $token?->isCurator() === true && !$hold;

        // ONE HELD ITEM PER NOTE PER CONNECTION (operator, 2026-09-08: "until
        // user approves the document, it belongs to the author... I don't want
        // to see multiple versions of it in inbox"). Until a verdict lands the
        // draft is the author's working copy, so a further edit revises it
        // rather than queueing a rival card carrying its own snapshot of the
        // note. Other connections keep their own draft: folding across authors
        // would discard work its author cannot see.
        $proposal = $this->whileHoldingNote($note, function () use ($note, $token, $title, $bodyMd, $tagNames, $comment, $summary, $patch, $changeTitle, $applies, $hold, $anchorHold, $instructionHold, $applyTags): EditProposal {
            $folded = false;
            $drafts = $this->heldDrafts($note, $token);
            $draft = $drafts[0] ?? null;
            $foldInto = !$applies && $draft?->getType() === EditProposal::TYPE_EDIT ? $draft : null;

            // Checked NOW, against the note as it stands, so a mistyped anchor is a
            // refusal the author can act on rather than a proposal that sits in
            // somebody's inbox and fails when they approve it. It is checked AGAIN
            // on approval, which is the check that matters: this one is courtesy.
            // Composed with anchors the draft already holds, because approving it
            // will run all of them in order against one body.
            if ($patch !== null || $bodyMd !== null) {
                $liveBody = $this->lockedBody($note);
                $priorBody = $foldInto?->getProposedBodyMd()
                    ?? ($foldInto?->getProposedPatch() !== null ? NotePatch::apply($liveBody, $foldInto->getProposedPatch()) : $liveBody);
                $nextBody = $patch !== null ? NotePatch::apply($liveBody, self::composedPatch($foldInto, $patch)) : $bodyMd;
                $this->storageLimits->assertBody($nextBody, strlen($priorBody));
            }

            // Everything this write supersedes: a different kind of decision from
            // the same author, a curator's edit that is about to apply, or rows
            // that predate the one-item rule and are older than the one being
            // folded into.
            $this->retire(array_values(array_filter($drafts, static fn (EditProposal $d): bool => $d !== $foldInto)));

            if ($foldInto !== null) {
                $foldInto->revise($title, $bodyMd, $patch, $tagNames, $summary, $comment, $changeTitle);
                $proposal = $foldInto;
                $folded = true;
            } else {
                $proposal = (new EditProposal($note, $token, $title, $bodyMd, $tagNames, $comment))
                    ->withProposedSummary($summary)
                    ->withProposedPatch($patch)
                    ->withChangeTitle($changeTitle);
                $this->em->persist($proposal);
            }
            $this->em->flush();

            // A null token is memex's own enrichment pass. It is never a curator,
            // so it always takes the held path below — which is the whole point of
            // the ruling it implements (operator, 2026-08-23: everything a pass
            // produces is proposed).
            if (!$applies) {
                $changed = self::changedFields($title, $bodyMd, $tagNames, $summary, $patch);
                // Why it is held is the useful half of a curator's row. "At own
                // request" was the only reason a curator's edit could be held until
                // C-9, and reading it against an edit the SERVER held would be
                // actively misleading — it would credit the connection with a
                // caution it did not show. Anyone else's edit is held because it
                // always is.
                $why = match (true) {
                    $token?->isCurator() !== true => 'held for review.',
                    $instructionHold => 'held for review: it changes instructions other assistants follow (a note tagged skill or user-profile), which always waits for the owner.',
                    $anchorHold => 'held for review: it replaces the whole body, and a curator connection\'s edits apply without review, so an anchored patch is what applies immediately.',
                    default => 'held for review at own request (unsure).',
                };
                $entry = new CuratorLogEntry(
                    $token?->getName() ?? 'memex',
                    CuratorLogEntry::ACTION_EDIT_PROPOSED,
                    ($folded ? 'Revised the held edit' : 'Proposed edit')
                        .' ('.implode(' + ', $changed).') of “'.$note->getTitle().'” — '.$why.($comment !== null && $comment !== '' ? ' '.$comment : ''),
                );
                $this->journal->record(($token === null ? $entry->byMemex() : $entry->withToken($token))->withNote($note));
            } elseif ($token?->isCurator()) {
                // Applying immediately, so there is no "as it then stands" to wait
                // for: resolve the patch against the body in front of us and take
                // the ordinary full-body path from here.
                //
                // Through resolveBodyForApply rather than NotePatch directly,
                // because this row SURVIVES as the audit record the Activity
                // journal reads, and a row holding only the anchors expands to
                // nothing. It is the one apply path that keeps its proposal.
                $bodyMd = $this->resolveBodyForApply($proposal);
                $prevTitle = $note->getTitle();
                $proposal->markApplied(
                    $prevTitle,
                    $note->getBodyMd(),
                    array_map(static fn ($tag) => $tag->getName(), $note->getTags()->toArray()),
                    $note->getSummary(),
                );
                // The actor is passed explicitly: without it `lastActor` kept
                // whoever edited the note previously, and — since 2026-08-16 — the
                // revision this edit replaces would have been attributed to them
                // too. A curator's direct edit is the single most common write in
                // this system and it was the one write not saying who made it.
                $this->journal->quietly(fn () => $this->update(
                    $note,
                    $title,
                    $bodyMd,
                    $tagNames,
                    null,
                    summaryProvided: $summary !== null,
                    summary: $summary,
                    actor: Note::actorFor(null, $token),
                    applyTags: $applyTags,
                    summaryBy: self::describedBy($token),
                    actorToken: $token,
                    // A curator's safe write applies immediately and never becomes
                    // a proposal, so this is the only place its headline can reach
                    // the revision it creates.
                    changeTitle: $changeTitle,
                    operation: NoteRevision::OP_EDIT,
                ));
                $this->gcTags();
                $changed = self::changedFields($title, $bodyMd, $tagNames, $summary);
                $this->journal->record(
                    (new CuratorLogEntry(
                        $token->getName(),
                        CuratorLogEntry::ACTION_EDIT,
                        'Edited '.implode(' + ', $changed).' of “'.$prevTitle.'”'.($comment !== null && $comment !== '' ? ' — '.$comment : ''),
                    ))->withToken($token)->withNote($note)->withProposal($proposal)
                );
            }
            $this->em->flush();

            return $proposal;
        });
        if ($applies) {
            $this->enricher->enrich(
                $note,
                EmbeddingSpend::Metered,
                generateSummary: $note->getSummary() === null,
                applyTags: $applyTags && ($bodyMd !== null || $patch !== null),
            );
            $this->em->flush();
        }
        return $proposal;
    }

    private static function proposedAction(EditProposal $proposal): string
    {
        return match ($proposal->getType()) {
            EditProposal::TYPE_DELETE => CuratorLogEntry::ACTION_DELETE_PROPOSED,
            EditProposal::TYPE_MERGE => CuratorLogEntry::ACTION_MERGE_PROPOSED,
            EditProposal::TYPE_REPORT => CuratorLogEntry::ACTION_REPORTED,
            default => CuratorLogEntry::ACTION_EDIT_PROPOSED,
        };
    }

    private static function kindOf(EditProposal $proposal): string
    {
        return $proposal->getType() === EditProposal::TYPE_REPORT ? 'report' : $proposal->getType();
    }

    /**
     * Which fields an edit actually touches, for the log line the operator
     * reads. Null means "leave alone" for every one of them.
     *
     * @param string[]|null $tagNames
     * @return string[]
     */
    private static function changedFields(?string $title, ?string $bodyMd, ?array $tagNames, ?string $summary = null, ?array $patch = null): array
    {
        return array_keys(array_filter(
            [
                'title' => $title,
                // A patch changes the body as surely as a replacement does, and
                // a Curator log line reading "proposed edit (summary)" for a
                // change that rewrote three paragraphs is worse than no line.
                'body' => $bodyMd ?? $patch,
                'tags' => $tagNames,
                'summary' => $summary,
            ],
            static fn ($v) => $v !== null
        ));
    }

    /**
     * A staleness report. Held for every role, curator included: there is
     * nothing to apply, so there is nothing a trusted connection could be
     * trusted to apply.
     */
    public function report(Note $note, ApiToken $token, string $comment): EditProposal
    {
        return $this->fileReport($note, $token, (string) self::agentProse($comment, $token));
    }

    /**
     * The same, for a caller whose comment has already been through
     * {@see agentProse()}. Repairing twice is not repairing harder: the second
     * pass reads `\n` — which the first pass produced out of an escaped
     * backslash — as a line break (Codex, 2026-09-16).
     */
    private function fileReport(Note $note, ApiToken $token, string $comment): EditProposal
    {
        return $this->whileHoldingNote($note, function () use ($note, $token, $comment): EditProposal {
            $this->supersedeDraft($note, $token);
            $proposal = EditProposal::forReport($note, $token, $comment);
            $this->em->persist($proposal);
            $this->journal->record(
                (new CuratorLogEntry(
                    $token->getName(),
                    CuratorLogEntry::ACTION_REPORTED,
                    'Reported “'.$note->getTitle().'” as wrong — held for review. '.$comment,
                ))->withToken($token)->withNote($note)->withProposal($proposal)
            );
            $this->em->flush();

            return $proposal;
        });
    }

    /** Hold a token-authored deletion for review — approve retires the note. */
    public function proposeDelete(Note $note, ApiToken $token, ?string $comment): EditProposal
    {
        $comment = self::agentProse($comment, $token);

        return $this->whileHoldingNote($note, function () use ($note, $token, $comment): EditProposal {
            $this->supersedeDraft($note, $token);
            $proposal = EditProposal::forDelete($note, $token, $comment);
            $this->em->persist($proposal);
            $this->journal->record(
                (new CuratorLogEntry(
                    $token->getName(),
                    CuratorLogEntry::ACTION_DELETE_PROPOSED,
                    'Proposed deleting “'.$note->getTitle().'” — held for review.'.($comment !== null && $comment !== '' ? ' Reason: '.$comment : ''),
                ))->withToken($token)->withNote($note)
            );
            $this->em->flush();

            return $proposal;
        });
    }

    /**
     * Hold a token-authored merge for review: approving folds $absorb into
     * $into (backlinks retargeted, tags united, absorbed note deleted).
     * $mergedBodyMd, when given, replaces the keeper's body on approval.
     */
    public function proposeMerge(Note $absorb, Note $into, ApiToken $token, ?string $mergedBodyMd, ?string $comment): EditProposal
    {
        if ($absorb->getId() === $into->getId()) {
            throw new \InvalidArgumentException('A note cannot be merged into itself');
        }
        if ($mergedBodyMd !== null && trim($mergedBodyMd) === '') {
            throw new \InvalidArgumentException('merged_body_md must not be empty when provided');
        }

        $comment = self::agentProse($comment, $token);

        return $this->whileHoldingNote($absorb, function () use ($absorb, $into, $token, $mergedBodyMd, $comment): EditProposal {
            if ($mergedBodyMd !== null) {
                $this->storageLimits->assertBody($mergedBodyMd, strlen($this->lockedBody($into)));
            }
            $this->supersedeDraft($absorb, $token);
            $proposal = EditProposal::forMerge($absorb, $into, $token, $mergedBodyMd, $comment);
            $this->em->persist($proposal);
            $this->journal->record(
                (new CuratorLogEntry(
                    $token->getName(),
                    CuratorLogEntry::ACTION_MERGE_PROPOSED,
                    'Proposed merging “'.$absorb->getTitle().'” into “'.$into->getTitle().'” — held for review.'.($comment !== null && $comment !== '' ? ' '.$comment : ''),
                ))->withToken($token)->withNote($absorb)
                    ->withAffectedNotes([(int) $absorb->getId(), (int) $into->getId()])
            );
            $this->em->flush();

            return $proposal;
        }, $into);
    }

    /**
     * Operator approval: apply the held change and discard the proposal.
     * Edits go through the normal update path (links re-sync, enrichment
     * re-runs); deletes retire the note; merges fold it into the keeper.
     *
     * @return array{note: ?Note, suggestions: array{tag_ids: int[], new_tags: string[]}}
     *         note is null after a delete — the target is gone
     */
    public function applyProposal(EditProposal $proposal): array
    {
        return $this->limbo->whileHoldingNotes(
            array_values(array_filter([$proposal->getNote(), $proposal->getMergeIntoNote()])),
            function () use ($proposal): array {
                $result = match ($proposal->getType()) {
                    EditProposal::TYPE_DELETE => $this->applyDeleteProposal($proposal),
                    EditProposal::TYPE_MERGE => $this->applyMergeProposal($proposal),
                    EditProposal::TYPE_REPORT => $this->closeReport($proposal),
                    default => $this->applyEditProposal($proposal),
                };
                $this->gcTags();

                return $result;
            },
        );
    }

    /**
     * What an edit operation makes the body — resolving an anchored patch
     * against the note AS IT NOW STANDS, which is the whole reason patches are
     * stored unresolved.
     *
     * Every apply path goes through here. A path that read
     * `getProposedBodyMd()` directly would silently ignore a patch and apply
     * nothing, which is the worst available outcome — an approval that reports
     * success and changes nothing.
     *
     * It also WRITES the resolution back onto the proposal, which is why it is
     * named for applying rather than for resolving. An applied proposal is an
     * audit row, and an audit row that says only "these anchors were to be
     * replaced" does not record what was done — see
     * {@see EditProposal::recordResolvedBody()}.
     *
     * @throws NotePatchException when the text the patch named is no longer there
     */
    private function resolveBodyForApply(EditProposal $op): ?string
    {
        $patch = $op->getProposedPatch();
        $note = $op->getNote();
        if ($patch === null || $note === null) {
            return $op->getProposedBodyMd();
        }

        $liveBody = $this->lockedBody($note);
        $resolved = NotePatch::apply($liveBody, $patch);
        $this->storageLimits->assertBody($resolved, strlen($liveBody));
        $op->recordResolvedBody($resolved);

        return $resolved;
    }

    /** @return array{note: Note, suggestions: array{tag_ids: int[], new_tags: string[]}} */
    private function applyEditProposal(EditProposal $proposal): array
    {
        // The proposal's author is the contributor, not the operator who
        // approved it — approval is a gate, not authorship.
        $proposedSummary = $proposal->getProposedSummary();
        // Resolved against the note as it stands at APPROVAL, so a patch filed
        // before somebody else's edit lands on top of it — and if the text it
        // named has moved, the exception stops the approval with the proposal
        // still held, rather than reverting work nobody was asked about.
        //
        // Resolved ONCE, into a variable, because resolving APPLIES the patch:
        // asking a second time to find out whether the body changed re-runs it
        // against a body that has already changed, and the anchor is gone.
        $body = $this->resolveBodyForApply($proposal);
        $result = $this->update(
            $proposal->getNote(),
            $proposal->getProposedTitle(),
            $body,
            $proposal->getProposedTags(),
            // The proposer's description is part of the change, not a detail to
            // be regenerated after it: approving an enrichment that then bought
            // a fresh summary would discard exactly the work being approved.
            summaryProvided: $proposedSummary !== null,
            summary: $proposedSummary,
            // Asked of the PROPOSAL rather than of its token, because since
            // 2026-08-23 a proposal may have no token: memex files its own.
            actor: $proposal->actor(),
            // The proposer wrote the description; approving is a gate, not
            // authorship — the same rule the actor line already follows.
            summaryBy: $proposal->describedBy(),
            // ReviewVerdicts::exactlyOnce() has a transaction open around this
            // call, so enriching here would hold its locks for the length of a
            // provider round trip (A-3b). It drains after committing, and the
            // drain decides what enrichment costs — M-9's "no tag suggestions
            // on an apply path" lives there now, in one place, rather than as
            // an argument each of these three call sites has to remember.
            enrich: null,
            // The headline follows the change onto the revision. Copied rather
            // than referenced: the proposal row is removed two lines below.
            changeTitle: $proposal->getChangeTitle(),
            // The contributor, not the operator who approved: approval is a
            // gate, not authorship — the same rule the actor line follows.
            actorToken: $proposal->getProposedByToken(),
            operation: NoteRevision::OP_PROPOSAL,
            // The credit stays with the proposer; what the history gains is
            // that the text applied was not the text they filed.
            amendedByOperator: $proposal->isAmended(),
        );
        $this->deferredEnrichment[] = [$proposal->getNote(), $body !== null];
        $this->em->remove($proposal);
        $this->em->flush();

        return $result;
    }

    /**
     * Acknowledging a report. The note it names is untouched, and the reason
     * this is its own branch rather than a fall-through is SPEND: the edit path
     * ends by queueing the note for deferred enrichment, and
     * {@see drainDeferredEnrichment()} buys a summary for any note that has
     * none. Acknowledging "this note is wrong" would then pay a provider to
     * describe it, on a proposal that asked for nothing.
     *
     * The difference only exists when server-side text generation is ON for the
     * team: with it off, `MlClient::summarize()` returns before it calls
     * anything and both paths look the same. That is why
     * {@see \App\Tests\Database\StalenessReportTest::testAcknowledgingAReportBuysNoSummary()}
     * switches enrichment on before it watches — and it writes the note first,
     * because creating one with the switch already on buys the very summary it
     * is looking for.
     *
     * @return array{note: Note|null, suggestions: array{tag_ids: int[], new_tags: string[]}}
     */
    private function closeReport(EditProposal $proposal): array
    {
        $note = $proposal->getNote();
        $this->em->remove($proposal);
        $this->em->flush();

        return ['note' => $note, 'suggestions' => ['tag_ids' => [], 'new_tags' => []]];
    }

    /** @return array{note: null, suggestions: array{tag_ids: int[], new_tags: string[]}} */
    private function applyDeleteProposal(EditProposal $proposal): array
    {
        $note = $proposal->getNote();
        if ($note === null) {
            throw new \LogicException('Delete proposal without a note');
        }
        // Inbound note_links go to_note_id=NULL via FK (dangling, raw_target
        // kept — they auto-resolve if an equivalent note appears later).
        // The proposal row must go first: it FKs the note, and retiring the
        // note cascades the proposal out from under Doctrine's unit of work.
        $reason = $proposal->getComment();
        $this->em->remove($proposal);
        $this->em->flush();
        // Retired, not destroyed — 30 days in limbo, then a permanent
        // tombstone. The approved reason is what the tombstone carries.
        $this->limbo->retire($note, Note::actorFor(null, $proposal->getProposedByToken()), $reason);
        $this->gcTags();

        return ['note' => null, 'suggestions' => ['tag_ids' => [], 'new_tags' => []]];
    }

    /** @return array{note: Note, suggestions: array{tag_ids: int[], new_tags: string[]}} */
    private function applyMergeProposal(EditProposal $proposal): array
    {
        $absorb = $proposal->getNote();
        $keeper = $proposal->getMergeIntoNote();
        if ($absorb === null || $keeper === null) {
            throw new \LogicException('Merge proposal without both notes');
        }
        $mergedBody = $proposal->getProposedBodyMd();
        if ($mergedBody !== null) {
            $this->storageLimits->assertBody($mergedBody, strlen($this->lockedBody($keeper)));
        }
        // Null on anything an agent filed — `forMerge()` sets neither, and the
        // keeper's tags and description are not a merge's to propose. They are
        // here only because {@see EditProposal::amend()} accepts them, which is
        // the operator taking over the document the merge leaves behind.
        $tags = $proposal->getProposedTags();
        $summary = $proposal->getProposedSummary();
        $this->em->remove($proposal);

        return $this->mergeNotes(
            $absorb,
            $keeper,
            $mergedBody,
            Note::actorFor(null, $proposal->getProposedByToken()),
            $proposal->getProposedByToken(),
            $proposal->isAmended(),
            $tags,
            $summary,
        );
    }

    /**
     * The merge mechanics.
     *
     * @param string[]|null $mergedTags the keeper's tags as the operator amended
     *                                   them; null leaves the union this method
     *                                   already performed
     * @return array{note: Note, suggestions: array{tag_ids: int[], new_tags: string[]}}
     */
    private function mergeNotes(
        Note $absorb,
        Note $keeper,
        ?string $mergedBody,
        ?string $actor = null,
        ?ApiToken $actorToken = null,
        bool $amendedByOperator = false,
        ?array $mergedTags = null,
        ?string $mergedSummary = null,
    ): array {
        if ($mergedBody !== null) {
            $this->storageLimits->assertBody($mergedBody, strlen($this->lockedBody($keeper)));
        }
        // This named the KIND and stopped there, so a merge approved from one
        // assistant's proposal left the PREVIOUS assistant's face on the
        // keeper — wrong rather than incomplete, and invisible unless you
        // happened to know which of two Claudes had touched it last. It also
        // only reached the note at all when the merge carried a body, because
        // the update() below (which does the full attribution) is skipped when
        // nothing about the text changed.
        if ($actor !== null) {
            $keeper->attribute($actor, $actorToken);
        }
        // Read off BEFORE the absorbed note is retired, and applied below
        // rather than here. A union written onto the keeper now would already
        // be on it when update() takes the revision snapshot, so the history
        // would claim the keeper previously held a tag it never had.
        $union = array_values(array_unique(array_merge(
            array_map(static fn (Tag $tag): string => $tag->getName(), $keeper->getTags()->toArray()),
            array_map(static fn (Tag $tag): string => $tag->getName(), $absorb->getTags()->toArray()),
        )));
        $this->em->flush();

        // Retarget inbound links onto the keeper. The keeper's own links to
        // the absorbed note would become self-links — drop their resolution
        // instead (raw_target survives; a later relink can re-resolve).
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'UPDATE note_links SET to_note_id = :keeper WHERE to_note_id = :absorb AND from_note_id != :keeper',
            ['keeper' => $keeper->getId(), 'absorb' => $absorb->getId()]
        );
        $conn->executeStatement(
            'UPDATE note_links SET to_note_id = NULL WHERE to_note_id = :absorb AND from_note_id = :keeper',
            ['absorb' => $absorb->getId(), 'keeper' => $keeper->getId()]
        );

        // The absorbed note is retired, not destroyed: a merge is a judgment
        // call about two notes being the same thing, and it is the judgment
        // most worth being able to walk back.
        $this->limbo->retire(
            $absorb,
            $actor ?? Note::ACTOR_HUMAN,
            'Merged into “'.$keeper->getTitle().'” (note '.$keeper->getId().')'
        );

        // Any of the three is a write to the keeper. Tags and a description
        // arrive only from an operator's amendment, which is why a merge that
        // proposes nothing still reaches update() when they are present.
        if ($mergedBody !== null || $mergedTags !== null || $mergedSummary !== null) {
            $this->deferredEnrichment[] = [$keeper, $mergedBody !== null];
            return $this->update(
                $keeper,
                null,
                $mergedBody,
                $mergedTags ?? $union,
                null,
                summaryProvided: $mergedSummary !== null,
                summary: $mergedSummary,
                actor: $actor,
                actorToken: $actorToken,
                operation: NoteRevision::OP_MERGE,
                // The keeper's text can be the operator's own: every merge is
                // amendable, including one that proposed no body at all. Credit
                // stays with the proposer; the revision records that the text
                // applied was not the text they filed.
                amendedByOperator: $amendedByOperator,
            );
        }

        // Body unchanged — sync links (tag/link state moved) but skip the
        // OpenAI re-enrichment; nothing the summary depends on changed. The
        // union lands here directly: no revision is written on this path, so
        // there is no snapshot for it to get ahead of.
        foreach ($this->resolveTags($union) as $tag) {
            $keeper->addTag($tag);
        }
        $this->enricher->syncLinks($keeper);
        $this->em->flush();

        return ['note' => $keeper, 'suggestions' => ['tag_ids' => [], 'new_tags' => []]];
    }

    /**
     * Enrich what the apply paths queued. Call ONLY where no transaction of
     * ours is still open — {@see \App\Service\ReviewVerdicts::exactlyOnce()}
     * after its commit.
     *
     * Not wrapped in a transaction of its own, deliberately: each note is
     * independent, and one that cannot be described must not undo an apply the
     * operator has already been told succeeded.
     */
    public function drainDeferredEnrichment(EmbeddingSpend $spend): void
    {
        $notes = $this->deferredEnrichment;
        $this->deferredEnrichment = [];
        if ($notes === []) {
            return;
        }

        foreach ($notes as [$note, $bodyChanged]) {
            $this->enricher->enrich(
                $note,
                // Decided by the CALLER rather than hard-coded here, which
                // is what silently exempted an apply path that ran behind a
                // token instead of a verdict (found by review, 2026-08-25).
                // {@see EmbeddingSpend}
                $spend,
                generateSummary: $note->getSummary() === null,
                // Tags are APPLIED now rather than handed back, so an apply
                // path files them like any other save. M-9's rule — "nobody is
                // looking at suggestions on an apply path" — was true of a
                // return value and is not true of a write; what survives of it
                // is the half that was really about spend, which is that an
                // edit leaving the text alone has nothing new to tag.
                applyTags: $bodyChanged,
            );
        }
        $this->em->flush();
    }

    /**
     * Forget what a rolled-back apply queued. The notes were not written, so
     * describing them would be describing rows that do not exist — and leaving
     * them queued would enrich them on the next successful apply in the same
     * request, which is worse than either.
     */
    public function discardDeferredEnrichment(): void
    {
        $this->deferredEnrichment = [];
    }

    /**
     * @param string[] $tagNames
     * @return Tag[]
     */
    private function resolveTags(array $tagNames): array
    {
        $tags = [];
        $seen = [];
        $repo = $this->em->getRepository(Tag::class);
        foreach ($tagNames as $name) {
            $name = mb_strtolower(trim((string) $name));
            if ($name === '' || mb_strlen($name) > 64 || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $tag = $repo->findOneBy(['name' => $name]);
            if ($tag === null) {
                $tag = new Tag($name);
                $this->em->persist($tag);
                // The one place a tag row is ever created, and therefore the
                // one place a retirement can stop being true. A name the owner
                // took out of the vocabulary is suppressed as a SUGGESTION; it
                // was never forbidden, and once it is back on a note, going on
                // telling assistants it was rejected would make `list_tags`
                // describe a vocabulary that is not this one.
                $this->tags->unretire($name);
            }
            $tags[] = $tag;
        }

        return $tags;
    }
}
