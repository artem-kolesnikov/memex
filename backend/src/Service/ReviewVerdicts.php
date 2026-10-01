<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CuratorLogEntry;
use App\Entity\EditProposal;
use App\Entity\Note;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deciding one held item: apply it or discard it, and put the operator's
 * verdict on the record.
 *
 * Extracted when the inbox learned to act on a selection. The single-item
 * routes had this logic inline, and a batch route with its own copy would have
 * been two answers to "what does approving mean" — the kind of divergence
 * nobody notices until a verdict logs differently depending on which button
 * reached it. There is one answer here and four callers.
 *
 * Nothing about the REVIEW GATE changes because a decision arrives in bulk: a
 * batch is the operator choosing to skip reading each item, which is theirs to
 * choose, and it is not agents gaining a way to write unreviewed.
 */
class ReviewVerdicts
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NoteWriter $noteWriter,
        private readonly NoteLimbo $limbo,
    ) {
    }

    /**
     * @param array{comment: ?string, precedent: bool} $verdict
     * @return array{note: ?Note, suggestions: array{tag_ids: int[], new_tags: string[]}}
     */
    public function approveProposal(EditProposal $proposal, array $verdict, array $snapshot): array
    {
        // Built BEFORE apply (delete/merge destroy the data it needs),
        // persisted only AFTER apply succeeds — a rolled-back apply must not
        // leave a phantom "approved" row.
        $logEntry = $this->proposalEntry($proposal, CuratorLogEntry::ACTION_APPROVED, $verdict);

        $snapshot = ReviewSnapshot::proposal($snapshot, $proposal->getMergeIntoNote() !== null);
        $revision = $snapshot['expected_revision'];
        $versions = [[$proposal->getNote(), $snapshot['expected_version']]];
        if ($proposal->getMergeIntoNote() !== null) {
            $versions[] = [$proposal->getMergeIntoNote(), $snapshot['expected_merge_version']];
        }

        return $this->exactlyOnce('edit_proposals', $proposal->getId(), function () use ($proposal, $logEntry, $versions): array {
            foreach ($versions as [$note, $expected]) {
                $this->assertNoteVersion($note, $expected);
            }
            $result = $this->noteWriter->applyProposal($proposal);
            if ($logEntry !== null) {
                $this->em->persist($logEntry);
                $this->em->flush();
            }

            return $result;
        }, ['revision' => $revision], array_values(array_filter([$proposal->getNote(), $proposal->getMergeIntoNote()])));
    }

    /**
     * Discard a held item because the OWNER wrote their own version of the note
     * instead.
     *
     * Not a verdict on what the item said, so it carries no `operator_comment`:
     * the owner typed a note, they did not review a proposal. The row is
     * written for every author rather than for curators only — the usual
     * silence is because the inbox already records an ordinary verdict, and
     * this discard happens somewhere the author never sees.
     */
    public function rejectSuperseded(EditProposal $proposal): void
    {
        $this->limbo->whileHoldingNotes(
            array_values(array_filter([$proposal->getNote(), $proposal->getMergeIntoNote()])),
            function () use ($proposal): void {
                $note = $proposal->getNote();
                $entry = (new CuratorLogEntry(
                    'operator',
                    CuratorLogEntry::ACTION_REJECTED,
                    'Owner saved their own version of “'.(string) $note?->getTitle().'”, which discarded the held '
                        .($proposal->getType() === EditProposal::TYPE_REPORT ? 'report' : $proposal->getType())
                        .' filed by '.$proposal->authorName().'.',
                ))->withNote($note);
                $this->detachFromLog($proposal);
                $this->em->remove($proposal);
                $this->em->persist($entry);
                $this->em->flush();
            },
        );
    }

    /**
     * Unhook the journal rows that point at a proposal about to be removed.
     *
     * The foreign key is `ON DELETE SET NULL`, so the database is safe on its
     * own. Doctrine is not: an entry loaded in this request keeps the
     * reference in memory, and the next flush rediscovers the removed row
     * through it and dies with "a new entity was found" (Codex, 2026-09-08).
     * Through the ORM rather than a DQL UPDATE, which would leave exactly the
     * in-memory reference that causes it.
     */
    private function detachFromLog(EditProposal $proposal): void
    {
        foreach ($this->em->getRepository(CuratorLogEntry::class)->findBy(['proposal' => $proposal]) as $entry) {
            $entry->withProposal(null);
        }
    }

    /** @param array{comment: ?string, precedent: bool} $verdict */
    public function rejectProposal(EditProposal $proposal, array $verdict): void
    {
        $this->limbo->whileHoldingNotes(
            array_values(array_filter([$proposal->getNote(), $proposal->getMergeIntoNote()])),
            function () use ($proposal, $verdict): void {
                $logEntry = $this->proposalEntry($proposal, CuratorLogEntry::ACTION_REJECTED, $verdict);
                $this->detachFromLog($proposal);
                $this->em->remove($proposal);
                if ($logEntry !== null) {
                    $this->em->persist($logEntry);
                }
                $this->em->flush();
            },
        );
    }

    /**
     * Run a verdict's work once, or not at all.
     *
     * Approving used to be check-then-act with nothing in between: the
     * controller looked the item up, saw `applied_at` was null, and applied it.
     * Two overlapping requests — two tabs, a duplicated POST, a retry after a
     * timeout — both passed that check and both ran, doubling revisions and
     * log rows for as long as it took anyone to notice. The client
     * disables the button while a verdict is in flight, which is a real guard
     * and is also only a client.
     *
     * The claim is a conditional UPDATE, so the database decides who wins:
     * exactly one caller can move `applied_at` from NULL, and the loser is told
     * so instead of doing the work again. It runs INSIDE the transaction that
     * wraps the apply, so a failed apply releases the claim with everything
     * else — an item that could not be applied must stay reviewable, not become
     * a row nobody can act on.
     *
     * Approval then usually deletes the row outright (an applied proposal is
     * not a held one), which is why the claim looks transient. Its job is not to
     * persist; it is to serialise.
     *
     * `$guard` adds columns the claim must still match — the caller naming
     * the version it read, so a row that has moved underneath is refused
     * rather than decided on stale fields. `IS`, because every one of them
     * can legitimately be NULL.
     *
     * @template T
     * @param callable(): T $work
     * @param array<string, \DateTimeImmutable|string|int|null> $guard
     * @param Note[] $notes
     * @return T
     */
    private function exactlyOnce(string $table, ?int $id, callable $work, array $guard = [], array $notes = []): mixed
    {
        // No id means Doctrine has already removed this entity — which is what
        // an applied proposal looks like from the caller's side. Deciding it
        // again is the very thing this method exists to refuse, so say so
        // rather than dying on a type error.
        if ($id === null) {
            throw new AlreadyDecidedException('This item has already been decided.');
        }

        $conn = $this->em->getConnection();
        $where = '';
        $params = ['id' => $id];
        $types = [];
        foreach ($guard as $column => $value) {
            $where .= " AND $column IS :guard_$column";
            $params["guard_$column"] = $value;
            if ($value instanceof \DateTimeInterface) {
                $types["guard_$column"] = Types::DATETIME_IMMUTABLE;
            }
        }

        $conn->beginTransaction();
        try {
            $result = $this->limbo->whileHoldingNotes($notes, function () use ($conn, $table, $where, $params, $types, $guard, $id, $work): mixed {
                $claimed = $conn->executeStatement(
                    "UPDATE $table SET applied_at = CURRENT_TIMESTAMP WHERE id = :id AND applied_at IS NULL$where",
                    $params,
                    $types
                );
                if ($claimed !== 1) {
                    // Which of the two it was. A row still sitting there unapplied
                    // means the guard is what refused: somebody changed it, and
                    // saying "already decided" would be describing the wrong race.
                    $stillHeld = $guard !== [] && $conn->fetchOne(
                        "SELECT 1 FROM $table WHERE id = :id AND applied_at IS NULL",
                        ['id' => $id]
                    ) !== false;
                    if ($stillHeld) {
                        throw new ProposalRevisedException('This draft was revised after it was loaded.');
                    }
                    throw new AlreadyDecidedException('This item has already been decided.');
                }

                return $work();
            });
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            // The rows are gone; describing them would describe nothing, and
            // leaving them queued would enrich them on the next apply in this
            // same request.
            $this->noteWriter->discardDeferredEnrichment();
            throw $e;
        }

        // Enrichment the apply path deferred, now that the locks are really
        // gone (audit A-3b). This is the outermost transaction: an apply path
        // opens one of its own INSIDE this one, so its commit only releases a
        // savepoint and draining there would have held every lock across a
        // provider round trip while looking like a fix.
        //
        // AFTER THE COMMIT AND OUTSIDE THE TRY, which the comment here has
        // claimed since it was written and the code did not do until
        // 2026-08-25: the call sat inside the try, so anything it threw ran
        // `catch`'s `rollBack()` against a connection that had already
        // committed — and DBAL throws "no active transaction" on that, which
        // would have replaced the real error with a nonsense 500 on top of
        // work that actually succeeded. Harmless while the drain could not
        // throw; not harmless the moment it can, which is now (the spend
        // limiter raises 429 from in there).
        //
        // A provider that is down must still not turn an approval the operator
        // has already been told about into a failed request — MlClient
        // swallows and records, `app:embed` sweeps, and `needs_enrichment` is
        // how an assistant finds what is still undescribed.
        //
        // OwnerInitiated: reaching this line means a person ruled on this item
        // in the review inbox. Both approve routes refuse a bearer token
        // outright, so no token can arrive here. {@see EmbeddingSpend}
        $this->noteWriter->drainDeferredEnrichment(EmbeddingSpend::OwnerInitiated);

        return $result;
    }

    /**
     * @param array{comment: ?string, precedent: bool} $verdict
     * @param bool $amended the operator rewrote the note before approving it
     */
    public function approveNote(Note $note, array $verdict, int $expectedVersion, bool $amended = false, ?callable $amend = null): void
    {
        $this->limbo->whileHoldingNotes([$note], function () use ($note, $verdict, $amended, $expectedVersion, $amend): void {
            $this->assertNoteVersion($note, $expectedVersion);
            if ($note->getStatus() !== Note::STATUS_PENDING) {
                throw new AlreadyDecidedException('This note is no longer pending review.');
            }
            if ($amend !== null) {
                $amended = $amend();
            }
            $note->approve();
            if ($verdict['comment'] !== null || $amended) {
                $this->em->persist(
                    (new CuratorLogEntry(
                        'operator',
                        CuratorLogEntry::ACTION_APPROVED,
                        $amended
                            ? 'Operator approved the pending note “'.$note->getTitle().'” with their own edits.'
                            : 'Operator approved the pending note “'.$note->getTitle().'”.'
                    ))
                        ->withNote($note)
                        ->withOperatorVerdict($verdict['comment'], $verdict['precedent'])
                );
            }
            $this->em->flush();
        });
    }

    public function assertNoteVersion(Note $note, int $expected): void
    {
        $actual = $this->em->getConnection()->fetchOne(
            'SELECT version FROM notes WHERE id = :id',
            ['id' => $note->getId()],
        );
        if ($actual === false || (int) $actual !== $expected || $note->getVersion() !== $expected) {
            throw new ProposalRevisedException('This note changed after it was reviewed.');
        }
    }

    /** @param array{comment: ?string, precedent: bool} $verdict */
    public function rejectNote(Note $note, array $verdict): void
    {
        // Rejection is a delete too, and the one most likely to be a slip —
        // a mis-click in the inbox used to be unrecoverable.
        $title = $note->getTitle();
        // The path marker stays — it says HOW the note was retired, which the
        // limbo view uses — and the operator's reasoning rides along with it.
        $this->limbo->retire($note, 'operator', 'Rejected from the review inbox'
            .($verdict['comment'] === null ? '' : ': '.$verdict['comment']));
        $this->noteWriter->gcTags();
        if ($verdict['comment'] !== null) {
            // Title only, no note reference: retire() has already moved the row
            // out of `notes`, so a reference would null out on the next flush
            // anyway. The copy is what survives, and it is what the curator
            // reads.
            $this->em->persist(
                (new CuratorLogEntry('operator', CuratorLogEntry::ACTION_REJECTED, 'Operator rejected the pending note “'.$title.'”.'))
                    ->withNote(null, $title)
                    ->withOperatorVerdict($verdict['comment'], $verdict['precedent'])
            );
            $this->em->flush();
        }
    }

    /**
     * Curator-filed proposals get their operator verdict on the record — the
     * log shows the full arc: proposed → approved/rejected. An agent-filed
     * proposal gets one too as soon as the operator says something about it
     * (2026-08-09): reasoning that reaches no row reaches no future run, and
     * agent-filed items are most of the inbox. A silent verdict on agent work
     * still writes nothing.
     *
     * Which note the row points AT depends on what the verdict does to it.
     *
     * The reference used to be kept even on a delete or a merge, reasoning
     * that `curator_log.note_id` is ON DELETE SET NULL so the database
     * handles it. It does not. This row is built before apply and persisted
     * after, so on an approved delete Doctrine is asked to flush a NEW entity
     * pointing at a note that has already been removed, and it throws —
     * after the deletion has committed. The operator got a 500 for work that
     * had happened, lost the reasoning they had just written, and in a batch
     * the poisoned unit of work took every item after it down too. SET NULL
     * cannot protect a row inserted once the note is already gone.
     *
     * So: an approved delete points at no note and carries the title copy,
     * exactly as rejectNote() has always done. An approved merge points at
     * the KEEPER, which survives — better than before, because the keeper is
     * the note a curator would think to ask about afterwards. Everything else
     * still points at its note, which is still there.
     *
     * `log_recent(note_id:)` — the curator's "have I already been told no
     * about this" check — therefore still finds every verdict whose note
     * exists to be asked about.
     *
     * @param array{comment: ?string, precedent: bool} $verdict
     */
    private function proposalEntry(EditProposal $proposal, string $action, array $verdict): ?CuratorLogEntry
    {
        // A verdict on an ordinary agent's proposal, with nothing said, is not
        // worth a journal row: the inbox already recorded it. A CURATOR's is,
        // because the curator reads this log on its next run and a silent no
        // teaches it nothing. memex's own pass (null token) is neither — it
        // never reads the log back, so it follows the agent rule.
        // An amendment is the exception to the silence: the note ends up
        // holding text the proposer never sent, and nothing else on the record
        // says the difference was the operator's.
        $amended = $action === CuratorLogEntry::ACTION_APPROVED && $proposal->isAmended();
        if ($proposal->getProposedByToken()?->isCurator() !== true && $verdict['comment'] === null && !$amended) {
            return null;
        }
        $applies = $action === CuratorLogEntry::ACTION_APPROVED;
        // A report is acknowledged or dismissed: nothing about it is applied,
        // and "approved the held report" would read as a verdict on whether
        // the note is wrong rather than on having seen the message.
        $isReport = $proposal->getType() === EditProposal::TYPE_REPORT;
        $verb = $applies
            ? ($isReport ? 'acknowledged' : 'approved')
            : ($isReport ? 'dismissed' : 'rejected');
        $title = (string) $proposal->getNote()?->getTitle();
        $keeper = $proposal->getMergeIntoNote();
        $what = match ($proposal->getType()) {
            EditProposal::TYPE_DELETE => 'deletion of “'.$title.'”',
            EditProposal::TYPE_MERGE => 'merge of “'.$title.'” into “'.$keeper?->getTitle().'”',
            EditProposal::TYPE_REPORT => 'report about “'.$title.'”',
            default => 'edit of “'.$title.'”',
        };

        $entry = new CuratorLogEntry(
            'operator',
            $action,
            'Operator '.$verb.' the held '.$what.($amended ? ', applying their own text.' : '.')
        );
        // A REJECTED delete or merge destroys nothing — the note stays and the
        // reference is safe. Only an approval has to plan around the apply.
        $entry = match (true) {
            !$applies => $entry->withNote($proposal->getNote()),
            $proposal->getType() === EditProposal::TYPE_DELETE => $entry->withNote(null, $title),
            $proposal->getType() === EditProposal::TYPE_MERGE => $entry->withNote($keeper, $keeper?->getTitle()),
            default => $entry->withNote($proposal->getNote()),
        };

        // Both ends of a merge, not only the row's one note_id: "which notes
        // did this touch" is the question the journal now answers under the
        // description, and a merge verdict touches two.
        $touched = array_filter([$proposal->getNote()?->getId(), $keeper?->getId()]);

        return $entry
            ->withAffectedNotes(array_map('intval', array_values($touched)))
            ->withOperatorVerdict($verdict['comment'], $verdict['precedent']);
    }

}
