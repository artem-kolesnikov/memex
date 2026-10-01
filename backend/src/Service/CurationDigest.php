<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CuratorLogEntry;
use App\Entity\EditProposal;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What curation actually did, run by run — the digest the Activity Journal
 * carries above its log.
 *
 * ## What a "run" is here, and why the server cannot work it out alone
 *
 * Nothing in memex identifies a curation run at write time. That is not an
 * oversight: C-9 considered a run id in the tool schema and refused it — *"a
 * lease belongs to a CONNECTION, not to a run… the alternative is a run id in
 * the tool schema, bought for a case nobody has"*. A pass announces itself
 * only when it finishes, by filing a `run-summary`.
 *
 * A connection is identified by its TOKEN where there is one and by the
 * recorded name otherwise — the same rule {@see \App\Controller\CuratorLogController}
 * filters by, and for the same reason: renaming a connection must not split
 * its history in two, and rows written before tokens were linked keep only the
 * string. Scoping to a connection also drops the operator's own rows out of
 * every window for free: approvals and rejections are written as `operator`
 * and flags under the person's own name, so no window can swallow a verdict
 * and count it as curation.
 *
 * **Two limits of that identity, both inherited rather than introduced here,
 * and both stated because they look like defects** (raised by Codex,
 * 2026-08-26):
 *
 *  - Two token-less connections that were recorded under the SAME NAME are one
 *    connection to every reader in this codebase, this one included. Nothing
 *    in the data separates them; the alternative is inventing a distinction
 *    the rows do not carry.
 *  - Two processes sharing ONE curator token are one connection too, so two
 *    overlapping passes on one token interleave into each other's windows.
 *    C-9 met the same limit in `CurationLeases` and wrote it down there: *"a
 *    lease belongs to a CONNECTION, not to a run… the alternative is a run id
 *    in the tool schema, bought for a case nobody has."* That reasoning
 *    governs here. The day somebody genuinely runs one token twice at once,
 *    both are one decision.
 *
 * The window's END is the run-summary row. Its START is one of two things, and
 * **which one it is changes what the digest is allowed to say**:
 *
 *  - **`run_started_at`, the pass's own statement of when it began.** Then the
 *    counts are the RUN's, and a claim may be checked against them. The
 *    statement is bounded rather than trusted: the tool refuses a start in the
 *    future, and this class clamps it forward to that connection's previous
 *    run-summary, so no pass can reach back over another pass's work.
 *  - **The previous run-summary by the same connection**, when the pass did
 *    not say. That is *everything this connection has written since its last
 *    pass*, which is honest but is not the run — and the difference is not
 *    academic. Measured on the operator's own vault: the nightly pass of
 *    2026-08-26 applied no edits and reported so, while that fallback window
 *    held sixteen edits and eight new notes, every one of them the same
 *    connection being used by hand during the day. So a fallback window is
 *    LABELLED as one and **fires no tripwire**: a claim must never be
 *    contradicted by rows the server could not attribute to the run.
 *
 * `examined` rows are counted by their `run_id` and not by the window at all.
 * They are persisted after the summary they belong to, in the same request and
 * so in the same second, with a higher id — so a window ending at the summary
 * excludes every one of them, and one ending at the next summary files a
 * pass's reading under the FOLLOWING pass. Both were measured before that
 * column existed; see {@see \DoctrineMigrations\Version20260827000047}.
 *
 * **One consequence worth knowing before reading a digest**, deliberate: one
 * pass may file two summaries. The vault holds *"Addendum to run-summary 597
 * (same pass)"*, eighteen minutes after 597. That is a second, nearly empty
 * run here, and it is left that way on purpose — folding two summaries into
 * one on a time heuristic would be the digest deciding what a pass was, which
 * is exactly the synthesis both adversaries killed. A short entry with prose
 * and no counts is the truth.
 *
 * ## What is computed and what is quoted
 *
 * Every NUMBER here is the server's, counted from rows it wrote itself. The
 * only agent-authored text is the run-summary's own `description`, passed
 * through verbatim for the screen to quote and label. Nothing is synthesized:
 * server-side prose would need an LLM inside memex, which is forbidden, and
 * agent-side prose is the curator writing its own performance review.
 */
