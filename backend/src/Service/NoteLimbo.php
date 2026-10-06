<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CuratorLogEntry;
use App\Entity\EditProposal;
use App\Entity\Note;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Limbo and tombstones — the only place a note is destroyed.
 *
 * Deletion used to be the one irreversible write in a system otherwise built
 * on reversibility: edits keep prev_* snapshots and render as diffs, while a
 * delete dropped the row and left a title in the log. Enormous ceremony
 * before the act (held for review at every role, "no unapproved deletes"),
 * no recourse after it.
 *
 * Now a delete RETIRES the note: its row moves out of `notes` into
 * `deleted_notes`, where it is invisible to the KB but restorable for 30
 * days. After that the content is purged and the row stays forever as a
 * tombstone — title, path and reason, no body — because two failure modes
 * outlive the content itself:
 *
 *  - **Links silently re-bind.** Inbound links keep `raw_target` and
 *    auto-resolve when "an equivalent note appears later" (NoteWriter's
 *    catch-up). Delete a note, let a different note take its title, and every
 *    historical link re-points at new meaning with nothing recording the
 *    switch. A permanent tombstone is what makes that detectable.
 *  - **Re-import resurrects retirements.** Import dedupes by title against
 *    notes that exist; a retired note does not, so re-importing the vault
 *    silently undoes curation. The tombstone is what lets the importer say
 *    "you retired this on <date> because <reason>".
 *
 * Why a table rather than `notes.deleted_at`: 26 raw-SQL sites read `notes`
 * and Doctrine's find() bypasses SQL filters, so a flag would need 26 hand-
 * added conditions plus perfect memory forever, and one miss leaks deleted
 * content into search, export or MCP. Moving the row makes every read correct
 * by construction.
 */
class NoteLimbo
{
    /** How long a retired note can be reincarnated before its content goes. */
    public const LIMBO_DAYS = 30;

    /** Columns copied out of `notes` and back again, in one list so the two directions cannot drift. */
    private const CARRIED = [
        'created_by_token_id', 'title', 'body_md', 'summary', 'summary_by',
        'source', 'source_url', 'import_path', 'status', 'last_actor', 'enriched_at',
        // Added 2026-08-23, late: `last_actor` (the KIND) was carried from the
        // start and the two columns naming WHO were not, so a restored note
        // came back saying "an assistant wrote this" with no assistant, or
        // "a person did" with no person. A restore is meant to be faithful,
        // not an approximation authored by whoever pressed restore.
        'last_actor_token_id',
    ];

