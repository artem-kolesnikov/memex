<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApiToken;
use App\Entity\CuratorLogEntry;
use App\Entity\EditProposal;
use App\Entity\Note;
use App\Service\CurationDigest;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Curator log (operator-directed 2026-08-06): ONE chronological list of
 * everything the curator does — date, action, description. System-written
 * rows cover applied writes and held-proposal lifecycles; curator-written
 * rows (MCP `log`) cover run summaries, questions, observations, and tooling
 * gaps — replacing journal MD notes. Rows referencing a surviving applied
 * proposal embed its diff payload so the UI can expand to exactly what
 * changed. Read-only: an editable audit is not an audit.
 *
 * **Not readable by an agent token** (codex H-5, fixed 2026-08-22) — see
 * {@see ApiController::assertSessionOrCurator()}. MCP gated this same data to
 * curator tokens from the day it existed; the REST route serving it to every
 * bearer token was the drift, and an audit an agent can read is an audit an
 * agent can learn to write around.
 */
class CuratorLogController extends ApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurationDigest $digest,
    ) {
    }

    /**
     * Rows a page may hold. The journal was capped at 200 rows with a note
     * telling the reader to narrow their filters to reach the rest, which is
     * an archive refusing to be read (operator, 2026-08-29). It pages instead;
     * the ceiling here is on ONE request, not on what is reachable.
     */
    private const PER_PAGE_MAX = 200;
    private const PER_PAGE_DEFAULT = 50;

    /**
     * Notes listed under one row before the list is cut. A tag merged across
     * ninety notes is ninety links on a row in a table, and the count travels
     * with the list so a cut is never silent.
     */
    private const AFFECTED_MAX = 100;

    #[Route('/api/curator-log', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->assertSessionOrCurator($request, 'The curator log');

        $perPage = min(self::PER_PAGE_MAX, max(1, (int) $request->query->get('per_page', self::PER_PAGE_DEFAULT)));
        $page = max(1, (int) $request->query->get('page', 1));

        try {
            [$qb, $window] = $this->filteredQuery($request);
        } catch (\RuntimeException) {
            return $this->json($this->json400('No curation run with that id'), 404);
        } catch (\InvalidArgumentException $e) {
            return $this->json($this->json400($e->getMessage()), 400);
        }

        $total = (int) (clone $qb)->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
        // What the download dialog asks: how big is this range, before you
        // commit to it. Answered without the rows and without the filter
        // menus, which are two GROUP BYs over the whole log.
        if ($request->query->get('count_only') === '1') {
            return $this->json(['total' => $total]);
        }
        // A page past the end is a stale link — a filter narrowed while page 7
        // was showing, a bookmark to a run since scoped away. It returns the
        // LAST page rather than an empty table, and says which page that was.
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        /** @var CuratorLogEntry[] $rows */
        $rows = $qb
            ->orderBy('e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $titles = $this->affectedTitles($rows);
        $owner = $this->ownerMark()->name;

        return $this->json([
            'entries' => array_map(fn (CuratorLogEntry $e) => $this->entryToArray($e, $titles, $owner), $rows),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            // What the run scope actually resolved to, so the screen can name
            // it on a removable chip rather than the user having to remember
            // which run they clicked. Absent when nothing is scoped.
            'run' => $window === null ? null : [
                'log_id' => $window['id'],
                'by' => $window['writer'],
                'at' => (new \DateTimeImmutable($window['created_at'], new \DateTimeZone('UTC')))->format(DATE_ATOM),
                'window_from' => $window['from_at'] === null ? null
                    : (new \DateTimeImmutable($window['from_at'], new \DateTimeZone('UTC')))->format(DATE_ATOM),
                'window_bounded' => $window['bounded'],
            ],
            // What the filters may offer, taken from the log itself rather
            // than from the list of actions the code can write: a menu of
            // eleven actions where nine have never happened is a menu that
            // mostly returns nothing.
            'filters' => $this->filterOptions($owner),
        ]);
    }

    /**
     * The whole journal as a file, under whatever filters are in force.
     *
     * Here because nothing is ever purged (operator, 2026-08-29): the log is
     * not only history, it is the index every "when was this note last
     * curated" query reads, so deleting old rows would make curated notes
     * report as untouched. What the operator actually needs from a long
     * journal is to be able to take it away — which is also the guarantee
     * PRODUCT.md makes about notes.
     *
     * Streamed in batches. A journal is the one table here with no natural
     * ceiling, and loading 100,000 hydrated entities to build a string is how
     * an export becomes the thing that takes the box down.
     */
    #[Route('/api/curator-log/export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $this->assertSessionOrCurator($request, 'The curator log');
        $format = $request->query->get('format') === 'csv' ? 'csv' : 'md';

        try {
            [$qb] = $this->filteredQuery($request);
        } catch (\RuntimeException) {
            return $this->json($this->json400('No curation run with that id'), 404);
        } catch (\InvalidArgumentException $e) {
            return $this->json($this->json400($e->getMessage()), 400);
        }
        $qb->orderBy('e.createdAt', 'DESC')->addOrderBy('e.id', 'DESC');

        $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $owner = $this->ownerMark()->name;
        $response = new StreamedResponse(function () use ($qb, $format, $owner): void {
            $out = fopen('php://output', 'wb');
            if ($format === 'csv') {
                fputcsv($out, ['entry', 'at', 'action', 'curation', 'by', 'description', 'operator_comment', 'precedent', 'notes'], ',', '"', '');
            } else {
                fwrite($out, "# Activity journal\n\n");
            }
            // Keyset, not OFFSET. The journal is written to while it is being
            // read — a curator pass filing rows mid-export is ordinary — and
            // every insert lands at the TOP of a `created_at DESC` ordering,
            // which shifts every later page down and hands the reader a row it
            // has already written. `(created_at, id) <` the last row emitted
            // cannot drift, whatever arrives above it (Codex, 2026-08-27).
            $cursor = null;
            do {
                $page = clone $qb;
                if ($cursor !== null) {
                    $page->andWhere('e.createdAt < :curAt OR (e.createdAt = :curAt AND e.id < :curId)')
                        ->setParameter('curAt', $cursor[0])
                        ->setParameter('curId', $cursor[1]);
                }
                /** @var CuratorLogEntry[] $batch */
                $batch = $page->setMaxResults(self::EXPORT_BATCH)->getQuery()->getResult();
                $mine = $this->ownNoteIds($batch);
                foreach ($batch as $entry) {
                    if ($format === 'csv') {
                        // Empty escape character: PHP's default backslash is
                        // not RFC 4180 and mangles a description ending in one.
                        fputcsv($out, self::exportRow($entry, $mine, $owner), ',', '"', '');
                    } else {
                        fwrite($out, self::exportMarkdown($entry, $mine, $owner));
                    }
                    $cursor = [$entry->getCreatedAt(), (int) $entry->getId()];
                }
                // Hydrated rows are not needed once written, and 100,000 of
                // them in the identity map is the memory this batching exists
                // to avoid. The cursor is two scalars, so it survives the clear.
                $this->em->clear();
            } while (count($batch) === self::EXPORT_BATCH);
            fclose($out);
        });
        $response->headers->set('Content-Type', $format === 'csv' ? 'text/csv; charset=utf-8' : 'text/markdown; charset=utf-8');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'memex-journal-'.$stamp.'.'.$format),
        );

        return $response;
    }

    private const EXPORT_BATCH = 500;

    /**
     * Every note a row touched, uncut.
     *
     * {@see self::touchedIds()} slices at AFFECTED_MAX because a table row
     * cannot carry ninety links. A FILE can, and a file that drops the
     * ninety-first without saying so is the silent truncation this endpoint
     * exists to replace (Codex, 2026-08-27).
     *
     * @return list<int>
     */
    private static function exportIds(CuratorLogEntry $entry): array
    {
        $ids = $entry->getAffectedNoteIds();
        if ($ids !== null && $ids !== []) {
            return $ids;
        }
        $note = $entry->getNote();

        return $note === null ? [] : [(int) $note->getId()];
    }

    /**
     * The same list, with the notes that no longer exist dropped — the rule
     * the screen follows, where a gone note is not offered as a link.
     *
     * @param array<int, true> $mine the ids that still name a note
     *
     * @return list<int>
     */
    private static function exportIdsIn(CuratorLogEntry $entry, array $mine): array
    {
        $out = [];
        foreach (self::exportIds($entry) as $id) {
            if (isset($mine[$id])) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * @param array<int, true> $mine
     *
     * @return list<string>
     */
    private static function exportRow(CuratorLogEntry $entry, array $mine, string $owner): array
    {
        return [
            (string) $entry->getId(),
            $entry->getCreatedAt()->format(DATE_ATOM),
            $entry->getAction(),
            $entry->getCurationRun() === null ? '' : (string) $entry->getCurationRun()->getId(),
            $entry->writerName($owner),
            $entry->getDescription(),
            (string) $entry->getOperatorComment(),
            $entry->isPrecedent() ? 'yes' : '',
            implode(' ', array_map(static fn (int $id) => '#'.$id, self::exportIdsIn($entry, $mine))),
        ];
    }

    /** @param array<int, true> $mine */
    private static function exportMarkdown(CuratorLogEntry $entry, array $mine, string $owner): string
    {
        $out = '## #'.$entry->getId().' · '.$entry->getAction().' · '.$entry->getCreatedAt()->format(DATE_ATOM)."\n\n";
        $out .= '_'.$entry->writerName($owner).'_';
        $run = $entry->getCurationRun();
        if ($run !== null) {
            $out .= ' · curation pass #'.$run->getId();
        }
        $out .= "\n\n".$entry->getDescription()."\n";
        $touched = self::exportIdsIn($entry, $mine);
        if ($touched !== []) {
            $out .= "\nNotes: ".implode(', ', array_map(static fn (int $id) => '#'.$id, $touched))."\n";
        }
        $comment = $entry->getOperatorComment();
        if ($comment !== null) {
            $out .= "\n> ".str_replace("\n", "\n> ", $comment).($entry->isPrecedent() ? ' _(precedent)_' : '')."\n";
        }

        return $out."\n";
    }

    /**
     * The filters every reader of this log shares — the list, the export and
     * the count that pages it.
     *
     * @return array{0: \Doctrine\ORM\QueryBuilder, 1: array<string, mixed>|null}
     *
     * @throws \RuntimeException when a run id names no run
     */
    private function filteredQuery(Request $request): array
    {
        $action = trim((string) $request->query->get('action', ''));
        $writer = trim((string) $request->query->get('writer', ''));
        $query = trim((string) $request->query->get('q', ''));
        $run = (int) $request->query->get('run', 0);
        $from = self::parseInstant($request->query->get('from'), 'from');
        $to = self::parseInstant($request->query->get('to'), 'to');
        if ($from !== null && $to !== null && $from > $to) {
            // Refused rather than answered with nothing. An inverted range is
            // a mistake somebody made, and a table that silently says "no
            // entries" is how they conclude the journal is empty.
            throw new \InvalidArgumentException('from is after to');
        }
        // Curation, as against everything else an agent does. The server marks
        // a row when the pass that wrote it declares itself; see
        // {@see \App\Service\CurationDigest::stampRun()}.
        $curationOnly = $request->query->get('curation') === '1';

        // Scoping to one curation RUN, which the digest links into. The window
        // is {@see CurationDigest::window()}'s and not a second idea of one: a
        // digest whose counts and whose "show me those rows" link disagreed
        // about what a run covered would be worse than having no link.
        $window = $run > 0 ? $this->digest->window($run) : null;
        if ($run > 0 && $window === null) {
            throw new \RuntimeException('no such run');
        }
        if ($window !== null) {
            // A run belongs to ONE connection, so the writer is the window's,
            // not the caller's — the same string the `writer` filter below
            // takes, so the two cannot express different things.
            $writer = $window['conn_filter'];
        }

        $qb = $this->em->createQueryBuilder()
            ->select('e')
            ->from(CuratorLogEntry::class, 'e');

        // A LIST, not one action: a count that covers several actions links
        // to exactly the rows it counted (Codex, 2026-08-26). One exact
        // action is the same call with a list of one.
        $actions = array_values(array_filter(array_map('trim', explode(',', $action))));
        if ($actions !== []) {
            $qb->andWhere('e.action IN (:actions)')->setParameter('actions', $actions);
        }
        // A writer is identified by its TOKEN, not by the name on the screen:
        // renaming a connection must not split its history in two, and two
        // connections may perfectly well be called the same thing. Rows older
        // than tokens-on-log-rows, and rows whose token died with an account,
        // keep only the string that was recorded, so those are addressed by it.
        if ($writer === 'owner') {
            $qb->andWhere('e.actor = :owner')->setParameter('owner', Note::ACTOR_HUMAN);
        } elseif (str_starts_with($writer, 'token:')) {
            $qb->andWhere('e.token = :token')->setParameter('token', (int) substr($writer, 6));
        } elseif (str_starts_with($writer, 'name:')) {
            $qb->andWhere('e.token IS NULL AND e.tokenName = :name')->setParameter('name', substr($writer, 5));
        }
        if ($curationOnly) {
            $qb->andWhere('e.curationRun IS NOT NULL');
        }
        if ($window !== null) {
            // Two disjoint sets make up a run's rows, and both halves matter.
            //
            // The rows the run WROTE are the ones inside its window, and the
            // bounds are (timestamp, id) tuples rather than timestamps because
            // `created_at` holds whole seconds: a pass files its summary in the
            // same second as its last write often enough that comparing
            // seconds alone would drop that row or count it twice. Rows
            // stamped with a DIFFERENT run are excluded from this half — the
            // previous run's `examined` rows are written just after its
            // summary and would otherwise sit inside this window.
            //
            // The rows the server wrote FOR the run are its `examined` rows,
            // which carry `run_id` and are always past the upper bound, so
            // they come in by that link instead.
            $inWindow = '(e.run IS NULL AND (e.createdAt < :toAt OR (e.createdAt = :toAt AND e.id <= :toId))';
            $qb->setParameter('toAt', new \DateTimeImmutable($window['created_at']))
                ->setParameter('toId', $window['id']);
            if ($window['from_at'] !== null) {
                $inWindow .= ' AND (e.createdAt > :fromAt OR (e.createdAt = :fromAt AND e.id > :fromId))';
                $qb->setParameter('fromAt', new \DateTimeImmutable($window['from_at']))
                    ->setParameter('fromId', $window['from_id'] ?? 0);
            }
            $qb->andWhere($inWindow.') OR e.run = :runRef')
                ->setParameter('runRef', $window['id']);
        }
        if ($from !== null) {
            $qb->andWhere('e.createdAt >= :from')->setParameter('from', $from);
        }
        if ($to !== null) {
            $qb->andWhere('e.createdAt < :to')->setParameter('to', $to);
        }
        if ($query !== '') {
            // The two things a row shows as words. `escapeLike` is ours because
            // a search for a literal underscore — 'note_id', say — otherwise
            // matches any character at all.
            $qb->andWhere("LOWER(e.description) LIKE :q ESCAPE '\\' OR LOWER(e.noteTitle) LIKE :q ESCAPE '\\'")
                ->setParameter('q', '%'.self::escapeLike(mb_strtolower($query)).'%');
        }

        return [$qb, $window];
    }

    /**
     * Every note id a row claims to have touched: the recorded list where
     * there is one, the row's own note otherwise.
     *
     * @return list<int>
     */
    private static function touchedIds(CuratorLogEntry $entry): array
    {
        $ids = $entry->getAffectedNoteIds();
        if ($ids !== null && $ids !== []) {
            return array_slice($ids, 0, self::AFFECTED_MAX);
        }
        $note = $entry->getNote();

        return $note === null ? [] : [(int) $note->getId()];
    }

    /**
     * Which of the notes this batch names still exist, in one query — the
     * export's equivalent of {@see self::affectedTitles()}, which needs the
     * ids alone because a file prints `#id` rather than a title.
     *
     * @param CuratorLogEntry[] $rows
     *
     * @return array<int, true>
     */
    private function ownNoteIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            foreach (self::exportIds($row) as $id) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $found = $this->em->getConnection()->fetchFirstColumn(
            'SELECT id FROM notes WHERE id IN (:ids)',
            ['ids' => array_keys($ids)],
            ['ids' => ArrayParameterType::INTEGER],
        );

        return array_fill_keys(array_map('intval', $found), true);
    }

    /**
     * Titles for every note named by this page, in one query.
     *
     * @param CuratorLogEntry[] $rows
     *
     * @return array<int, string> note id => title, for the notes that still exist
     */
    private function affectedTitles(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            foreach (self::touchedIds($row) as $id) {
                $ids[$id] = true;
            }
            // The row's OWN note as well, which touchedIds() leaves out
            // whenever an affected list is recorded.
            $own = $row->getNote();
            if ($own !== null) {
                $ids[(int) $own->getId()] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $found = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, title FROM notes WHERE id IN (:ids)',
            ['ids' => array_keys($ids)],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $out = [];
        foreach ($found as $row) {
            $out[(int) $row['id']] = (string) $row['title'];
        }

        return $out;
    }

    /**
     * The actions and writers this log actually contains, newest usage
     * first, each with how many rows it accounts for.
     *
     * @return array{actions: list<array{value: string, count: int}>, writers: list<array{value: string, label: string, count: int}>}
     */
    private function filterOptions(string $owner): array
    {
        $conn = $this->em->getConnection();

        /** @var list<array{action: string, n: int}> $actionRows */
        $actionRows = $conn->fetchAllAssociative(
            'SELECT action, COUNT(*) AS n FROM curator_log GROUP BY action ORDER BY n DESC, action',
        );

        /** @var list<array{token_id: ?int, token_name: string, actor: string, n: int}> $writerRows */
        $writerRows = $conn->fetchAllAssociative(
            'SELECT token_id, token_name, actor, COUNT(*) AS n FROM curator_log GROUP BY token_id, token_name, actor',
        );

        // One entry per token, whatever it has been called along the way —
        // the same rule the rows themselves follow through writerName() — and
        // one for the owner, under whichever name each row recorded.
        $writers = [];
        foreach ($writerRows as $row) {
            $tokenId = $row['token_id'] === null ? null : (int) $row['token_id'];
            $token = $tokenId === null ? null : $this->em->find(ApiToken::class, $tokenId);
            $value = match (true) {
                $row['actor'] === Note::ACTOR_HUMAN => 'owner',
                $token === null => 'name:'.$row['token_name'],
                default => 'token:'.$tokenId,
            };
            $label = $value === 'owner' ? $owner : ($token?->displayName() ?? $row['token_name']);
            $writers[$value] ??= ['value' => $value, 'label' => $label, 'count' => 0];
            $writers[$value]['count'] += (int) $row['n'];
        }
        usort($writers, static fn (array $a, array $b) => $b['count'] <=> $a['count'] ?: strcasecmp($a['label'], $b['label']));

        return [
            'actions' => array_map(
                static fn (array $r) => ['value' => $r['action'], 'count' => (int) $r['n']],
                $actionRows,
            ),
            'writers' => array_values($writers),
        ];
    }

    /**
     * A range boundary, as an instant.
     *
     * INSTANTS RATHER THAN CALENDAR DAYS, and the reason is the only reason
     * that matters here: `created_at` is UTC and the journal renders in the
     * reader's own zone, so "to 26 August" resolved server-side cut the range
     * at 26 August 00:00 UTC — which in New York is the evening of the 25th,
     * hiding rows the screen was showing as the 26th, and moving again across
     * a DST boundary (Codex, 2026-08-27). The browser knows the zone and the
     * server does not, so the browser turns the two days it was given into the
     * two instants that bound them. `from` is inclusive, `to` is exclusive —
     * the dialog sends the start of the day AFTER the one chosen, so the
     * "whole of the closing day" question is answered once, where the calendar
     * is.
     *
     * Strict, and refused rather than ignored: `new DateTimeImmutable()` also
     * accepts "yesterday" and "+3 hours" — the same laxity Codex found in
     * `started_at` — and a filter that silently does nothing is how somebody
     * downloads the wrong range and never finds out.
     */
    private static function parseInstant(?string $raw, string $field): ?\DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        // The shape is checked before the parse, not by it: `createFromFormat`
        // accepts `2026-8-27` for `Y-m-d` and would let a half-padded date
        // through a contract that says it is strict.
        $iso = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';
        if (preg_match($iso, $raw) !== 1) {
            throw new \InvalidArgumentException($field.' must be an ISO-8601 instant, e.g. "2026-08-26T00:00:00Z"');
        }
        try {
            $at = new \DateTimeImmutable($raw);
        } catch (\Exception) {
            throw new \InvalidArgumentException($field.' is not a real date and time');
        }
        // Month 13 throws; the 31st of February does NOT — PHP rolls it into
        // March and hands back a date nobody asked for. Compared before the
        // zone is applied, so an offset does not move the day out from under
        // the check.
        if ($at->format('Y-m-d\TH:i:s') !== substr($raw, 0, 19)) {
            throw new \InvalidArgumentException($field.' is not a real date and time');
        }

        return $at->setTimezone(new \DateTimeZone('UTC'));
    }

    /** LIKE wildcards in a user's search text are text, not syntax. */
    private static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * @param array<int, string> $titles
     *
     * @return array<string, mixed>
     */
    private function entryToArray(CuratorLogEntry $entry, array $titles, string $owner): array
    {
        $note = $entry->getNote();
        $noteId = $note === null ? null : (int) $note->getId();
        $proposal = $entry->getProposal();
        $touched = self::touchedIds($entry);
        // Only the notes that still exist. A row outlives what it references
        // on purpose — `note_id` is ON DELETE SET NULL and the id list is a
        // copy — so a link to a note that is gone would be a 404 offered as a
        // fact.
        $affected = [];
        foreach ($touched as $id) {
            if (isset($titles[$id])) {
                $affected[] = ['id' => $id, 'title' => $titles[$id]];
            }
        }

        return [
            'id' => $entry->getId(),
            'created_at' => $entry->getCreatedAt()->format(DATE_ATOM),
            // What the connection is called NOW, falling back to the name
            // recorded at the time for rows written before tokens were linked
            // and for a token destroyed with a closing account.
            'by' => $entry->writerName($owner),
            'actor' => $entry->getActor(),
            'action' => $entry->getAction(),
            'description' => $entry->getDescription(),
            // The operator's reasoning on a verdict, and whether they marked it
            // as generalising — shown in the activity view so the operator can
            // see what the curator will read back.
            'operator_comment' => $entry->getOperatorComment(),
            'is_precedent' => $entry->isPrecedent(),
            'note' => $noteId === null || !isset($titles[$noteId])
                ? null
                : ['id' => $noteId, 'title' => $titles[$noteId]],
            'note_title' => $entry->getNoteTitle(),
            // Every note this row touched, as links. One-note rows carry their
            // own; a tag merge or a merge verdict carries the list
            // they recorded when they ran.
            'affected' => $affected,
            // Counted over the row's whole recorded list, which is what the
            // row touched — the screen says how many of them are gone. It is
            // deliberately NOT the resolved count: a row that touched ninety
            // notes and lost eighty of them to deletion touched ninety.
            'affected_total' => count($entry->getAffectedNoteIds() ?? $touched),
            // The curation pass this row belongs to, or null for ordinary
            // agent work — the distinction the journal's Curation filter is.
            'curation_run' => $entry->getCurationRun()?->getId(),
            // Diff payload for expandable rows — present only while the
            // applied proposal row survives (its note may be deleted later),
            // and only when it has something to show.
            'diff' => $this->diffPayload($proposal),
        ];
    }

    /**
     * The pre/post pair an expanded row renders, or null when there is nothing
     * to render.
     *
     * The emptiness test is here rather than in the view because the view is
     * not the only reader and, more to the point, because the row's "(show
     * diff)" affordance and the panel below it have to agree. They did not: an
     * edit stored as an anchored patch and applied before 2026-08-23 kept a
     * null title, null tags, null summary and a null body — the patch was
     * resolved into the note and never written down — so the row promised a
     * diff and expanded to blank. {@see EditProposal::recordResolvedBody()}
     * stops new rows being written that way; this stops the ones already
     * written from offering something they cannot deliver.
     *
     * @return array<string, mixed>|null
     */
    private function diffPayload(?EditProposal $proposal): ?array
    {
        if ($proposal === null || !$proposal->isApplied()) {
            return null;
        }

        // An edit is the only type whose panel can come out empty: create,
        // delete and merge each render from the note itself.
        if (EditProposal::TYPE_EDIT === $proposal->getType()
            && $proposal->getProposedTitle() === null
            && $proposal->getProposedBodyMd() === null
            && $proposal->getProposedTags() === null
            && $proposal->getProposedSummary() === null
        ) {
            return null;
        }

        return [
            'type' => $proposal->getType(),
            'proposed_title' => $proposal->getProposedTitle(),
            'proposed_body_md' => $proposal->getProposedBodyMd(),
            'proposed_tags' => $proposal->getProposedTags(),
            'proposed_summary' => $proposal->getProposedSummary(),
            'prev_summary' => $proposal->getPrevSummary(),
            'prev_title' => $proposal->getPrevTitle(),
            'prev_body_md' => $proposal->getPrevBodyMd(),
            'prev_tags' => $proposal->getPrevTags(),
        ];
    }
}