class CurationDigest
{
    /**
     * The claims a run-summary may file, and the log actions each is checked
     * against.
     *
     * One constant, because three things have to agree about what `edited`
     * means: the `log` verb that validates the claim, the charter that asks
     * the curator to file it, and the comparison that calls a run out for
     * getting it wrong. A second definition anywhere is a tripwire that fires
     * on a disagreement about vocabulary rather than on a real discrepancy.
     *
     * The three keys are the 2026-08-26 ruling's, and they are disjoint:
     * `edited` is what applied on the spot, `held` is a safe change the pass
     * chose to send for review instead, `proposed` is the two operations that
     * are held from every role whatever it wanted.
     *
     * `create`, `examined` and `flag-resolved` are counted in the digest but
     * are deliberately NOT claimable. `examined` already arrives as a list the
     * server writes its rows from, so a claim about it could not disagree;
     * the other two have never been miscounted by a pass and a claim key that
     * nothing checks is a key the charter has to explain for nothing.
     */
    public const CLAIMS = [
        'edited' => [CuratorLogEntry::ACTION_EDIT],
        'held' => [CuratorLogEntry::ACTION_EDIT_PROPOSED],
        'proposed' => [CuratorLogEntry::ACTION_DELETE_PROPOSED, CuratorLogEntry::ACTION_MERGE_PROPOSED],
    ];

    /**
     * A claimed count above this is a mistake rather than a big pass — the
     * same argument as {@see McpServer::EXAMINED_MAX},
     * and the number is deliberately generous against a charter that asks for
     * 15-25 notes a run.
     */
    public const CLAIM_MAX = 1000;

    /** Runs rendered by default, and the ceiling a caller may ask for. */
    public const DEFAULT_RUNS = 10;
    public const MAX_RUNS = 25;