    /**
     * Which of {@see CARRIED} are booleans — see restore() for why it matters.
     *
     * Empty since `enrichment_requested` was removed on 2026-08-23, and kept
     * rather than deleted: the binding trap it exists for is a property of
     * PDO and DBAL, not of that one column, and the next boolean added to
     * CARRIED would hit it silently.
     */
    private const CARRIED_BOOLEANS = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NoteEnricher $enricher,
        private readonly NoteRevisions $revisions,
        private readonly GrowthLimits $growth,
        private readonly Journal $journal,
    ) {
    }

    /**
     * Move a note into limbo. The caller must not have flushed a removal —
     * this IS the removal.
     *
     * @param string $by     Who retired it: 'operator' or a token name
     * @param string $reason Why — carried on the tombstone forever
     */
    public function retire(Note $note, string $by, ?string $reason = null): void
    {
        $this->whileHoldingNotes([$note], function () use ($note, $by, $reason): void {
            $id = (int) $note->getId();
            $title = $note->getTitle();
            $this->retireWithin($this->em->getConnection(), $note, $id, $by, $reason);
            $entry = new CuratorLogEntry('operator', CuratorLogEntry::ACTION_DELETE, 'Deleted “'.$title.'”'.($reason !== null && $reason !== '' ? ' — '.$reason : ''));
            if ($by === Note::ACTOR_MEMEX) {
                $entry = (new CuratorLogEntry('memex', CuratorLogEntry::ACTION_DELETE, $entry->getDescription()))->byMemex();
            }
            $this->journal->record($entry->withNote(null, $title)->rememberRetiredNote($id));
            $this->em->flush();
        });
    }

    /**
     * Run work that writes notes or proposals with the vault write-locked, once
     * the notes it is about are known to still be live. A transaction takes
     * the whole file's write lock when it begins, so there is no lock order to
     * keep. Callers enter before writing anything in the transaction.
     *
     * @param Note[] $notes
     */
    public function whileHoldingNotes(array $notes, callable $work): mixed
    {
        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            foreach ($notes as $note) {
                $found = $conn->fetchOne('SELECT id FROM notes WHERE id = :id', ['id' => $note->getId()]);
                if ($found === false) {
                    throw new AlreadyDecidedException('This note has already been retired.');
                }
            }
            $result = $work();
            $conn->commit();
            return $result;
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw StorageLimitExceeded::fromThrowable($e) ?? $e;
        }
    }

    /** The retirement itself, with the transaction as the caller's problem. */
    private function retireWithin(\Doctrine\DBAL\Connection $conn, Note $note, int $noteId, string $by, ?string $reason): void
    {
        $tags = $conn->fetchFirstColumn(
            'SELECT t.name FROM note_tag nt JOIN tags t ON t.id = nt.tag_id WHERE nt.note_id = :id ORDER BY '.SystemTags::sqlRank('t.name').', t.name',
            ['id' => $noteId]
        );

        $carried = implode(', ', self::CARRIED);
        $conn->executeStatement(
            "INSERT INTO deleted_notes (
                 id, $carried, tags,
                 note_created_at, note_updated_at, deleted_at, deleted_by, deleted_reason, purge_after
             )
             SELECT n.id, $carried, :tags,
                    n.created_at, n.updated_at, :now, :by, :reason, :purge_after
             FROM notes n WHERE n.id = :id",
            [
                'tags' => json_encode($tags, JSON_THROW_ON_ERROR),
                'now' => self::stamp(),
                'by' => mb_substr($by, 0, 120),
                'reason' => $reason,
                'purge_after' => self::stamp('+'.self::LIMBO_DAYS.' days'),
                'id' => $noteId,
            ]
        );

        // The FKs do the rest: note_tag/note_embeddings/note_links(from), any
        // held proposals and any curation flag cascade; note_links(to) and
        // curator_log null out. A flag is a to-do about a live note, so it goes
        // with the note — unlike its revisions, which are history and survive.
        //
        // …but only in the DATABASE. `curator_log.note_id` being ON DELETE SET
        // NULL says nothing about the CuratorLogEntry objects already in
        // Doctrine's identity map, which keep a PHP reference to this note. On
        // the next flush the unit of work walks that association, finds an
        // entity the remove has turned back into a NEW one, and throws — by
        // which time the retirement has committed. That is how approving a
        // curator-filed delete 500'd over a deletion that had happened and
        // threw away the operator's reasoning with it.
        // The log row is written at PROPOSE time, so the reference is already
        // there before the approval builds anything of its own.
        //
        // So mirror the FK in memory, here, where the removal is: one door,
        // every caller, including ones not written yet. And before nulling
        // anything, write down which note the nulls used to mean, so a restore
        // can reconnect the history instead of finding none.
        $this->discardHeldDrafts($note);
        $this->rememberCuratorHistory($noteId);
        $this->forgetInMemoryReferences($note);

        $this->em->remove($note);
        $this->em->flush();
        // Tag GC stays the caller's job (NoteWriter::gcTags owns that policy
        // for every write path) — depending on NoteWriter here would make the
        // cycle NoteWriter -> NoteLimbo -> NoteWriter.
    }

    /** @return EditProposal[] */
    public function heldDraftsForRetirement(Note $note): array
    {
        return $this->em->createQueryBuilder()
            ->select('p')->from(EditProposal::class, 'p')
            ->where('(p.note = :note OR p.mergeIntoNote = :note)')
            ->andWhere('p.status = :status')
            ->setParameter('note', $note)
            ->setParameter('status', EditProposal::STATUS_HELD)
            ->orderBy('p.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Retire the drafts held against a note, and say so in the journal.
     *
     * The FK would cascade them out anyway, which is the problem: a note is
     * restorable for 30 days and the work held against it is not, so a
     * deletion the owner reverses still cost an agent everything it had
     * written and nothing anywhere recorded that it had. Here rather than in
     * the delete routes because EVERY retirement discards them — the owner's
     * own DELETE, an approved delete proposal, the absorbed half of a merge —
     * and a guard at one door leaves the others silent.
     *
     * Removed explicitly rather than left to the cascade so the journal rows
     * are written while the proposals still exist to be described, and so
     * Doctrine is told about a removal the database would otherwise do behind
     * it.
     */
    private function discardHeldDrafts(Note $note): void
    {
        $drafts = $this->heldDraftsForRetirement($note);
        foreach ($drafts as $draft) {
            $this->em->persist(
                (new CuratorLogEntry(
                    'operator',
                    CuratorLogEntry::ACTION_REJECTED,
                    ($draft->getMergeIntoNote()?->getId() === $note->getId()
                        ? 'Deleting merge keeper “'.$note->getTitle().'” discarded the held merge of “'.$draft->getNote()?->getTitle().'” into it'
                        : 'Deleting “'.$note->getTitle().'” discarded the held '
                            .($draft->getType() === EditProposal::TYPE_REPORT ? 'report' : $draft->getType()))
                        .' filed by '.$draft->authorName().'. A restore brings the note back, not this.',
                ))->withNote($note)->aboutWorkBy($draft->getProposedByToken())
            );
            foreach ($this->em->getRepository(CuratorLogEntry::class)->findBy(['proposal' => $draft]) as $entry) {
                $entry->withProposal(null);
            }
            $this->em->remove($draft);
        }
        if ($drafts !== []) {
            $this->em->flush();
        }
    }

    /**
     * Null every loaded reference to a note that is about to stop existing,
     * for the one association whose FK is ON DELETE SET NULL and whose rows
     * outlive their note on purpose: the curator log.
     *
     * Cascading associations need nothing — their rows go and Doctrine knows
     * it. SET NULL is the awkward case: the database does the right thing and
     * the in-memory graph does not, and it is the in-memory graph that the
     * next flush inspects.
     *
     * The title copy is deliberately kept. It is what a verdict about a
     * deleted note is FOR — the row still reads "Operator approved the held
     * deletion of X" after X is gone.
     */
    private function forgetInMemoryReferences(Note $note): void
    {
        foreach ($this->em->getUnitOfWork()->getIdentityMap()[CuratorLogEntry::class] ?? [] as $entry) {
            if ($entry->getNote() === $note) {
                $entry->withNote(null, $entry->getNoteTitle());
            }
        }
    }

    /**
     * Record, on every log row about this note, which note its about-to-be-null
     * `note_id` used to mean — so restore can put it back.
     *
     * Raw SQL rather than the identity map: unlike the in-memory nulling above,
     * this has to reach EVERY row, including the ones no request has loaded.
     * It runs before the delete, while note_id still says which they are.
     */
    private function rememberCuratorHistory(int $noteId): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE curator_log SET retired_note_id = :id WHERE note_id = :id',
            ['id' => $noteId]
        );
    }

    /**
     * Bring a retired note back at its ORIGINAL id — inbound links, log rows
     * and anything the operator bookmarked all name notes by id, so a restore
     * that renumbered would quietly break them.
     *
     * Returns the restored note, or null if the row is gone or already purged
     * (a tombstone has no body to restore).
     */
    public function restore(int $id): ?Note
    {
        $conn = $this->em->getConnection();

        $conn->beginTransaction();
        try {
            // Inside the write transaction, because the operation this races
            // is purge(): read outside it, "restore reads → purge commits →
            // restore writes" puts the pre-purge body back and deletes the
            // tombstone, silently undoing the one operation whose promise is
            // that nothing survives it.
            $row = $conn->fetchAssociative(
                'SELECT * FROM deleted_notes WHERE id = :id AND purged_at IS NULL',
                ['id' => $id]
            );
            if ($row === false) {
                $conn->commit();

                return null;
            }
            $this->growth->assertNoteRoom();

            $carried = implode(', ', self::CARRIED);
            $placeholders = implode(', ', array_map(static fn (string $c): string => ':'.$c, self::CARRIED));
            $params = ['id' => $id, 'now' => self::stamp()];
            // Types, because this direction round-trips through PHP where
            // retire() is an INSERT…SELECT that never leaves the database. A
            // boolean read back as PHP false is bound untyped as the STRING '',
            // not 0. Every column carried today is text or an integer, so this
            // loop currently types nothing — see CARRIED_BOOLEANS for why it
            // stays anyway.
            $types = [];
            foreach (self::CARRIED as $column) {
                $params[$column] = $row[$column];
                if (in_array($column, self::CARRIED_BOOLEANS, true)) {
                    $params[$column] = (bool) $row[$column];
                    $types[$column] = ParameterType::BOOLEAN;
                }
            }
            $params['created_at'] = $row['note_created_at'];

            $conn->executeStatement(
                "INSERT INTO notes (id, $carried, created_at, updated_at)
                 VALUES (:id, $placeholders, :created_at, :now)",
                $params,
                $types
            );

            $tags = json_decode((string) $row['tags'], true, 8, JSON_THROW_ON_ERROR);
            if ($tags !== []) {
                $tagIds = $conn->fetchFirstColumn(
                    'SELECT id FROM tags WHERE name IN (:names)',
                    ['names' => $tags],
                    ['names' => ArrayParameterType::STRING]
                );
                $known = $conn->fetchFirstColumn(
                    'SELECT name FROM tags WHERE name IN (:names)',
                    ['names' => $tags],
                    ['names' => ArrayParameterType::STRING]
                );
                // Tags garbage-collected while the note sat in limbo have to
                // be recreated, or the note comes back partly untagged.
                foreach (array_diff($tags, $known) as $missing) {
                    $conn->executeStatement('INSERT INTO tags (name) VALUES (:name)', ['name' => $missing]);
                    $tagIds[] = (int) $conn->lastInsertId();
                }
                foreach ($tagIds as $tagId) {
                    $conn->executeStatement(
                        'INSERT INTO note_tag (note_id, tag_id) VALUES (:note, :tag) ON CONFLICT DO NOTHING',
                        ['note' => $id, 'tag' => (int) $tagId]
                    );
                }
            }

            $this->journal->record(
                (new CuratorLogEntry('operator', CuratorLogEntry::ACTION_RESTORE, 'Restored “'.$row['title'].'” from Deleted notes'))
                    ->withNote(null, (string) $row['title'])
                    ->rememberRetiredNote($id)
            );
            $this->em->flush();

            // The note is back at its original id, so its curation history can
            // point at it again. Without this a restored note came back with an
            // empty log, and log_recent(note_id:) — the curator's check for
            // whether the operator has already refused something — answered
            // "nothing" for precisely the notes with the most history.
            $conn->executeStatement(
                'UPDATE curator_log SET note_id = retired_note_id, retired_note_id = NULL
                 WHERE retired_note_id = :id',
                ['id' => $id]
            );

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        $this->em->clear();
        $note = $this->em->getRepository(Note::class)->find($id);
        if ($note !== null) {
            // Outbound links are re-derived from the body; inbound ones kept
            // raw_target and re-resolve through the usual catch-up. Embedding
            // was cascaded away — the sweep backfills it.
            $this->enricher->syncLinks($note);
        }

        return $note;
    }

    /**
     * The operator saying a note must genuinely be gone NOW — a leaked
     * credential or personal data, where "deleted but retained" is exactly the
     * wrong outcome (the charter's secrets rule).
     *
     * NOT the same operation as the 30-day sweep, though both end in a
     * tombstone, and the difference is what each one destroys. purgeExpired()
     * below is routine expiry: the note's time ran out, so the content goes and
     * the circumstances — why it was deleted, where it came from — stay as
     * curation history, which is what a later re-import warning reads. This one
     * is an emergency, and the circumstances are exactly where the operator is
     * most likely to have named the thing they are trying to destroy ("removing
     * this, it has Anna's home address in it"). So this destroys every free-text
     * field and the sweep does not.
     */
    public function purge(int $id): bool
    {
        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $tombstone = $conn->fetchAssociative('SELECT purged_at, title FROM deleted_notes WHERE id = :id', ['id' => $id]);

            // Everything free-text goes, not just the body.
            //
            // Until 2026-08-21 this nulled `body_md` and `summary` and stopped
            // there, so a secret in the source URL, the import path, the tags
            // or the operator's own deletion reason ("removing, it has Anna's
            // home address in it") survived permanently on a row whose whole
            // promise is that nothing does. That reason field is the sharpest
            // of them: the operator writes it AT the moment of deciding
            // something must not be retained, which is exactly when they are
            // most likely to name the thing.
            //
            // `title` is the deliberate exception, and it stays for a reason
            // that outranks tidiness: a purged tombstone is what stops a
            // re-imported vault from resurrecting the note (see
            // tombstonesByTitle and ImportController), and it can only match on
            // the title. Destroying it would mean the most dangerous content in
            // the system is the content easiest to walk back in through the
            // import screen. A title is also short and deliberate in a way the
            // other fields are not. If a secret is IN a title, that needs a
            // different operation and an explicit choice — filed, not assumed.
            $purged = $tombstone !== false && $conn->executeStatement(
                "UPDATE deleted_notes
                    SET body_md = NULL, summary = NULL, summary_by = NULL,
                        source_url = NULL, import_path = NULL, deleted_reason = NULL,
                        tags = '[]', purged_at = :now
                 WHERE id = :id AND purged_at IS NULL",
                ['id' => $id, 'now' => self::stamp()]
            ) > 0;

            // The note's previous states go with its body (2026-08-16). Purge
            // is the one operation whose entire value is that nothing survives
            // it — it exists for content that must not be retained, a leaked
            // credential being the named case — and a history table holding
            // twenty earlier copies of that body would make it a lie. Still
            // unconditional on $purged, so a second purge of an already-purged
            // tombstone leaves nothing behind.
            //
            // But conditional on the id BEING a tombstone. It was neither
            // before, and an id that is not in limbo at all is very often a
            // live note — a mistyped id, a stale limbo list, or a restore that
            // won the race just above. Forgetting then destroyed that live
            // note's entire history while the caller was told 404, nothing
            // happened. purgeExpired() never had this problem because it
            // collects the ids it actually swept first.
            if ($purged) {
                $title = (string) $tombstone['title'];
                $this->journal->record(
                    (new CuratorLogEntry('operator', CuratorLogEntry::ACTION_PURGE, 'Deleted “'.$title.'” for good'))->withNote(null, $title)
                );
                $this->em->flush();
            }
            if ($tombstone !== false) {
                $this->revisions->forget($id);
                // Nothing will ever restore this id, so the hint is a dangling
                // pointer to a note that cannot return. The log rows stay —
                // they are the record that it existed and was removed.
                $conn->executeStatement(
                    'UPDATE curator_log SET retired_note_id = NULL WHERE retired_note_id = :id',
                    ['id' => $id]
                );
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        return $purged;
    }

    /**
     * How many are past their limbo period in the bound vault — the sweep's
     * dry run. A suspended account's vault is not swept: its content is kept
     * whole, limbo included, and the caller walks only the others.
     */
    public function expiredCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM deleted_notes WHERE purged_at IS NULL AND purge_after <= :now',
            ['now' => self::stamp()]
        );
    }

    /** The 30-day sweep of the bound vault. Returns how many notes lost their content. */
    public function purgeExpired(): int
    {
        $conn = $this->em->getConnection();

        // The ids are needed because the UPDATE is what makes them unfindable —
        // after it runs there is no way to ask which notes this sweep took, and
        // their revisions would sit there holding the bodies the sweep was for.
        // Read, purge and forget in ONE transaction, which write-locks the vault
        // from BEGIN: a restore committing between the read and the UPDATE
        // would otherwise leave a live note whose every revision the DELETE
        // destroyed, and a note at exactly day 30 is still shown as restorable.
        // Not `UPDATE … RETURNING`: PHP's SQLite3 runs the statement once on
        // execute and again on the first fetch, so the ids come back empty.
        return $conn->transactional(function (\Doctrine\DBAL\Connection $conn): int {
            $now = self::stamp();
            $ids = array_map('intval', $conn->fetchFirstColumn(
                'SELECT id FROM deleted_notes WHERE purged_at IS NULL AND purge_after <= :now',
                ['now' => $now]
            ));
            if ($ids === []) {
                return 0;
            }
            $this->journal->record(
                (new CuratorLogEntry(
                    'memex',
                    CuratorLogEntry::ACTION_PURGE,
                    count($ids) === 1
                        ? 'Deleted 1 note for good, '.self::LIMBO_DAYS.' days after it was deleted'
                        : 'Deleted '.count($ids).' notes for good, '.self::LIMBO_DAYS.' days after they were deleted',
                ))->byMemex()
            );
            $this->em->flush();

            $conn->executeStatement(
                'UPDATE deleted_notes SET body_md = NULL, summary = NULL, purged_at = :now WHERE id IN (:ids)',
                ['now' => $now, 'ids' => $ids],
                ['ids' => ArrayParameterType::INTEGER]
            );
            $conn->executeStatement(
                'DELETE FROM note_revisions WHERE note_id IN (:ids)',
                ['ids' => $ids],
                ['ids' => ArrayParameterType::INTEGER]
            );

            return count($ids);
        });
    }

    public const PER_PAGE_MAX = 200;

    /**
     * How many rows a listing of this shape holds, for the pager.
     */
    public function count(bool $includePurged = false, string $query = ''): int
    {
        [$where, $params] = $this->listWhere($includePurged, $query);

        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM deleted_notes WHERE '.$where,
            $params
        );
    }

    /**
     * The one place the listing's filters are written, so a page and its own
     * total cannot come from different questions.
     *
     * A purged row keeps its title and nothing else, which is what makes a
     * tombstone findable at all — the reason is nulled by {@see purge()}, so
     * matching on it here cannot resurrect purged text.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function listWhere(bool $includePurged, string $query): array
    {
        $where = $includePurged ? '1 = 1' : 'purged_at IS NULL';
        $params = [];

        $query = trim($query);
        if ($query !== '') {
            $where .= " AND (title LIKE :q ESCAPE '\\' OR COALESCE(deleted_reason, '') LIKE :q ESCAPE '\\')";
            $params['q'] = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query).'%';
        }

        return [$where, $params];
    }

    /**
     * One page of limbo, newest first — paged, with the total from the same
     * question, so a vault with many tombstones can still reach every one.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(bool $includePurged = false, int $perPage = 20, int $offset = 0, string $query = ''): array
    {
        $perPage = max(1, min(self::PER_PAGE_MAX, $perPage));
        [$where, $params] = $this->listWhere($includePurged, $query);
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, title, summary, status, source, source_url, import_path, tags,
                    deleted_at, deleted_by, deleted_reason, purge_after, purged_at,
                    note_created_at, note_updated_at
             FROM deleted_notes
             WHERE '.$where.'
             ORDER BY deleted_at DESC, id DESC
             LIMIT :limit OFFSET :offset',
            $params + ['limit' => $perPage, 'offset' => max(0, $offset)]
        );

        return array_map(static function (array $r): array {
            $purged = $r['purged_at'] !== null;

            return [
                'note_id' => (int) $r['id'],
                'title' => $r['title'],
                'summary' => $r['summary'],
                'status' => $r['status'],
                'source' => $r['source'],
                'source_url' => $r['source_url'],
                'import_path' => $r['import_path'],
                'tags' => json_decode((string) $r['tags'], true, 8, JSON_THROW_ON_ERROR),
                'deleted_at' => (new \DateTimeImmutable($r['deleted_at']))->format(DATE_ATOM),
                'deleted_by' => $r['deleted_by'],
                'deleted_reason' => $r['deleted_reason'],
                'purge_after' => (new \DateTimeImmutable($r['purge_after']))->format(DATE_ATOM),
                'purged_at' => $purged ? (new \DateTimeImmutable($r['purged_at']))->format(DATE_ATOM) : null,
                // A purged row is a tombstone: it records that the note
                // existed and was retired, and cannot be brought back.
                'restorable' => !$purged,
                'days_left' => $purged ? 0 : max(0, (int) ceil(
                    ((new \DateTimeImmutable($r['purge_after']))->getTimestamp() - time()) / 86400
                )),
            ];
        }, $rows);
    }

    /**
     * Titles/paths that have been retired — the lookup that keeps a deletion
     * meaningful after the fact. Used by import (do not silently resurrect)
     * and available for link-rebinding checks.
     *
     * @param string[] $titles
     * @return array<string, array{note_id: int, deleted_at: string, deleted_reason: ?string, purged: bool}> keyed by lower-cased title
     */
    public function tombstonesByTitle(array $titles): array
    {
        if ($titles === []) {
            return [];
        }
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, title, deleted_at, deleted_reason, purged_at FROM deleted_notes
             WHERE LOWER(title) IN (:titles)',
            ['titles' => array_map('mb_strtolower', $titles)],
            ['titles' => ArrayParameterType::STRING]
        );

        $out = [];
        foreach ($rows as $r) {
            // Keyed by the lowercased title because that is how import matches
            // them; the value carries the title as the operator wrote it,
            // because that is what gets shown back to them.
            $out[mb_strtolower((string) $r['title'])] = [
                'title' => (string) $r['title'],
                'note_id' => (int) $r['id'],
                'deleted_at' => (new \DateTimeImmutable($r['deleted_at']))->format(DATE_ATOM),
                'deleted_reason' => $r['deleted_reason'],
                'purged' => $r['purged_at'] !== null,
            ];
        }

        return $out;
    }

    private static function stamp(string $modify = 'now'): string
    {
        return (new \DateTimeImmutable($modify))->format('Y-m-d H:i:s');
    }
}
