<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CuratorLogEntry;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

class CurationQueue
{
    public const NO_SUMMARY_SQL = "n.summary IS NULL OR trim(n.summary) = ''";

    public const UNTAGGED_SQL = 'NOT EXISTS (SELECT 1 FROM note_tag nt WHERE nt.note_id = n.id)';

    private const TAG_SPACE_GLOB = "'*[' || char(9, 10, 11, 12, 13, 32, 133, 160, 5760, 8192, 8193, 8194, 8195, 8196, 8197, 8198, 8199, 8200, 8201, 8202, 8232, 8233, 8239, 8287, 12288) || ']*'";

    private const PREDICATES = [
        'operator_flag' => 'cf.id IS NOT NULL',
        'untagged' => self::UNTAGGED_SQL,
        'no_summary' => self::NO_SUMMARY_SQL,
        'not_embedded' => 'NOT EXISTS (SELECT 1 FROM note_embeddings ne WHERE ne.note_id = n.id)',
        'disconnected' => 'NOT EXISTS (SELECT 1 FROM note_links l WHERE l.from_note_id = n.id AND l.to_note_id IS NOT NULL)
                 AND NOT EXISTS (SELECT 1 FROM note_links l WHERE l.to_note_id = n.id)',
        'dangling_links' => 'EXISTS (SELECT 1 FROM note_links l WHERE l.from_note_id = n.id AND l.to_note_id IS NULL)',
        'nonstandard_tags' => 'EXISTS (SELECT 1 FROM note_tag nt JOIN tags t ON t.id = nt.tag_id
                 WHERE nt.note_id = n.id
                   AND (t.name GLOB '.self::TAG_SPACE_GLOB.' OR t.name <> lower(t.name)))',
        'weak_title' => "trim(n.title) = '' OR (instr(n.title, ' ') = 0
                 AND (lower(n.title) GLOB '*.md' OR lower(n.title) GLOB '*.markdown' OR lower(n.title) GLOB '*.txt'
                   OR lower(n.title) GLOB '*.htm' OR lower(n.title) GLOB '*.html' OR lower(n.title) GLOB '*.pdf'
                   OR instr(n.title, '-') > 0 OR instr(n.title, '_') > 0 OR n.title GLOB '*[0-9]*'))",
    ];

    /** @return array{notes: list<array{id: int, title: string, missing: string[], created_at: string}>, total: int, returned: int, offset: int, awaiting_review: int} */
    public function needsEnrichment(int $limit, int $offset): array
    {
        $noSummary = self::PREDICATES['no_summary'];
        $untagged = self::PREDICATES['untagged'];
        $conn = $this->em->getConnection();
        $wanted = EnrichmentBacklog::sql();

        $total = (int) $conn->fetchOne("SELECT COUNT(*) FROM notes n WHERE $wanted");
        $resting = $conn->fetchAssociative(EnrichmentBacklog::restingSql()) ?: [];
        $rows = $conn->fetchAllAssociative(
            "SELECT n.id, n.title, n.created_at,
                    ($noSummary) AS no_summary,
                    ($untagged) AS untagged
             FROM notes n
             WHERE $wanted
             ORDER BY n.created_at ASC, n.id ASC
             LIMIT :limit OFFSET :offset",
            ['limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER]
        );

        $notes = [];
        foreach ($rows as $row) {
            $missing = [];
            if ($row['no_summary']) {
                $missing[] = 'summary';
            }
            if ($row['untagged']) {
                $missing[] = 'tags';
            }
            $notes[] = [
                'id' => (int) $row['id'],
                'title' => $row['title'],
                'missing' => $missing,
                'created_at' => (new \DateTimeImmutable($row['created_at']))->format(DATE_ATOM),
            ];
        }

        return [
            'notes' => $notes,
            'total' => $total,
            'returned' => count($notes),
            'offset' => $offset,
            'awaiting_review' => (int) ($resting['awaiting_review'] ?? 0),
        ];
    }

    public const DEFAULT_COOLDOWN_DAYS = 7;

    /**
     * How many distances a duplicates read may measure settling neighbour
     * lists before it answers from the table as it stands, about three
     * seconds; the embedding sweep settles the rest.
     */
    private const DUPLICATE_SETTLE_MEASUREMENTS = 300_000;

    public const REST_LADDER_CAP = 3;

    public const DEFAULT_BLAST_RADIUS_DAYS = 14;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NoteNeighbours $neighbours,
        private readonly EmbeddingSpace $space,
    ) {
    }

    /** @return string[] The defect names a candidate's `reasons` can contain. */
    public static function reasons(): array
    {
        return array_keys(self::PREDICATES);
    }


    public function noteHasDefect(int $noteId, string $reason): bool
    {
        if (!in_array($reason, self::reasons(), true) || $reason === 'operator_flag') {
            throw new \InvalidArgumentException('Not a structural defect: '.$reason);
        }

        return (bool) $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM notes n WHERE n.id = :note AND ('.self::PREDICATES[$reason].')',
            ['note' => $noteId],
            ['note' => ParameterType::INTEGER]
        );
    }

    private static function predicateHolds(mixed $value): bool
    {
        return !in_array($value, [false, 'f', 0, '0', '', null], true);
    }

    /**
     * @param int[] $noteIds
     * @return array<int, array{note_id: int, title: string, reasons: list<string>}> keyed by note id
     */
    public function defectsAmong(array $noteIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $noteIds)));
        if ($ids === []) {
            return [];
        }

        return $this->defects(
            ' WHERE n.id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );
    }

    /**
     * The same finding over the whole collection, for callers that must know
     * what is defective before they decide which notes to look at.
     *
     * @return array<int, array{note_id: int, title: string, reasons: list<string>}> keyed by note id
     */
    public function defectsForVault(): array
    {
        return $this->defects('', [], []);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     * @return array<int, array{note_id: int, title: string, reasons: list<string>}> keyed by note id
     */
    private function defects(string $where, array $params, array $types): array
    {
        $columns = [];
        foreach (self::PREDICATES as $reason => $sql) {
            if ($reason === 'operator_flag') {
                continue;
            }
            $columns[] = '('.$sql.') AS "'.$reason.'"';
        }

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT n.id, n.title, '.implode(', ', $columns).' FROM notes n'.$where,
            $params,
            $types,
        );

        $out = [];
        foreach ($rows as $row) {
            $reasons = [];
            foreach (self::reasons() as $reason) {
                if ($reason !== 'operator_flag' && self::predicateHolds($row[$reason] ?? null)) {
                    $reasons[] = $reason;
                }
            }
            if ($reasons !== []) {
                $out[(int) $row['id']] = [
                    'note_id' => (int) $row['id'],
                    'title' => (string) $row['title'],
                    'reasons' => $reasons,
                ];
            }
        }

        return $out;
    }

    /**
     * @param string|null $reason         Only notes carrying this defect
     * @param bool        $includePending Include pending notes (advisory only)
     * @param int|null    $staleDays      Only notes no pass has read in this many days
     * @return array{
     *     total: int,
     *     offset: int,
     *     cooldown_days: int,
     *     stale_days: int|null,
     *     defect_free: int,
     *     never_read: int,
     *     reason_counts: array<string, int>,
     *     candidates: array<int, array<string, mixed>>
     * }
     */
    /**
     * @param int[] $excludeNoteIds notes another curation run is already
     *                              working, withheld from the ROWS only —
     *                              {@see \App\Service\CurationLeases}
     */
    public function candidates(
        int $limit = 25,
        int $offset = 0,
        int $cooldownDays = self::DEFAULT_COOLDOWN_DAYS,
        ?string $reason = null,
        bool $includePending = false,
        ?int $staleDays = null,
        array $excludeNoteIds = [],
    ): array {
        if ($reason !== null && !in_array($reason, self::reasons(), true)) {
            throw new \InvalidArgumentException('reason must be one of: '.implode(', ', self::reasons()));
        }
        if ($staleDays !== null && $staleDays < 1) {
            throw new \InvalidArgumentException('stale_days must be at least 1');
        }

        $names = self::reasons();
        $predicates = self::PREDICATES;
        $flagSelect = implode(",\n                   ", array_map(
            static fn (string $name): string => '('.$predicates[$name].") AS $name",
            $names
        ));
        $defectSum = implode(' + ', array_values(array_diff($names, ['operator_flag'])));

        $statusWhere = $includePending ? '' : " AND (n.status = 'verified' OR cf.id IS NOT NULL)";
        $where = array_filter([
            $reason,
            $staleDays === null ? null : "(last_at IS NULL OR last_at < datetime(:now, '-' || :stale_days || ' days'))",
        ]);
        $reasonWhere = $where === [] ? '' : "\n            WHERE ".implode(' AND ', $where);

        $underReview = sprintf(CurationFlags::AWAITING_DESTRUCTIVE_REVIEW_SQL, 'n.id');

        $workOnly = CuratorLogEntry::curationWorkOnly('curator_log');
        $examined = CuratorLogEntry::ACTION_EXAMINED;
        $ladderCap = self::REST_LADDER_CAP;
        $cte = <<<SQL
        WITH last_log AS (
            SELECT note_id, MAX(created_at) AS last_at
            FROM curator_log
            WHERE note_id IS NOT NULL AND $workOnly
            GROUP BY note_id
        ),
        -- The rest ladder's counter: how many passes IN A ROW have read this
        -- note and found nothing to do. Newest row first; the first row that
        -- is not an `examined` breaks the streak, because the moment a note
        -- needed work it stopped being evergreen.
        log_ranked AS (
            SELECT note_id, action,
                   ROW_NUMBER() OVER (PARTITION BY note_id ORDER BY created_at DESC, id DESC) AS rn
            FROM curator_log
            WHERE note_id IS NOT NULL AND $workOnly
        ), first_work AS (
            SELECT note_id, MIN(rn) AS rn FROM log_ranked
            WHERE action <> '$examined' GROUP BY note_id
        ), streaks AS (
            SELECT r.note_id, COUNT(*) AS clean_passes
            FROM log_ranked r
            LEFT JOIN first_work fw ON fw.note_id = r.note_id
            WHERE r.action = '$examined' AND (fw.rn IS NULL OR r.rn < fw.rn)
            GROUP BY r.note_id
        ),
        -- Producer 3, folded in. A note is stale when what it links to (or
        -- what links to it) moved and it did not — so a neighbour's change
        -- since this note was last read both wakes it early and lifts it in
        -- the ranking. Curator-authored neighbour edits are excluded for the
        -- same reason the note's own are: a pass that edits A must not wake
        -- everything cited by A, or the vault never goes quiet.
        neighbour_moves AS (
            SELECT l.from_note_id AS note_id, nb.id AS nb_id, nb.updated_at AS nb_at
            FROM note_links l
            JOIN notes nb ON nb.id = l.to_note_id
            WHERE nb.last_actor <> 'curator'
            UNION
            SELECT l.to_note_id AS note_id, nb.id, nb.updated_at
            FROM note_links l
            JOIN notes nb ON nb.id = l.from_note_id
            WHERE l.to_note_id IS NOT NULL AND nb.last_actor <> 'curator'
        ), moved AS (
            -- Counted only for notes a pass HAS read. "Changed since never"
            -- is vacuously true, and letting it through would mark every
            -- unread note disturbed, collapsing the two bands into one and
            -- making the number meaningless on exactly the rows a curator
            -- reads it on. An unread note already ranks on being unread.
            SELECT nm.note_id,
                   COUNT(DISTINCT nm.nb_id) FILTER (
                       WHERE ll2.last_at IS NOT NULL AND nm.nb_at > ll2.last_at
                   ) AS changed_neighbours,
                   MAX(nm.nb_at) FILTER (
                       WHERE ll2.last_at IS NOT NULL AND nm.nb_at > ll2.last_at
                   ) AS neighbour_at
            FROM neighbour_moves nm
            LEFT JOIN last_log ll2 ON ll2.note_id = nm.note_id
            GROUP BY nm.note_id
        ), flags AS (
            SELECT n.id, n.title, n.status, n.updated_at, n.last_actor, ll.last_at,
                   COALESCE(st.clean_passes, 0) AS clean_passes,
                   COALESCE(mv.changed_neighbours, 0) AS changed_neighbours,
                   mv.neighbour_at,
                   cf.comment AS flag_comment, cf.flagged_by_name AS flag_by,
                   cf.created_at AS flag_at, cf.updated_at AS flag_reworded_at,
                   $flagSelect
            FROM notes n
            LEFT JOIN last_log ll ON ll.note_id = n.id
            LEFT JOIN streaks st ON st.note_id = n.id
            LEFT JOIN moved mv ON mv.note_id = n.id
            LEFT JOIN curation_flags cf
                   ON cf.note_id = n.id AND cf.resolved_at IS NULL
            WHERE NOT $underReview$statusWhere
        ), scored AS (
            SELECT f.*, ($defectSum) AS defects,
                   -- The rest this note has earned, in days. Doubling per
                   -- consecutive clean pass, capped: 7, 14, 28, 56. A
                   -- cooldown of 0 (a deliberate re-sweep) stays 0 through the
                   -- multiplication, which is what makes that override work.
                   :cooldown * (1 << min(COALESCE(f.clean_passes, 0), $ladderCap)) AS rest_days
            FROM flags f
        ), eligible AS (
            SELECT * FROM scored
            -- An open flag ignores the rest ladder entirely, deliberately. The
            -- ladder exists so a run does not re-litigate notes it keeps
            -- judging fine; a note the operator flagged AFTER that judgment is
            -- the one case where coming straight back is the whole point, and
            -- a flag raised the day after a pass would otherwise wait weeks.
            WHERE operator_flag
               OR (last_at IS NULL
                   -- The earned rest, not a flat week. A note three passes
                   -- have approved of rests eight times as long as one nobody
                   -- has read yet, which is how an evergreen cluster stops
                   -- consuming a budget of 15-25 notes a run forever.
                   OR last_at < datetime(:now, '-' || rest_days || ' days')
                   -- Three things cut the rest short, all of them "something
                   -- happened that this note has not been read against":
                   OR (updated_at > last_at AND last_actor <> 'curator')
                   OR neighbour_at IS NOT NULL
                   OR defects > 0)
        ), candidates AS (
            SELECT * FROM eligible$reasonWhere
        )
        SQL;

        $conn = $this->em->getConnection();
        $params = ['cooldown' => max(0, $cooldownDays), 'now' => self::now()];
        if ($staleDays !== null) {
            $params['stale_days'] = $staleDays;
        }

        $excludeNoteIds = array_values(array_map('intval', $excludeNoteIds));

        $leaseParams = $params;
        $leaseTypes = [];
        $leasedSelect = '';
        if ($excludeNoteIds !== []) {
            $leasedSelect = ', COUNT(*) FILTER (WHERE id IN (:leased)) AS leased';
            $leaseParams['leased'] = $excludeNoteIds;
            $leaseTypes['leased'] = \Doctrine\DBAL\ArrayParameterType::INTEGER;
        }
        $totals = $conn->executeQuery(
            $cte."\nSELECT COUNT(*) AS total$leasedSelect FROM candidates",
            $leaseParams,
            $leaseTypes
        )->fetchAssociative() ?: [];
        $total = (int) ($totals['total'] ?? 0);
        $leasedElsewhere = (int) ($totals['leased'] ?? 0);

        $countSelect = implode(', ', array_map(
            static fn (string $n): string => "COALESCE(SUM($n), 0) AS $n",
            $names
        ));
        $countSelect .= ", COUNT(*) FILTER (WHERE defects = 0 AND NOT operator_flag) AS defect_free"
            .", COUNT(*) FILTER (WHERE defects = 0 AND NOT operator_flag AND last_at IS NULL) AS never_read";
        $counts = $conn->executeQuery($cte."\nSELECT $countSelect FROM eligible", $params)->fetchAssociative() ?: [];

        if ($limit < 1) {
            return [
                'total' => $total,
                'offset' => $offset,
                'cooldown_days' => max(0, $cooldownDays),
                'stale_days' => $staleDays,
                'defect_free' => (int) ($counts['defect_free'] ?? 0),
                'never_read' => (int) ($counts['never_read'] ?? 0),
                'reason_counts' => array_map('intval', array_intersect_key($counts, array_flip($names))),
                'leased_elsewhere' => $leasedElsewhere,
                'candidates' => [],
            ];
        }

        $params['limit'] = $limit;
        $params['offset'] = $offset;
        $leaseWhere = '';
        $types = ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER];
        if ($excludeNoteIds !== []) {
            $leaseWhere = ' WHERE id NOT IN (:leased)';
            $params['leased'] = $excludeNoteIds;
            $types['leased'] = \Doctrine\DBAL\ArrayParameterType::INTEGER;
        }
        $rows = $conn->executeQuery(
            $cte."\nSELECT id, title, status, updated_at, last_at, defects,
                   clean_passes, changed_neighbours, neighbour_at, rest_days,
                   flag_comment, flag_by, flag_at, flag_reworded_at, ".implode(', ', $names).'
            FROM candidates'.$leaseWhere.'
            ORDER BY operator_flag DESC, flag_at ASC,
                     (defects > 0) DESC, defects DESC,
                     (changed_neighbours > 0) DESC, changed_neighbours DESC,
                     last_at ASC NULLS FIRST, id ASC
            LIMIT :limit OFFSET :offset',
            $params,
            $types
        )->fetchAllAssociative();

        return [
            'total' => $total,
            'offset' => $offset,
            'cooldown_days' => max(0, $cooldownDays),
            'stale_days' => $staleDays,
            'defect_free' => (int) ($counts['defect_free'] ?? 0),
            'never_read' => (int) ($counts['never_read'] ?? 0),
            'reason_counts' => array_map('intval', array_intersect_key($counts, array_flip($names))),
            'leased_elsewhere' => $leasedElsewhere,
            'candidates' => array_map(
                static fn (array $row): array => self::candidateRow($row, $names),
                $rows
            ),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param string[]             $names
     * @return array<string, mixed>
     */
    public static function candidateRow(array $row, array $names): array
    {
        $candidate = [
            'note_id' => (int) $row['id'],
            'title' => $row['title'],
            'status' => $row['status'],
            'reasons' => array_values(array_filter($names, static fn (string $n): bool => self::predicateHolds($row[$n] ?? null))),
            'defects' => (int) $row['defects'],
            'last_curated_at' => ($row['last_at'] ?? null) === null
                ? null
                : (new \DateTimeImmutable((string) $row['last_at']))->format(DATE_ATOM),
            'updated_at' => (new \DateTimeImmutable((string) $row['updated_at']))->format(DATE_ATOM),
        ];

        if (array_key_exists('clean_passes', $row)) {
            $candidate['clean_passes'] = (int) $row['clean_passes'];
            $candidate['rest_days'] = (int) $row['rest_days'];
        }
        if ((int) ($row['changed_neighbours'] ?? 0) > 0) {
            $candidate['changed_neighbours'] = (int) $row['changed_neighbours'];
            $candidate['neighbour_changed_at'] = ($row['neighbour_at'] ?? null) === null
                ? null
                : (new \DateTimeImmutable((string) $row['neighbour_at']))->format(DATE_ATOM);
        }

        if (($row['flag_comment'] ?? null) !== null) {
            $candidate['operator_flag'] = [
                'comment' => (string) $row['flag_comment'],
                'flagged_by' => (string) ($row['flag_by'] ?? ''),
                'flagged_at' => (new \DateTimeImmutable((string) $row['flag_at']))->format(DATE_ATOM),
                'reworded_at' => ($row['flag_reworded_at'] ?? null) === null
                    ? null
                    : (new \DateTimeImmutable((string) $row['flag_reworded_at']))->format(DATE_ATOM),
            ];
        }

        return $candidate;
    }

    /**
     * @return array{
     *     last_run_at: ?string,
     *     last_run_by: ?string,
     *     notes_changed_since: int,
     *     queue_total: int,
     *     never_read: int,
     *     defect_free: int,
     *     reason_counts: array<string, int>,
     *     due: bool,
     *     due_reasons: list<string>,
     *     candidates?: array<int, array<string, mixed>>
     * }
     */
    public function preflight(int $candidateLimit = 0): array
    {
        $conn = $this->em->getConnection();

        $last = $conn->fetchAssociative(
            'SELECT created_at, token_name FROM curator_log
             WHERE '.CuratorLogEntry::curationWorkOnly('curator_log').'
             ORDER BY created_at DESC, id DESC LIMIT 1'
        ) ?: null;
        $lastAt = $last === null ? null : new \DateTimeImmutable($last['created_at']);

        $changed = (int) ($lastAt === null
            ? $conn->fetchOne('SELECT COUNT(*) FROM notes')
            : $conn->fetchOne(
                "SELECT COUNT(*) FROM notes WHERE updated_at > :since AND last_actor <> 'curator'",
                ['since' => $lastAt->format('Y-m-d H:i:s')]
            ));

        $queue = $this->candidates(max(0, $candidateLimit));
        $counts = array_filter($queue['reason_counts'], static fn (int $n): bool => $n > 0);
        arsort($counts);

        $plural = static fn (int $n): string => 1 === $n ? 'note' : 'notes';

        $reasons = [];
        if ($lastAt === null) {
            $reasons[] = 'no pass has ever curated this knowledge base';
        }
        $flagged = (int) ($counts['operator_flag'] ?? 0);
        if ($flagged > 0) {
            $reasons[] = $flagged.' '.$plural($flagged).' the operator flagged — these come first';
        }
        foreach ($counts as $name => $n) {
            if ('operator_flag' === $name) {
                continue;
            }
            $reasons[] = $n.' '.$plural($n).' — '.$name;
        }
        $never = $queue['never_read'];
        $again = $queue['defect_free'] - $never;
        if ($never > 0) {
            $reasons[] = $never.' '.$plural($never).' no pass has ever read';
        }
        if ($again > 0) {
            $reasons[] = $again.' '.$plural($again).' with no structural defect, read before and due again';
        }

        $result = [
            'last_run_at' => $lastAt?->format(DATE_ATOM),
            'last_run_by' => $last['token_name'] ?? null,
            'notes_changed_since' => $changed,
            'queue_total' => $queue['total'],
            'never_read' => $queue['never_read'],
            'defect_free' => $queue['defect_free'],
            'reason_counts' => $queue['reason_counts'],
            'due' => $queue['total'] > 0,
            'due_reasons' => $reasons,
        ];

        if ($candidateLimit > 0) {
            $result['candidates'] = $queue['candidates'];
        }

        return $result;
    }

    /**
     * Pairs of notes that say the same thing: each note's five nearest in
     * meaning, kept where they fall inside `$maxDistance`, each pair once.
     *
     * Read from {@see NoteNeighbours}, whose lists are exact once settled.
     * `unsettled` counts notes whose lists were still being recomputed when
     * this answered; pairs involving them may be missing, never wrong.
     *
     * @return array{
     *     total: int,
     *     offset: int,
     *     max_distance: float,
     *     unsettled: int,
     *     pairs: array<int, array<string, mixed>>
     * }
     */
    public function duplicates(
        ?float $maxDistance = null,
        int $limit = 25,
        int $offset = 0,
        bool $includePending = false,
    ): array {
        $model = $this->space->model();
        $maxDistance ??= $model->duplicateDistance();
        if ($maxDistance <= 0.0 || $maxDistance > $model->neighbourHorizon()) {
            throw new \InvalidArgumentException('max_distance must be between 0 and '.$model->neighbourHorizon());
        }

        $statusWhere = $includePending ? '' : " AND l.status = 'verified' AND r.status = 'verified'";
        $unsettled = $this->neighbours->settle(self::DUPLICATE_SETTLE_MEASUREMENTS);

        $rows = $this->em->getConnection()->executeQuery(<<<SQL
            WITH pairs AS (
                SELECT MIN(note_id, neighbour_id) AS left_id, MAX(note_id, neighbour_id) AS right_id, MIN(distance) AS dist
                FROM note_neighbours
                WHERE distance < CAST(:max_distance AS REAL)
                GROUP BY 1, 2
            )
            SELECT p.dist, p.left_id, p.right_id,
                   l.title AS left_title, l.status AS left_status, l.updated_at AS left_updated,
                   r.title AS right_title, r.status AS right_status, r.updated_at AS right_updated
            FROM pairs p
            JOIN notes l ON l.id = p.left_id
            JOIN notes r ON r.id = p.right_id
            WHERE NOT EXISTS (
                SELECT 1 FROM edit_proposals ep
                WHERE ep.status = 'held'
                  AND (
                      (ep.type = 'merge' AND (
                          (ep.note_id = p.left_id AND ep.merge_into_note_id = p.right_id)
                       OR (ep.note_id = p.right_id AND ep.merge_into_note_id = p.left_id)))
                   OR (ep.type = 'delete' AND ep.note_id IN (p.left_id, p.right_id))
                  )
            )$statusWhere
            ORDER BY p.dist ASC, p.left_id ASC, p.right_id ASC
            SQL,
            ['max_distance' => $maxDistance],
        )->fetchAllAssociative();

        return [
            'total' => count($rows),
            'offset' => $offset,
            'max_distance' => $maxDistance,
            'unsettled' => $unsettled,
            'pairs' => array_map(static fn (array $row): array => [
                'distance' => round((float) $row['dist'], 3),
                'similarity' => round(1 - (float) $row['dist'], 3),
                'left' => [
                    'note_id' => (int) $row['left_id'],
                    'title' => $row['left_title'],
                    'status' => $row['left_status'],
                    'updated_at' => (new \DateTimeImmutable($row['left_updated']))->format(DATE_ATOM),
                ],
                'right' => [
                    'note_id' => (int) $row['right_id'],
                    'title' => $row['right_title'],
                    'status' => $row['right_status'],
                    'updated_at' => (new \DateTimeImmutable($row['right_updated']))->format(DATE_ATOM),
                ],
            ], array_slice($rows, max(0, $offset), max(0, $limit))),
        ];
    }

    /**
     * @return array{
     *     total: int,
     *     offset: int,
     *     since_days: int,
     *     notes: array<int, array<string, mixed>>
     * }
     */
    public function blastRadius(
        int $sinceDays = self::DEFAULT_BLAST_RADIUS_DAYS,
        int $limit = 25,
        int $offset = 0,
        bool $includePending = false,
    ): array {
        $statusWhere = $includePending ? '' : " AND n.status = 'verified'";
        $workOnly = CuratorLogEntry::curationWorkOnly('curator_log');

        $cte = <<<SQL
        WITH changed AS (
            SELECT n.id, n.title, n.updated_at, n.last_actor
            FROM notes n
            WHERE n.updated_at > datetime(:now, '-' || :since || ' days')
              AND n.last_actor <> 'curator'
        ), neighbours AS (
            -- Both link directions: a note that cites what changed, and a note
            -- that what changed cites. Either can be the one now out of date.
            SELECT l.to_note_id AS note_id, c.id AS src, c.title AS src_title,
                   c.updated_at AS src_at, c.last_actor AS src_actor
            FROM note_links l JOIN changed c ON c.id = l.from_note_id
            WHERE l.to_note_id IS NOT NULL
            UNION ALL
            SELECT l.from_note_id AS note_id, c.id AS src, c.title AS src_title,
                   c.updated_at AS src_at, c.last_actor AS src_actor
            FROM note_links l JOIN changed c ON c.id = l.to_note_id
        ), last_log AS (
            SELECT note_id, MAX(created_at) AS last_at
            FROM curator_log
            WHERE note_id IS NOT NULL
              AND {$workOnly}
            GROUP BY note_id
        ), affected AS (
            SELECT nb.note_id, n.title, n.status, n.updated_at, ll.last_at,
                   COUNT(DISTINCT nb.src) AS changed_neighbours,
                   MAX(nb.src_at) AS latest_change,
                   json_group_array(json_object(
                       'note_id', nb.src, 'title', nb.src_title,
                       'updated_at', nb.src_at, 'last_actor', nb.src_actor
                   )) AS sources
            FROM (SELECT DISTINCT note_id, src, src_title, src_at, src_actor FROM neighbours) nb
            JOIN notes n ON n.id = nb.note_id$statusWhere
            LEFT JOIN last_log ll ON ll.note_id = nb.note_id
            -- A note that changed on its own account is not blast radius; it
            -- is the blast. Producer 1 and the operator's own review have it.
            WHERE NOT EXISTS (SELECT 1 FROM changed c WHERE c.id = nb.note_id)
              -- Curated since that neighbour moved = already handled.
              AND (ll.last_at IS NULL OR ll.last_at < nb.src_at)
            GROUP BY nb.note_id, n.title, n.status, n.updated_at, ll.last_at
        )
        SQL;

        $conn = $this->em->getConnection();
        $params = ['now' => self::now(), 'since' => max(1, $sinceDays)];

        $total = (int) $conn->executeQuery($cte."\nSELECT COUNT(*) FROM affected", $params)->fetchOne();

        $params['limit'] = $limit;
        $params['offset'] = $offset;
        $rows = $conn->executeQuery(
            $cte."\nSELECT * FROM affected
            ORDER BY changed_neighbours DESC, latest_change DESC, note_id ASC
            LIMIT :limit OFFSET :offset",
            $params,
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER]
        )->fetchAllAssociative();

        return [
            'total' => $total,
            'offset' => $offset,
            'since_days' => max(1, $sinceDays),
            'notes' => array_map(static function (array $row): array {
                $sources = json_decode((string) $row['sources'], true, 8, JSON_THROW_ON_ERROR);
                usort($sources, static fn (array $a, array $b) => strcmp($b['updated_at'], $a['updated_at']));

                return [
                    'note_id' => (int) $row['note_id'],
                    'title' => $row['title'],
                    'status' => $row['status'],
                    'changed_neighbours' => (int) $row['changed_neighbours'],
                    'last_curated_at' => $row['last_at'] === null
                        ? null
                        : (new \DateTimeImmutable($row['last_at']))->format(DATE_ATOM),
                    'updated_at' => (new \DateTimeImmutable($row['updated_at']))->format(DATE_ATOM),
                    'changed' => array_map(static fn (array $s): array => [
                        'note_id' => (int) $s['note_id'],
                        'title' => $s['title'],
                        'last_actor' => $s['last_actor'],
                        'updated_at' => (new \DateTimeImmutable($s['updated_at']))->format(DATE_ATOM),
                    ], $sources),
                ];
            }, $rows),
        ];
    }

    /**
     * @param int[] $noteIds the notes just written; they are excluded from
     *                       their own answer, so a write does not report
     *                       itself back to itself
     * @return array{total: int, notes: array<int, array{note_id: int, title: string}>}
     *         `total` is exact; `notes` is the first $limit of it
     */
    public function citedBy(array $noteIds, int $limit = self::CITERS_NAMED): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $noteIds))));
        if ($ids === []) {
            return ['total' => 0, 'notes' => []];
        }

        $rows = $this->em->getConnection()->executeQuery(
            'SELECT c.id, c.title, COUNT(*) OVER () AS total
             FROM (
                 SELECT DISTINCT n.id, n.title, n.updated_at
                 FROM note_links l
                 JOIN notes n ON n.id = l.from_note_id
                 WHERE l.to_note_id IN (:ids) AND n.id NOT IN (:ids)
             ) c
             ORDER BY c.updated_at ASC, c.id ASC
             LIMIT :limit',
            ['ids' => $ids, 'limit' => $limit],
            [
                'ids' => ArrayParameterType::INTEGER,
                'limit' => ParameterType::INTEGER,
            ]
        )->fetchAllAssociative();

        return [
            'total' => $rows === [] ? 0 : (int) $rows[0]['total'],
            'notes' => array_map(static fn (array $r): array => [
                'note_id' => (int) $r['id'],
                'title' => (string) $r['title'],
            ], $rows),
        ];
    }

    public const CITERS_NAMED = 10;

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