    /**
     * Exception rows listed per run before the list is cut.
     *
     * Both caps travel with a total, because a cap that cannot be seen is the
     * part that is wrong (Codex, 2026-08-29, on the flag list shipping 25 of
     * 26 in silence).
     */
    private const DEFECTS_SHOWN = 10;
    private const WAITING_SHOWN = 10;
    private const TOUCHED_SHOWN = 25;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurationQueue $queue,
    ) {
    }

    /**
     * The most recent runs, newest first, each with its window's counts, its
     * exceptions and its own prose.
     *
     * @return array{runs: list<array<string, mixed>>, runs_total: int}
     */
    public function recent(int $limit = self::DEFAULT_RUNS): array
    {
        $limit = max(1, min(self::MAX_RUNS, $limit));
        $runs = $this->runWindows($limit);
        if ($runs === []) {
            return ['runs' => [], 'runs_total' => 0];
        }

        $counts = $this->countsByRun($runs);
        $examined = $this->examinedByRun($runs);
        $written = $this->writtenByRun($runs);
        $waiting = $this->waitingByRun($runs);
        // One defect query for every note in every rendered run, rather than
        // one per note per reason — ten runs of twenty notes across nine
        // predicates is 1,800 round trips done the obvious way.
        $allExamined = [];
        foreach ($examined as $run) {
            foreach ($run['ids'] as $noteId) {
                $allExamined[$noteId] = true;
            }
        }
        $defects = $this->queue->defectsAmong(array_keys($allExamined));

        $out = [];
        foreach ($runs as $run) {
            $runId = $run['id'];
            $byAction = $counts[$runId] ?? [];
            $ids = $examined[$runId]['ids'] ?? [];

            $stillDefective = [];
            foreach ($ids as $noteId) {
                if (isset($defects[$noteId])) {
                    $stillDefective[] = $defects[$noteId];
                }
            }

            $runWaiting = $waiting[$runId] ?? [];
            $runWritten = $written[$runId] ?? [];
            $runExamined = $examined[$runId]['notes'] ?? [];

            $out[] = [
                'log_id' => $run['id'],
                'at' => self::atom($run['created_at']),
                'by' => $run['writer'],
                // The value CuratorLogController's `writer` filter takes, so
                // the digest can scope the table underneath it in one click.
                'writer' => $run['conn_filter'],
                // Where the counts start. Null means the window reaches back
                // to everything this connection had ever written — a first
                // recorded pass that did not state its own start.
                'window_from' => self::atom($run['from_at']),
                // False = the pass did not say when it began, so this window
                // is "since your last pass" rather than the run, and no claim
                // is checked against it. The screen has to say which.
                'window_bounded' => $run['bounded'],
                'description' => $run['description'],
                'counts' => $this->countsFor($byAction, $examined[$runId]['total'] ?? 0),
                'claims' => $run['claims'],
                'mismatches' => $run['bounded'] ? $this->mismatches($run['claims'], $byAction) : [],
                'still_defective' => array_slice($stillDefective, 0, self::DEFECTS_SHOWN),
                'still_defective_total' => count($stillDefective),
                'waiting' => array_slice($runWaiting, 0, self::WAITING_SHOWN),
                'waiting_total' => count($runWaiting),
                'written' => array_slice($runWritten, 0, self::TOUCHED_SHOWN),
                'written_total' => count($runWritten),
                'examined' => array_slice($runExamined, 0, self::TOUCHED_SHOWN),
            ];
        }

        return [
            'runs' => $out,
            'runs_total' => (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM curator_log WHERE action = :action',
                ['action' => 'run-summary'],
            ),
        ];
    }

    /**
     * The window of one run, for a reader that wants the ROWS rather than the
     * counts — {@see \App\Controller\CuratorLogController} scoping the journal
     * to a run.
     *
     * Shared rather than reimplemented there, because a digest whose counts
     * and whose "show me these rows" link disagreed about what a run was would
     * be worse than having no link at all.
     *
     * @return array{id: int, conn: string, conn_filter: string, writer: string, description: string,
     *               claims: array<string, int>|null, created_at: string, from_at: ?string, from_id: ?int,
     *               bounded: bool, stated_start: ?string, prev_at: ?string, prev_id: ?int}|null
     *               null when there is no run-summary with that id
     */
    public function window(int $runLogId): ?array
    {
        return $this->runWindows(1, $runLogId)[0] ?? null;
    }

    /**
     * Mark every row of a finished pass as that pass's work, so the journal
     * can separate curation from ordinary agent activity (operator,
     * 2026-08-29).
     *
     * Called once, by the `log` verb, the moment a run-summary lands. The
     * summary itself and its `examined` rows are stamped by their writer;
     * this covers the rows the pass produced before it, which is everything
     * inside its window.
     *
     * **Only a BOUNDED window is stamped**, and that is the whole judgment
     * here. The fallback window is *everything this connection has written
     * since its last pass*, which this class already documents as frequently
     * not the run at all — the pass of 2026-08-26 applied nothing and said so
     * while its fallback window held sixteen edits and eight new notes, every
     * one of them the same connection driven by hand during the day. The
     * filter under-reporting an unbounded pass is a gap; filing the operator's
     * own afternoon under curation is a lie, and this column exists to remove
     * exactly that confusion.
     *
     * `curation_run_id IS NULL` keeps a row with the earliest pass that
     * claimed it. Two overlapping passes on one token are one connection to
     * every reader in this codebase (see the class note), so a later window
     * reaching over an earlier one takes nothing back.
     *
     * @return int rows stamped
     */
    public function stampRun(int $runLogId): int
    {
        $window = $this->window($runLogId);
        if ($window === null || !$window['bounded']) {
            return 0;
        }

        return (int) $this->em->getConnection()->executeStatement(
            'UPDATE curator_log AS e
                SET curation_run_id = :run
              WHERE e.curation_run_id IS NULL
                AND '.self::CONN_EXPR.' = :conn
                AND (e.created_at, e.id) > (:fromAt, :fromId)
                AND (e.created_at, e.id) <= (:toAt, :toId)',
            [
                'run' => $runLogId,
                'conn' => $window['conn'],
                'fromAt' => $window['from_at'],
                'fromId' => $window['from_id'] ?? 0,
                'toAt' => $window['created_at'],
                'toId' => $window['id'],
            ],
            [
                'run' => ParameterType::INTEGER,
                'fromId' => ParameterType::INTEGER,
                'toId' => ParameterType::INTEGER,
            ],
        );
    }

    /**
     * Each run-summary with the boundary of its window: the previous
     * run-summary by the SAME connection, as a (timestamp, id) pair.
     *
     * The id half is not decoration. `curator_log.created_at` holds whole
     * seconds, and a pass files its run-summary in the
     * same second as the last row it wrote often enough that comparing
     * timestamps alone would put that row in the wrong window, or in both.
     * Every comparison here is on the tuple.
     *
     * The LAG runs over the whole log whatever is asked for, so the oldest row
     * returned keeps its real predecessor rather than being reported as a
     * connection's first pass — the limit and the id filter are applied
     * outside the window, never inside it.
     *
     * @param int|null $onlyId a single run-summary, for the row-level reader
     *
     * @return list<array<string, mixed>>
     */
    private function runWindows(int $limit, ?int $onlyId = null): array
    {
        $conn = self::CONN_EXPR;
        $sql = <<<SQL
            WITH runs AS (
                SELECT e.id, e.token_id, e.token_name, e.description, e.claims, e.created_at, e.run_started_at,
                       {$conn} AS conn,
                       LAG(e.created_at) OVER w AS prev_at,
                       LAG(e.id)         OVER w AS prev_id
                FROM curator_log e
                WHERE e.action = 'run-summary'
                WINDOW w AS (
                    PARTITION BY {$conn}
                    ORDER BY e.created_at, e.id
                )
            )
            SELECT r.*, t.name AS token_current_name, t.display_name AS token_display_name
            FROM runs r
            LEFT JOIN api_tokens t ON t.id = r.token_id
            SQL;
        $params = [];
        if ($onlyId !== null) {
            $sql .= "\nWHERE r.id = :run";
            $params['run'] = $onlyId;
        }
        $sql .= "\nORDER BY r.created_at DESC, r.id DESC\nLIMIT ".$limit;

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative($sql, $params);

        return array_map(static function (array $row): array {
            $tokenId = $row['token_id'] === null ? null : (int) $row['token_id'];
            $prevAt = $row['prev_at'] === null ? null : (string) $row['prev_at'];
            $stated = $row['run_started_at'] === null ? null : (string) $row['run_started_at'];
            // Clamped forward, never back. A pass that names a start earlier
            // than its connection's previous run-summary would otherwise count
            // that pass's work as its own — the one thing an unverifiable
            // statement about a boundary could be used for.
            //
            // A stated start is only USED when it actually narrows the window:
            // later than the connection's previous run-summary, and no later
            // than the summary that ends the run. Outside that range it is
            // ignored and the fallback is used — and `bounded` follows the
            // start that was USED, never the fact that a value arrived. Codex
            // found both halves of this on 2026-08-26: a stale start earlier
            // than the previous pass, and a start inside the tool's 60-second
            // future allowance, each left `bounded: true` over a window the
            // server had not established, which is precisely the thing that
            // licenses the tripwire.
            //
            // The stated start is rounded UP to the next second before it is
            // compared. `created_at` holds whole seconds, so a row written at
            // 10:00:00.4 and a start of 10:00:00.6 are stored as the same
            // second and `> (from_at, 0)` would take the earlier row into the
            // run. Rounding up errs toward a SMALLER window, which is the
            // direction every other decision about an unverifiable boundary
            // errs here, and it costs nothing real: a pass's first write is
            // never in the same second as its own start.
            //
            // One consequence, harmless and left alone: a pass that begins and
            // ends inside ONE second has its rounded-up start pushed past its
            // own summary, so it falls back and is labelled. That is a pass
            // that did nothing measurable, and a fallback window over nothing
            // is the truth about it.
            $ceil = $stated === null ? null : self::nextSecond($stated);
            $useStated = $ceil !== null
                && ($prevAt === null || $ceil > $prevAt)
                && $ceil <= (string) $row['created_at'];

            return [
                'id' => (int) $row['id'],
                'conn' => (string) $row['conn'],
                // The window the counts are taken over, and whether the pass
                // drew it. `bounded` is what licenses the tripwire, so it is
                // the start that was USED and not the one that was sent.
                'from_at' => $useStated ? $ceil : $prevAt,
                // `from_id = 0` makes the tuple comparison `> (from_at, 0)`
                // mean "at or after that second", since no row has a
                // non-positive id. A previous run-summary is a ROW and must be
                // excluded, so it keeps its own id as the second half.
                'from_id' => $useStated ? 0 : ($row['prev_id'] === null ? null : (int) $row['prev_id']),
                'bounded' => $useStated,
                'stated_start' => $stated,
                // What the connection is called NOW where it survives, the
                // recorded string otherwise — CuratorLogEntry::writerName()'s
                // rule, applied in SQL because the digest never loads the row.
                'writer' => (string) ($row['token_display_name'] ?? $row['token_current_name'] ?? $row['token_name']),
                'conn_filter' => $tokenId === null ? 'name:'.$row['token_name'] : 'token:'.$tokenId,
                'description' => (string) $row['description'],
                'claims' => self::decodeClaims($row['claims']),
                'created_at' => (string) $row['created_at'],
                'prev_at' => $row['prev_at'] === null ? null : (string) $row['prev_at'],
                'prev_id' => $row['prev_id'] === null ? null : (int) $row['prev_id'],
            ];
        }, $rows);
    }

    /**
     * How many rows of each action fall in each run's window.
     *
     * @param list<array<string, mixed>> $runs
     *
     * @return array<int, array<string, int>> run log id => action => count
     */
    private function countsByRun(array $runs): array
    {
        [$values, $params, $types] = self::windowValues($runs);

        $sql = 'WITH w(run_id, conn, from_at, from_id, to_at, to_id) AS (VALUES '.$values.')
                SELECT w.run_id, e.action, COUNT(*) AS n
                FROM w
                JOIN curator_log e ON '.self::IN_WINDOW.'
                GROUP BY w.run_id, e.action';

        $out = [];
        foreach ($this->em->getConnection()->fetchAllAssociative($sql, $params, $types) as $row) {
            $out[(int) $row['run_id']][(string) $row['action']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * The notes each run recorded as examined.
     *
     * By `run_id`, NOT by the window — these rows are written after the
     * summary they belong to and share its second, so no window that ends at
     * the summary can contain them. That is not a subtlety anybody would
     * guess: measured against the real vault before the column existed, run
     * 656's own eighteen examined rows counted as zero while run 597's
     * nineteen were attributed to the run after it.
     *
     * **The COUNT and the ids are different numbers, and conflating them was a
     * defect.** `curator_log.note_id` is `ON DELETE SET NULL`, so a pass that
     * read a note since deleted keeps its row and loses the reference. Reading
     * the count off the ids therefore under-reported every run that had
     * touched a note which later went — measured in a browser at 16 against a
     * journal scoped to the same run showing 18, which is precisely the
     * "the digest disagrees with the rows it links to" failure this class's
     * shared window exists to prevent. The row is the reading; the id is only
     * how the defect check finds the note.
     *
     * @param list<array<string, mixed>> $runs
     *
     * `notes` is the same rows as the reader sees them: the note's number and
     * title where it survives, the title alone where it does not.
     *
     * @return array<int, array{total: int, ids: list<int>, notes: list<array{note_id: ?int, title: string}>}>
     */
    private function examinedByRun(array $runs): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT e.run_id, e.note_id, e.note_title, wn.id AS note_number
             FROM curator_log e
             LEFT JOIN notes wn ON wn.id = e.note_id
             WHERE e.action = :examined AND e.run_id IN (:runs)
             ORDER BY e.id',
            [
                'examined' => CuratorLogEntry::ACTION_EXAMINED,
                'runs' => array_column($runs, 'id'),
            ],
            ['runs' => ArrayParameterType::INTEGER],
        );

        $out = [];
        foreach ($rows as $row) {
            $runId = (int) $row['run_id'];
            $out[$runId] ??= ['total' => 0, 'ids' => [], 'notes' => []];
            ++$out[$runId]['total'];
            if ($row['note_id'] !== null) {
                $out[$runId]['ids'][] = (int) $row['note_id'];
            }
            $out[$runId]['notes'][] = [
                'note_id' => $row['note_number'] === null ? null : (int) $row['note_number'],
                'title' => (string) ($row['note_title'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * The notes each run changed — one row per note, however many edits the
     * pass made to it — over the same window the `edited` and `created`
     * counts are taken from, so the list and the numbers cannot disagree
     * about which pass a change belongs to.
     *
     * A note the pass created and then edited is `created`. A note since
     * deleted keeps its title and loses its link, as everywhere in the digest;
     * the title shown is the one its latest row recorded.
     *
     * @param list<array<string, mixed>> $runs
     *
     * @return array<int, list<array{note_id: ?int, title: string, kind: 'created'|'edited', rows: int}>>
     */
    private function writtenByRun(array $runs): array
    {
        [$values, $params, $types] = self::windowValues($runs);

        $sql = 'WITH w(run_id, conn, from_at, from_id, to_at, to_id) AS (VALUES '.$values.')
                SELECT w.run_id, e.action, e.note_id, e.retired_note_id, e.note_title, wn.id AS note_number
                FROM w
                JOIN curator_log e ON '.self::IN_WINDOW.'
                LEFT JOIN notes wn ON wn.id = e.note_id
                WHERE e.action IN (:create, :edit)
                ORDER BY e.created_at DESC, e.id DESC';

        $params += [
            'create' => CuratorLogEntry::ACTION_CREATE,
            'edit' => CuratorLogEntry::ACTION_EDIT,
        ];

        $grouped = [];
        foreach ($this->em->getConnection()->fetchAllAssociative($sql, $params, $types) as $row) {
            $runId = (int) $row['run_id'];
            // A retired note's rows keep its id in `retired_note_id` for the
            // thirty days it can come back, so a note renamed and then deleted
            // inside one pass stays one row; the title is the key only once
            // the note is purged and nothing else survives.
            $key = 'note:'.($row['note_id'] ?? $row['retired_note_id'] ?? 'title:'.$row['note_title']);
            $grouped[$runId][$key] ??= [
                'note_id' => $row['note_number'] === null ? null : (int) $row['note_number'],
                'title' => (string) ($row['note_title'] ?? ''),
                'kind' => 'edited',
                'rows' => 0,
            ];
            ++$grouped[$runId][$key]['rows'];
            if ($row['action'] === CuratorLogEntry::ACTION_CREATE) {
                $grouped[$runId][$key]['kind'] = 'created';
            }
        }

        return array_map('array_values', $grouped);
    }

    /**
     * The deletes and merges each run proposed that are still undecided.
     *
     * Built from the LOG rows rather than from `edit_proposals` alone, because
     * these are the rows the digest is summarising and the ones a click lands
     * on. "Still waiting" is then the exact question asked of the proposals
     * table: a rejected proposal is REMOVED ({@see ReviewVerdicts::rejectProposal()})
     * and an approved one is marked applied, so a surviving `held` row of the
     * same type against the same note is undecided and nothing else is.
     *
     * @param list<array<string, mixed>> $runs
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function waitingByRun(array $runs): array
    {
        [$values, $params, $types] = self::windowValues($runs);

        $sql = 'WITH w(run_id, conn, from_at, from_id, to_at, to_id) AS (VALUES '.$values.')
                SELECT w.run_id, e.id AS log_id, e.action, wn.id AS note_number, e.note_title,
                       CASE WHEN EXISTS (
                           SELECT 1 FROM edit_proposals p
                           WHERE p.note_id = e.note_id
                             AND p.status = :held
                             AND p.type = CASE e.action WHEN :del THEN :tdel ELSE :tmerge END
                       ) THEN 1 ELSE 0 END AS undecided
                FROM w
                JOIN curator_log e ON '.self::IN_WINDOW.'
                LEFT JOIN notes wn ON wn.id = e.note_id
                WHERE e.action IN (:del, :merge) AND e.note_id IS NOT NULL
                ORDER BY e.created_at DESC, e.id DESC';

        $params += [
            'held' => EditProposal::STATUS_HELD,
            'del' => CuratorLogEntry::ACTION_DELETE_PROPOSED,
            'merge' => CuratorLogEntry::ACTION_MERGE_PROPOSED,
            'tdel' => EditProposal::TYPE_DELETE,
            'tmerge' => EditProposal::TYPE_MERGE,
        ];

        $out = [];
        foreach ($this->em->getConnection()->fetchAllAssociative($sql, $params, $types) as $row) {
            if ((int) $row['undecided'] !== 1) {
                continue;
            }
            $out[(int) $row['run_id']][] = [
                'log_id' => (int) $row['log_id'],
                'kind' => $row['action'] === CuratorLogEntry::ACTION_DELETE_PROPOSED ? 'delete' : 'merge',
                'note_id' => (int) $row['note_number'],
                'title' => (string) ($row['note_title'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * The join predicate every window query shares: same connection, and
     * (created_at, id) inside the half-open interval the run covers.
     *
     * Written once and interpolated rather than repeated, because three
     * queries asking slightly different versions of "is this row in that run"
     * is how a digest comes to disagree with the rows it links to.
     */
    private const IN_WINDOW = self::CONN_EXPR.' = w.conn
                    AND (w.from_at IS NULL OR (e.created_at, e.id) > (w.from_at, w.from_id))
                    AND (e.created_at, e.id) <= (w.to_at, w.to_id)';

    /**
     * How a row names its connection, in SQL: the token where there is one,
     * the recorded string otherwise. Written once because every query that
     * scopes to a connection has to agree with `runWindows()`'s partition, and
     * a fourth spelling of it is how a run comes to hold rows its own window
     * does not.
     */
    private const CONN_EXPR = "COALESCE('t:' || e.token_id, 'n:' || e.token_name)";

    /**
     * The VALUES list of run windows, as SQL plus bound parameters.
     *
     * @param list<array<string, mixed>> $runs
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private static function windowValues(array $runs): array
    {
        $rows = [];
        $params = [];
        $types = [];
        foreach (array_values($runs) as $i => $run) {
            $rows[] = sprintf('(:rid%1$d, :conn%1$d, :from%1$d, :fid%1$d, :to%1$d, :tid%1$d)', $i);
            $params['rid'.$i] = $run['id'];
            $params['conn'.$i] = $run['conn'];
            $params['from'.$i] = $run['from_at'];
            $params['fid'.$i] = $run['from_id'];
            $params['to'.$i] = $run['created_at'];
            $params['tid'.$i] = $run['id'];
            $types['fid'.$i] = ParameterType::INTEGER;
            $types['tid'.$i] = ParameterType::INTEGER;
            $types['rid'.$i] = ParameterType::INTEGER;
        }

        return [implode(', ', $rows), $params, $types];
    }

    /**
     * The counts a run shows, from the per-action tallies.
     *
     * Every key is present even at zero: this is one run's shape, and a
     * missing key would make a screen have to decide between "none" and "not
     * counted". The screen's job of not printing a wall of zeroes is the
     * screen's — {@see \App\Service\CurationQueue::preflight()} draws the same
     * line.
     *
     * `examined` is passed in rather than read off the tallies: those rows are
     * counted by `run_id`, and any that fall inside this window belong to the
     * PREVIOUS run (they are written just after its summary, which this
     * window's lower bound sits before).
     *
     * @param array<string, int> $byAction
     *
     * @return array<string, int>
     */
    private function countsFor(array $byAction, int $examined): array
    {
        $sum = static fn (array $actions) => array_sum(array_map(
            static fn (string $a) => $byAction[$a] ?? 0,
            $actions,
        ));

        return [
            'examined' => $examined,
            'created' => $byAction[CuratorLogEntry::ACTION_CREATE] ?? 0,
            'edited' => $sum(self::CLAIMS['edited']),
            'held' => $sum(self::CLAIMS['held']),
            'proposed' => $sum(self::CLAIMS['proposed']),
            'flags_resolved' => $byAction[CuratorLogEntry::ACTION_FLAG_RESOLVED] ?? 0,
            'observations' => $byAction['observation'] ?? 0,
            'tooling_gaps' => $byAction['tooling-gap'] ?? 0,
        ];
    }

    /**
     * Where a run's own claims and the server's rows disagree.
     *
     * A key the pass did not file is not compared — that is the whole of "a
     * run that files no claims is not accused of anything", and it is why an
     * absent key and a claimed zero have to stay distinguishable all the way
     * down from the tool call.
     *
     * @param array<string, int>|null $claims
     * @param array<string, int>      $byAction
     *
     * @return list<array{claim: string, claimed: int, logged: int}>
     */
    private function mismatches(?array $claims, array $byAction): array
    {
        if ($claims === null) {
            return [];
        }

        $out = [];
        foreach (self::CLAIMS as $key => $actions) {
            if (!array_key_exists($key, $claims)) {
                continue;
            }
            $logged = array_sum(array_map(static fn (string $a) => $byAction[$a] ?? 0, $actions));
            if ($logged !== $claims[$key]) {
                $out[] = ['claim' => $key, 'claimed' => $claims[$key], 'logged' => $logged];
            }
        }

        return $out;
    }

    /**
     * The next whole second after a stored instant.
     *
     * Both sides of the comparison have lost their fractions by the time they
     * reach this class — `curator_log.created_at` and `run_started_at` are
     * both stored in whole seconds — so a start cannot be resolved against a row
     * written in the same second. This moves the boundary past that second
     * rather than into it. See {@see runWindows()} for why smaller is the
     * right direction to be wrong in.
     */
    private static function nextSecond(string $raw): string
    {
        return (new \DateTimeImmutable($raw, new \DateTimeZone('UTC')))
            ->modify('+1 second')
            ->format('Y-m-d H:i:s');
    }

    /**
     * A stored instant, as the ISO instant every other date in this API is.
     *
     * The column has no zone and the application writes UTC into it, so the
     * zone is asserted here rather than guessed — without it `new Date(…)` in
     * a browser reads the string as LOCAL time and a run logged at 02:14 UTC
     * is shown at 02:14 wherever the reader is.
     */
    private static function atom(?string $raw): ?string
    {
        return $raw === null
            ? null
            : (new \DateTimeImmutable($raw, new \DateTimeZone('UTC')))->format(DATE_ATOM);
    }

    /**
     * @return array<string, int>|null
     */
    private static function decodeClaims(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($decoded) && $decoded !== [] ? array_map('intval', $decoded) : null;
    }
}
