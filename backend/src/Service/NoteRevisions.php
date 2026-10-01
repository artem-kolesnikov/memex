<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;
use App\Entity\Note;
use App\Entity\NoteRevision;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Psr\Log\LoggerInterface;

/**
 * A note's previous states: recorded, pruned, read back and destroyed.
 *
 * The counterpart of `NoteLimbo`. Limbo makes *deletion* reversible; this makes
 * *editing* reversible, and the two together are what let an unattended curator
 * write to the operator's only copy overnight without the operator having to
 * trust it absolutely.
 *
 * Recording is driven from `NoteWriter::update()` and nowhere else. That is not
 * a convention: `update()` is the sole call site of `setTitle`/`setBodyMd` in
 * the codebase, so a new write path has to go through it and therefore through
 * this — the same structural argument the review gate rests on.
 *
 * **Destruction is a first-class operation here, not an oversight.** Redacting
 * a leaked credential out of a note is an *edit*, so a history that kept
 * everything would faithfully preserve the thing the edit existed to remove.
 * `forget()` and `NoteLimbo::purge()` are the two ways out, both operator-only
 * and both genuinely irreversible.
 */
class NoteRevisions
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Snapshot a note as it stands, for the caller to keep or discard.
     *
     * Deliberately two calls rather than one: the caller takes this *before*
     * mutating, then hands it back to `record()` afterwards, which is the only
     * ordering that can capture what an edit replaced. A single method taking a
     * closure would read better and would not survive `update()`'s several
     * mutation steps.
     */
    public function snapshot(
        Note $note,
        ?string $actor,
        ?string $changeTitle = null,
        ?string $operation = null,
        ?ApiToken $actorToken = null,
    ): NoteRevision {
        // When the caller names no actor it is not writing as anyone new, so
        // the note's own last writer is the best answer for all three columns
        // at once — splitting them would attribute the ROLE to one connection
        // and the NAME to another.
        if ($actor === null) {
            return NoteRevision::capture(
                $note,
                $note->getLastActor(),
                $changeTitle,
                $operation,
                $note->getLastActorToken(),
            );
        }

        return NoteRevision::capture($note, $actor, $changeTitle, $operation, $actorToken);
    }

    /**
     * Keep the snapshot if the note actually changed, and prune to the window.
     *
     * The no-change case is common rather than theoretical: `update()` is
     * reached by a merge that only moved tags, by an enrichment re-run, and by
     * a proposal whose fields are all null. Recording those would fill a
     * twenty-deep window with copies of the present and push the states
     * somebody might want out of the far end.
     */
    public function record(NoteRevision $snapshot, Note $note): bool
    {
        if (!$snapshot->differsFrom($note)) {
            return false;
        }
        $this->em->persist($snapshot);
        $this->em->flush();
        $this->prune($note->getId());

        return true;
    }

    /**
     * Drop everything past the newest `KEEP_PER_NOTE` for one note.
     *
     * In SQL rather than through the ORM: this runs after every edit, and
     * hydrating twenty entities to delete one of them is work done once per
     * write, forever, for no benefit.
     */
    public function prune(?int $noteId): void
    {
        if ($noteId === null) {
            return;
        }
        $this->em->getConnection()->executeStatement(
            'DELETE FROM note_revisions WHERE id IN (
                 SELECT id FROM note_revisions
                 WHERE note_id = :note
                 ORDER BY replaced_at DESC, id DESC
                 LIMIT -1 OFFSET :keep
             )',
            ['note' => $noteId, 'keep' => NoteRevision::KEEP_PER_NOTE]
        );
    }

    /**
     * One note's history, newest first. By the revision's own column rather
     * than a join to the note: a note in limbo has no row in `notes`, and its
     * history is precisely what somebody restoring it wants to see.
     *
     * @return NoteRevision[]
     */
    public function forNote(int $noteId): array
    {
        return $this->em->getRepository(NoteRevision::class)->createQueryBuilder('r')
            ->where('r.noteId = :note')
            ->setParameter('note', $noteId)
            ->orderBy('r.replacedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The reconstructed body of every revision of one note, keyed by id.
     *
     * Walks newest-first from the note as it stands now, applying each stored
     * patch to the state in front of it. A row that carries a body rather than
     * a patch is an anchor and simply replaces the running text.
     *
     * A patch that will not apply yields null FOR THAT ROW AND THE OLDER ONES
     * BEHIND IT, and no exception. That is the deliberate failure mode: the
     * note itself is not in this table and is never at risk, the newer
     * revisions were resolved before the bad row was reached, and the history
     * section still renders the dates. Throwing would let one corrupt row hide
     * a note behind a 500.
     *
     * @param NoteRevision[] $revisions newest first, as `forNote()` returns them
     *
     * @return array<int, string|null>
     */
    public function bodiesFor(int $noteId, array $revisions): array
    {
        $history = $this->historyFor($noteId);
        $ids = array_map(static fn (NoteRevision $r): int => (int) $r->getId(), $revisions);

        return array_intersect_key($history['bodies'], array_flip($ids));
    }

    /** @return array{revisions: list<NoteRevision>, bodies: array<int, string|null>} */
    public function historyFor(int $noteId): array
    {
        $mapping = new ResultSetMappingBuilder($this->em);
        $mapping->addRootEntityFromClassMetadata(NoteRevision::class, 'r');
        $mapping->addScalarResult('history_base', 'history_base');
        $query = $this->em->createNativeQuery(
            'SELECT r.*, COALESCE(n.body_md, d.body_md) AS history_base
             FROM note_revisions r
             LEFT JOIN notes n ON n.id = r.note_id
             LEFT JOIN deleted_notes d ON d.id = r.note_id AND d.purged_at IS NULL
             WHERE r.note_id = :note
             ORDER BY r.replaced_at DESC, r.id DESC',
            $mapping,
        );
        $query->setParameter('note', $noteId);
        $query->setHint(Query::HINT_REFRESH, true);
        $rows = $query->getResult();
        $revisions = array_map(static fn (array $row): NoteRevision => $row[0], $rows);
        $current = $rows[0]['history_base'] ?? null;
        $bodies = [];

        foreach ($revisions as $revision) {
            $body = $revision->getBodyMd();
            if ($body === null) {
                $ops = $revision->getBodyDiffOps();
                try {
                    $sourceHash = $revision->getBodyDiffSourceHash();
                    if ($sourceHash !== null && ($current === null || !hash_equals($sourceHash, hash('sha256', $current)))) {
                        throw new \RuntimeException('Revision delta source hash does not match its base.');
                    }
                    $body = ($ops === null || $current === null) ? null : LineDiff::apply($current, $ops);
                } catch (\RuntimeException $e) {
                    $this->logger->error('A note revision could not be reconstructed', [
                        'note' => $noteId,
                        'revision' => $revision->getId(),
                        'error' => $e->getMessage(),
                    ]);
                    $body = null;
                }
            }
            $bodies[(int) $revision->getId()] = $body;
            // A break propagates BACKWARDS. Keeping the previous `$current`
            // here would resolve the next row's patch against the wrong text
            // and hand back a plausible, wrong previous version of somebody's
            // note — which is worse than admitting the row is unreadable, and
            // is what this did until a test asked for the second row after a
            // corrupt one. An anchor row re-establishes the chain on its own,
            // since it carries its body rather than a patch.
            $current = $body;
        }

        return ['revisions' => $revisions, 'bodies' => $bodies];
    }

    /** One revision's reconstructed body — the chain has to be walked to reach it. */
    public function bodyFor(NoteRevision $revision): ?string
    {
        $noteId = $revision->getNoteId();

        return $this->historyFor($noteId)['bodies'][(int) $revision->getId()] ?? null;
    }

    public function find(int $revisionId): ?NoteRevision
    {
        return $this->em->getRepository(NoteRevision::class)->find($revisionId);
    }

    public function countFor(int $noteId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM note_revisions WHERE note_id = :note',
            ['note' => $noteId]
        );
    }

    /**
     * Destroy one note's history, keeping the note.
     *
     * The redact-in-place case, and the reason it is an explicit operator
     * action rather than something inferred: an edit that removes a credential
     * cannot be told from any other edit by looking at it, so the only honest
     * design is to let the person who knows say so. Irreversible on purpose —
     * this is the operation whose entire value is that nothing survives it.
     *
     * @return int how many revisions were destroyed
     */
    public function forget(int $noteId): int
    {
        $conn = $this->em->getConnection();
        return $conn->transactional(function () use ($conn, $noteId): int {
            $params = ['note' => $noteId];
            if ($conn->fetchOne(
                "SELECT id FROM edit_proposals
                 WHERE (note_id = :note OR merge_into_note_id = :note) AND status = 'held' LIMIT 1",
                $params,
            ) !== false) {
                throw new ConflictHttpException('Decide or discard pending proposals for this note before forgetting its history.');
            }
            $forgotten = $conn->executeStatement(
                'DELETE FROM note_revisions WHERE note_id = :note', $params,
            );
            $conn->executeStatement(
                "WITH related AS (
                    SELECT id, run_id, curation_run_id FROM curator_log WHERE
                        note_id = :note OR retired_note_id = :note
                        OR EXISTS (SELECT 1 FROM json_each(curator_log.affected_note_ids) a WHERE a.value = :note)
                        OR proposal_id IN (SELECT id FROM edit_proposals
                            WHERE note_id = :note OR merge_into_note_id = :note)
                 )
                 UPDATE curator_log SET description = 'Content removed when note history was forgotten.',
                     operator_comment = NULL, note_title = NULL
                 WHERE id IN (SELECT id FROM related)
                    OR id IN (SELECT run_id FROM related) OR id IN (SELECT curation_run_id FROM related)",
                $params,
                ['note' => ParameterType::INTEGER],
            );
            $conn->executeStatement(
                "UPDATE edit_proposals SET proposed_title = NULL, proposed_body_md = NULL,
                 proposed_summary = NULL, proposed_tags = NULL, proposed_patch = NULL,
                 prev_title = NULL, prev_body_md = NULL, prev_summary = NULL, prev_tags = NULL,
                 comment = NULL, change_title = NULL
                 WHERE (note_id = :note OR merge_into_note_id = :note) AND status <> 'held'",
                $params,
            );
            return $forgotten;
        });
    }
}
