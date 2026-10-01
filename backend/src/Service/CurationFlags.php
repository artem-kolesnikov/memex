<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;
use App\Entity\CurationFlag;
use App\Entity\CuratorLogEntry;
use App\Entity\Note;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Raising, rewording, withdrawing and resolving an operator flag — the whole
 * lifecycle in one place, because every step of it writes two rows.
 *
 * A flag is never *only* a flag: each transition also appends to the Curator
 * log. That is not bookkeeping, it is the delivery mechanism. The curator
 * bootstraps every run from `log_recent`, so a flag raised at 11pm is read as
 * a message the same way an approve/reject verdict is, whether or not the run
 * ever reaches the queue verbs. The `curation_flags` row is what makes the
 * instruction *standing*; the log row is what makes it *heard*.
 *
 * Every method here is idempotent in the way the UI needs: flagging a flagged
 * note rewords it, clearing an unflagged note is not an error.
 */
class CurationFlags
{
    /**
     * "This note is already awaiting a verdict that would destroy or rewrite
     * it" — a printf template so the queue's correlated form and the service's
     * parameterised form cannot drift apart. `%1$s` is the note reference.
     *
     * Both directions of a merge count. The absorbed note disappears on
     * approval and the keeper's body is replaced, so neither is a sensible
     * thing to curate — the same reasoning `CurationQueue::duplicates()` has
     * used since 2026-08-08 to omit pairs already under review.
     */
    public const AWAITING_DESTRUCTIVE_REVIEW_SQL = 'EXISTS (
                SELECT 1 FROM edit_proposals ep
                WHERE ep.status = \'held\'
                  AND ((ep.type IN (\'delete\', \'merge\') AND ep.note_id = %1$s)
                    OR (ep.type = \'merge\' AND ep.merge_into_note_id = %1$s))
            )';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Is a held delete or merge already waiting on the operator for this note?
     *
     * The question a flag has to ask before it can be closed. Filing a deletion
     * is the *complete* action available to a curator — the note itself does not
     * go until the operator approves — so resolving the flag at filing time
     * closes the request on the strength of work that may yet be rejected. The
     * first real run made this concrete: 27 delete-flags resolved into held
     * proposals, and a rejection would have left 27 unflagged notes with nothing
     * done and no reminder anywhere (2026-08-17).
     */
    public function awaitingDestructiveReview(Note $note): bool
    {
        return (bool) $this->em->getConnection()->fetchOne(
            'SELECT '.sprintf(self::AWAITING_DESTRUCTIVE_REVIEW_SQL, ':id'),
            ['id' => $note->getId()]
        );
    }

    /**
     * Validate what someone typed into the flag box.
     *
     * A flag with no comment is the failure this feature exists to avoid: it
     * would rank a note without saying what to look at, and the curator would
     * have to re-derive an opinion the operator already holds. So the comment
     * is required, and the emptiness check happens after trimming — a box
     * holding one space is an empty box.
     *
     * @throws \InvalidArgumentException
     */
    public static function normalizeComment(mixed $raw): string
    {
        $comment = is_string($raw) ? trim($raw) : '';
        if ($comment === '') {
            throw new \InvalidArgumentException('A flag needs a comment: what is wrong with this note and what should the curator pay attention to?');
        }
        if (mb_strlen($comment) > CurationFlag::COMMENT_MAX) {
            throw new \InvalidArgumentException('comment must be at most '.CurationFlag::COMMENT_MAX.' characters');
        }

        return $comment;
    }

    public function open(Note $note): ?CurationFlag
    {
        return $this->em->getRepository(CurationFlag::class)
            ->findOneBy(['note' => $note, 'resolvedAt' => null]);
    }

    /**
     * Flag a note, or reword the flag it already carries.
     *
     * The log row differs between the two on purpose: "flagged" and "reworded"
     * are different events to a curator reading the log — the second means the
     * operator has looked again since, which is worth knowing when the run
     * decides what the instruction actually asks for.
     */
    public function raise(Note $note, string $flaggedByName, string $comment): CurationFlag
    {
        $flag = $this->open($note);
        $reworded = $flag !== null;
        if ($flag === null) {
            $flag = new CurationFlag($note, $flaggedByName, $comment);
            $this->em->persist($flag);
        } else {
            $flag->reword($comment);
        }

        $this->em->persist(
            (new CuratorLogEntry(
                $flaggedByName,
                CuratorLogEntry::ACTION_FLAG_RAISED,
                ($reworded ? 'Reworded the curation flag on “' : 'Flagged “')
                .$note->getTitle().($reworded ? '”: ' : '” for priority curation: ').$comment,
            ))->withNote($note)
        );
        $this->em->flush();

        return $flag;
    }

    /**
     * The operator withdrawing their own flag — "never mind", not "done".
     *
     * Recorded rather than deleted, and recorded as the operator's act rather
     * than the curator's, so a run that reads the log does not mistake a
     * withdrawal for work someone did.
     */
    public function withdraw(Note $note, string $withdrawnByName): ?CurationFlag
    {
        $flag = $this->open($note);
        if ($flag === null) {
            return null;
        }

        $flag->resolve(CurationFlag::RESOLVED_BY_OPERATOR, 'Withdrawn by the operator.');
        $this->em->persist(
            (new CuratorLogEntry(
                $withdrawnByName,
                CuratorLogEntry::ACTION_FLAG_RESOLVED,
                'Withdrew the curation flag on “'.$note->getTitle().'”.',
            ))->withNote($note)
        );
        $this->em->flush();

        return $flag;
    }

    /**
     * The curator closing a flag, with what it did about it.
     *
     * The resolution is mandatory at the call site (McpServer validates it)
     * because the alternative — a flag that can be cleared silently — turns the
     * whole channel into something an unattended run can make disappear. What
     * it says is not constrained: "rewrote the third section", "disagree, the
     * note is current, see the note it cites" and "cannot fix without information I do
     * not have" are all legitimate, and all readable by the operator, who can
     * flag it again if the answer is wrong.
     */
    /**
     * @param ApiToken|null $token the connection resolving it, so the log can
     *                             show what it is called now rather than what
     *                             it was called then
     */
    public function resolve(Note $note, string $tokenName, string $resolution, ?ApiToken $token = null): CurationFlag
    {
        $flag = $this->open($note);
        if ($flag === null) {
            throw new \InvalidArgumentException('Note '.$note->getId().' carries no open curation flag.');
        }

        $flag->resolve($tokenName, $resolution);
        $this->em->persist(
            (new CuratorLogEntry(
                $tokenName,
                CuratorLogEntry::ACTION_FLAG_RESOLVED,
                'Resolved the operator flag on “'.$note->getTitle().'”: '.$resolution,
            ))->withToken($token)->withNote($note)
        );
        $this->em->flush();

        return $flag;
    }

    /**
     * Which of these notes carry an open flag — one query for a whole page of
     * search results, so the list can mark them without a query per row.
     *
     * @param int[] $noteIds
     *
     * @return array<int, true> keyed by note id
     */
    public function openByNoteIds(array $noteIds): array
    {
        if ($noteIds === []) {
            return [];
        }

        $rows = $this->em->getConnection()->fetchFirstColumn(
            'SELECT note_id FROM curation_flags
             WHERE resolved_at IS NULL AND note_id IN (:ids)',
            ['ids' => array_values(array_map('intval', $noteIds))],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );

        return array_fill_keys(array_map('intval', $rows), true);
    }

    /**
     * Everything the owner has flagged and nobody has answered yet, oldest
     * first — "what have I asked for", as a list.
     *
     * The listing `idx_curation_flags_open` was created for, and the
     * gap it closes is that a flag is raised on the note view and then
     * disappears from the operator's sight: the queue verbs are curator-token
     * work, so the only way to find a standing flag from a browser was to
     * remember which note it was on.
     *
     * `awaiting_review` is why this is a query of its own rather than a filter
     * over the queue. A note with a held delete or merge leaves the queue
     * flag and all (see `candidates()`), which is correct there — the next run
     * must not re-propose what is already in the inbox — and would be a lie
     * here: the flag IS open, the operator IS waiting, and the reason it is
     * not in the queue is a verdict only they can give. Same SQL as the
     * suppression itself, so the two cannot come to disagree about which notes
     * those are.
     *
     * @return list<array<string, mixed>>
     */
    public function openList(int $limit = 25): array
    {
        $awaiting = sprintf(self::AWAITING_DESTRUCTIVE_REVIEW_SQL, 'f.note_id');

        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT n.id AS note_id, n.title, n.status, f.comment, f.flagged_by_name, f.created_at, f.updated_at,
                    $awaiting AS awaiting_review
             FROM curation_flags f
             JOIN notes n ON n.id = f.note_id
             WHERE f.resolved_at IS NULL
             ORDER BY f.created_at ASC, f.note_id ASC
             LIMIT :limit",
            ['limit' => max(1, $limit)],
            ['limit' => ParameterType::INTEGER]
        );

        return array_map(static fn (array $row): array => [
            'note_id' => (int) $row['note_id'],
            'title' => (string) $row['title'],
            'status' => (string) $row['status'],
            'comment' => (string) $row['comment'],
            'flagged_by' => (string) $row['flagged_by_name'],
            'flagged_at' => (new \DateTimeImmutable((string) $row['created_at']))->format(DATE_ATOM),
            'reworded_at' => ($row['updated_at'] ?? null) === null
                ? null
                : (new \DateTimeImmutable((string) $row['updated_at']))->format(DATE_ATOM),
            'awaiting_review' => (bool) $row['awaiting_review'],
        ], $rows);
    }

    /** How many notes the owner has flagged and nobody has answered yet. */
    public function openCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM curation_flags WHERE resolved_at IS NULL'
        );
    }
}
