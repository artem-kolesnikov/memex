<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Hybrid semantic+keyword note search, ported from mm2's production query
 * handler (DoctrineArticleQueryHandler): a top-K chunk candidate CTE with a
 * cosine distance cutoff, and FTS5 keyword matching of every word. Hard
 * facets (status, source, tag) stay exact WHERE clauses in every mode.
 *
 * **The notes holding every word lead, and meaning joins them only from
 * nearer.** A note that lacks the words is listed when it is nearer in
 * meaning than every note that has them, and then ahead of them; when no
 * note has them, the vector tier answers alone. A knowledge base has
 * exact-recall expectations a news feed doesn't: measured 2026-08-18 on the
 * live vault, searching a rare proper noun ("webdesk") put ~20 unrelated
 * notes inside the cutoff — for a token the embedding model has no concept
 * of, every note is equidistant noise, and blending buried six true matches
 * among fifteen strangers. Its own notes are nearer than the noise, so the
 * rule keeps them on top, while "health insurance" still finds the medical
 * note that never says "health". The cutoff is the vault model's
 * ({@see EmbeddingModel::searchDistance()}).
 *
 * Keywords match in `notes_terms`, which reads every script
 * ({@see SearchTerms}); `notes_fts` holds the note's own text, for the
 * passage that shows a match.
 */
class HybridSearch
{
    /**
     * Chunks, not notes: a long note has one vector per section and its best
     * one is what ranks it, so the k-NN is asked for this many chunk rows and
     * the nearest chunk of each note is what the distance cutoff then judges.
     */
    private const SEMANTIC_CHUNK_CANDIDATES = 600;
    /** How many words of a body a passage around a keyword match shows, at most. */
    private const PASSAGE_TOKENS = 32;
    /**
     * Result orderings a caller may ask for. `relevance` is the ranking this
     * class exists for; the rest are plain column orders, needed because
     * ranking answers "what matches" and curation asks "what have I not looked
     * at in the longest time" — a question no relevance score can express.
     */
    public const ORDER_RELEVANCE = 'relevance';
    public const ORDER_UPDATED_DESC = 'updated_desc';
    public const ORDER_UPDATED_ASC = 'updated_asc';
    public const ORDER_CREATED_DESC = 'created_desc';
    public const ORDER_CREATED_ASC = 'created_asc';
    public const ORDERS = [
        self::ORDER_RELEVANCE,
        self::ORDER_UPDATED_DESC,
        self::ORDER_UPDATED_ASC,
        self::ORDER_CREATED_DESC,
        self::ORDER_CREATED_ASC,
    ];


    /**
     * "This note carries an open operator flag" — one definition, used as both
     * a facet and a returned column so the filter and the marker can never
     * disagree about what flagged means.
     */
    private const OPEN_FLAG_EXISTS = 'EXISTS (SELECT 1 FROM curation_flags cf
             WHERE cf.note_id = n.id AND cf.resolved_at IS NULL)';

    /**
     * The notes the keyword expression matches, with their BM25 score over
     * title and body as one document. FTS5 scores lower-is-better.
     */
    private const KEYWORD_HITS = 'SELECT rowid AS note_id, bm25(notes_terms) AS rank FROM notes_terms WHERE notes_terms MATCH :keyword_query';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MlClient $mlClient,
        private readonly SpendLimiter $spendLimiter,
        private readonly Owner $owner,
        private readonly EmbeddingSpace $space,
    ) {
    }

    /**
     * @param int[]       $tagIds
     * @param string|null $order  One of self::ORDERS; null = the historical
     *                            default (relevance when a query is embedded,
     *                            most-recently-updated otherwise)
     * @param int|null    $offset Row offset, overriding page-based paging when
     *                            given — callers that walk a result set
     *                            (agents) think in offsets, the UI in pages
     * @param bool        $keywordOnly The words as typed, ANDed, no operators and no
     *                            semantic tier — the canary proving a title finds
     *                            itself, never a person's search
     * @param bool        $flaggedOnly Only notes carrying an open operator flag
     * @param bool        $undescribedOnly Only notes with no summary — the enrichment backlog, as the website sees it
     *                            — the operator's own "what have I asked for"
     *                            listing, and a hard facet like the others
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, semantic_unavailable: bool, keyword_only: bool}
     */
    public function search(
        ?string $query,
        array $tagIds,
        ?string $source,
        ?string $status,
        int $page,
        int $perPage,
        ?string $order = null,
        ?int $offset = null,
        bool $flaggedOnly = false,
        bool $undescribedOnly = false,
        ?int $addedByTokenId = null,
        bool $keywordOnly = false,
        bool $withSkills = true,
        ?DateWindow $dates = null,
        bool $excerpts = false,
        ?PropertyFilter $properties = null,
    ): array {
        if ($order !== null && !in_array($order, self::ORDERS, true)) {
            throw new \InvalidArgumentException('order must be one of: '.implode(', ', self::ORDERS));
        }

        $scope = $this->scope($query, $tagIds, $source, $status, $flaggedOnly, $undescribedOnly, $addedByTokenId, $keywordOnly, $withSkills, $dates, $properties);
        if ($scope['empty']) {
            return ['items' => [], 'total' => 0, 'semantic_unavailable' => false, 'keyword_only' => $scope['exact']];
        }
        [
            'where' => $where, 'params' => $params, 'types' => $types, 'cte' => $cte, 'join' => $join,
            'section' => $section, 'relevance' => $relevance,
            'semantic_unavailable' => $semanticUnavailable, 'exact' => $exact,
        ] = $scope;
        $conn = $this->em->getConnection();

        $total = (int) $conn->executeQuery($cte.'SELECT COUNT(n.id) AS total FROM notes n'.$join.' '.$where, $params, $types)->fetchOne();

        // `flagged` rides along on every listing rather than being a second
        // query the UI has to remember to make: a note the operator has
        // complained about should look different everywhere it appears, and a
        // per-row lookup for that would be one query per result.
        // Which section of a long note the query landed in, so a reader of a
        // 60,000-character note knows where to look. Only a meaning-based hit
        // has one; a keyword hit matched the note as a whole.
        $matched = $section.' AS matched_section, '.$scope['hit'].' AS keyword_hit,';
        $sql = $cte.<<<SQL
        SELECT
            n.id, n.title, n.source, n.source_url, n.status, n.summary, n.properties,
            n.last_actor, n.created_at, n.updated_at, n.version, {$matched}
            -- WHICH assistant wrote last, joined rather than copied onto the
            -- note: renaming a connection renames it everywhere, which is the
            -- operator's ruling (2026-08-23) and only holds if there is one
            -- place the name lives. Null on a person's own edit, which is the
            -- owner's (see Note::attribute()).
            t.display_name AS agent_display_name,
            t.name AS agent_name,
            t.icon_key AS agent_icon_key,
            t.id AS agent_token_id,
            (t.icon_blob IS NOT NULL) AS agent_has_upload,

        SQL.self::OPEN_FLAG_EXISTS.<<<'SQL'
         AS flagged
        FROM notes n
        LEFT JOIN api_tokens t ON t.id = n.last_actor_token_id
        SQL;
        $sql .= $join.' '.$where;

        $sql .= ' '.$this->orderBy($order, $relevance);

        $sql .= ' LIMIT :limit OFFSET :offset';
        $params['limit'] = $perPage;
        $params['offset'] = max(0, $offset ?? (($page - 1) * $perPage));
        $types['limit'] = ParameterType::INTEGER;
        $types['offset'] = ParameterType::INTEGER;

        $rows = $conn->executeQuery($sql, $params, $types)->fetchAllAssociative();

        if ($rows !== []) {
            $ids = array_map('intval', array_column($rows, 'id'));
            $tagRows = $conn->executeQuery(
                'SELECT nt.note_id, t.id, t.name FROM note_tag nt JOIN tags t ON t.id = nt.tag_id
                 WHERE nt.note_id IN (:ids) ORDER BY '.SystemTags::sqlRank('t.name').', t.name',
                ['ids' => $ids],
                ['ids' => ArrayParameterType::INTEGER]
            )->fetchAllAssociative();
            $tagsByNote = [];
            foreach ($tagRows as $t) {
                $tagsByNote[(int) $t['note_id']][] = ['id' => (int) $t['id'], 'name' => $t['name']];
            }
            $owner = $this->owner->mark();
            foreach ($rows as &$row) {
                $row['id'] = (int) $row['id'];
                $row['tags'] = $tagsByNote[$row['id']] ?? [];
                $row['properties'] = NoteProperties::json(\is_string($row['properties']) ? json_decode($row['properties'], true, 512, JSON_THROW_ON_ERROR) : null);
                $row['flagged'] = (bool) $row['flagged'];
                // ONE producer for this shape, shared with the ORM path in
                // ApiController::noteToArray(). Two serialisers for one row is
                // how the note page came to show a token's registered name
                // while the list showed the name the operator had chosen.
                $row['edited_by'] = ActorView::fromRow($row, 'agent_', $owner, is_string($row['last_actor'] ?? null) ? $row['last_actor'] : null);
                // The joined columns did their job; the payload is `edited_by`.
                unset(
                    $row['agent_display_name'], $row['agent_name'], $row['agent_icon_key'],
                    $row['agent_token_id'], $row['agent_has_upload'],
                );
            }
            unset($row);
            $hits = array_column(array_filter($rows, static fn (array $r): bool => (bool) $r['keyword_hit']), 'id');
            foreach ($rows as &$row) {
                unset($row['keyword_hit']);
            }
            unset($row);
            if ($excerpts && $scope['matched'] !== null && $hits !== []) {
                $passages = $this->passages($conn, $scope['matched'], $hits);
                foreach ($rows as &$row) {
                    if (isset($passages[$row['id']])) {
                        $row['matched_text'] = $passages[$row['id']];
                    }
                }
                unset($row);
            }
        }

        return ['items' => $rows, 'total' => $total, 'semantic_unavailable' => $semanticUnavailable, 'keyword_only' => $exact];
    }

    /**
     * Every note a search matches, as note numbers and nothing else —
     * unpaged, because the notes page's map dims whatever its list would
     * leave out, and a page of the answer would dim the rest of it.
     *
     * @param int[] $tagIds
     * @return array{ids: list<int>, semantic_unavailable: bool, keyword_only: bool}
     */
    public function matchingNumbers(
        ?string $query,
        array $tagIds,
        ?string $source,
        ?string $status,
        bool $flaggedOnly = false,
        bool $undescribedOnly = false,
        ?int $addedByTokenId = null,
        bool $withSkills = true,
        ?DateWindow $dates = null,
    ): array {
        $scope = $this->scope($query, $tagIds, $source, $status, $flaggedOnly, $undescribedOnly, $addedByTokenId, false, $withSkills, $dates);
        if ($scope['empty']) {
            return ['ids' => [], 'semantic_unavailable' => false, 'keyword_only' => $scope['exact']];
        }

        $numbers = $this->em->getConnection()->executeQuery(
            $scope['cte'].'SELECT n.id FROM notes n'.$scope['join'].' '.$scope['where'].' ORDER BY n.id',
            $scope['params'],
            $scope['types'],
        )->fetchFirstColumn();

        return [
            'ids' => array_map('intval', $numbers),
            'semantic_unavailable' => $scope['semantic_unavailable'],
            'keyword_only' => $scope['exact'],
        ];
    }

    /**
     * The WHERE clause one search stands for, with the keyword or semantic
     * candidates it joins. Shared by {@see search()} and
     * {@see matchingNumbers()}, so a list and the map drawn beside it cannot
     * disagree about which notes a search matches.
     *
     * `empty` is an answer, not a failure: the query can match nothing, and the
     * caller returns that without running a query of its own. `relevance` is
     * the ORDER BY a relevance ordering means, or null when no tier ranks.
     *
     * @param int[] $tagIds
     * @return array{empty: true, exact: bool}|array{empty: false, where: string, params: array<string, mixed>, types: array<string, mixed>, cte: string, join: string, section: string, relevance: ?string, semantic_unavailable: bool, exact: bool, matched: ?string, hit: string}
     */
    private function scope(
        ?string $query,
        array $tagIds,
        ?string $source,
        ?string $status,
        bool $flaggedOnly,
        bool $undescribedOnly,
        ?int $addedByTokenId,
        bool $keywordOnly,
        bool $withSkills,
        ?DateWindow $dates = null,
        ?PropertyFilter $properties = null,
    ): array {
        // A query with no letters/digits ("*", "?", punctuation) can't match
        // anything meaningfully — but it WOULD get embedded, and its vector is
        // near nothing, so the distance cutoff would hide every note. Agents
        // try wildcards for "list all"; give them the listing.
        if ($query !== null && !preg_match('/[\p{L}\p{N}]/u', $query)) {
            $query = null;
        }

        $conn = $this->em->getConnection();

        $where = [];
        $params = [];
        $types = [];

        if ($status !== null) {
            $where[] = 'n.status = :status';
            $params['status'] = $status;
        }
        if ($source !== null) {
            $where[] = 'n.source = :source';
            $params['source'] = $source;
        }
        if ($flaggedOnly) {
            $where[] = self::OPEN_FLAG_EXISTS;
        }
        // Provenance: which connection ADDED these notes.
        //
        // Read off `notes.created_by_token_id`, and that column rather than the
        // Curator log for a reason worth stating, because the log looks like it
        // would do. The log records a `create` row only when the writing token
        // is a CURATOR (NoteWriter::create) — an agent-role token's notes land
        // pending and write no row at all. So the log answers this question for
        // one role and silently omits the other, while the column is written
        // for every note by every writer and survives a note's trip through
        // limbo (NoteLimbo::RETIRED_COLUMNS).
        //
        // That completeness is the whole point here. This exists as the
        // recovery lever for a leaked token — "what did this connection put in
        // my knowledge base" — and a lever that covers one role is worse than
        // none, because it answers confidently and short.
        if ($addedByTokenId !== null) {
            $where[] = 'n.created_by_token_id = :added_by';
            $params['added_by'] = $addedByTokenId;
            $types['added_by'] = ParameterType::INTEGER;
        }
        if ($undescribedOnly) {
            // Literally the same predicate `needs_enrichment` uses, shared
            // rather than repeated: the operator and their assistant have to be
            // looking at one backlog.
            $where[] = '('.CurationQueue::NO_SUMMARY_SQL.')';
        }
        if (!$withSkills) {
            $where[] = SystemTags::notSkillSql('n');
        }
        if ($dates !== null) {
            ['conditions' => $conditions, 'params' => $dateParams] = $dates->sql();
            $where = [...$where, ...$conditions];
            $params = [...$params, ...$dateParams];
        }
        if ($properties !== null) {
            ['conditions' => $conditions, 'params' => $propertyParams] = $properties->sql();
            $where = [...$where, ...$conditions];
            $params = [...$params, ...$propertyParams];
        }
        foreach (array_values($tagIds) as $i => $tagId) {
            // One EXISTS per tag: notes must carry ALL requested tags.
            $where[] = "EXISTS (SELECT 1 FROM note_tag nt{$i} WHERE nt{$i}.note_id = n.id AND nt{$i}.tag_id = :tag{$i})";
            $params["tag{$i}"] = $tagId;
            $types["tag{$i}"] = ParameterType::INTEGER;
        }

        $cte = '';
        $join = '';
        $section = 'NULL';
        $relevance = null;
        /**
         * A semantic search was WANTED and did not happen — over budget, or
         * ml-processor is down. Not the same as "no semantic tier was needed":
         * an expression with operators never wants one and leaves this false.
         *
         * Reported because the fallback returns the literal matches alone, or
         * an honest EMPTY set when there are none (see the else below), and an
         * empty answer that cannot say why is a search claiming the knowledge
         * base holds nothing on the subject.
         */
        $semanticUnavailable = false;
        // The query carried OR, NOT or a phrase (KeywordQuery): it is an
        // expression over the literal text and has no meaning to embed, so a
        // miss is the whole answer rather than a reason to search by meaning.
        $exact = false;
        // The words a keyword hit matched, for the passage that shows it.
        // Null when no keyword tier answered, or when it matched by absence.
        $matched = null;
        // Which rows hold the words, as SQL: the ones a passage is looked for in.
        $hit = '0';
        if ($query !== null && trim($query) !== '') {
            [
                'match' => $keywordQuery, 'negated' => $negated, 'exact' => $exact, 'words' => $words, 'passage' => $passage,
            ] = $keywordOnly ? KeywordQuery::literal($query) : KeywordQuery::parse($query);
            if ($keywordQuery === '' && !$words) {
                // Operators with no words between them (`AND`, `NOT OR`):
                // nothing to match and nothing to embed. Returned empty here
                // rather than falling through, where a missing keyword clause
                // on a degraded semantic tier would be the unfiltered listing.
                return ['empty' => true, 'exact' => $exact];
            }
            if ($keywordQuery !== '') {
                $params['keyword_query'] = $keywordQuery;
            }
            $literalOnly = function () use (&$join, &$where, &$relevance, &$matched, &$hit, $negated, $passage): void {
                if ($negated) {
                    // Matches by what a note lacks, and has nothing to score:
                    // its rows rank by recency.
                    $where[] = 'n.id NOT IN (SELECT rowid FROM notes_terms WHERE notes_terms MATCH :keyword_query)';
                    $relevance = 'n.updated_at DESC, n.id DESC';

                    return;
                }
                $join = ' JOIN ('.self::KEYWORD_HITS.') kw ON kw.note_id = n.id';
                $relevance = 'kw.rank ASC, n.updated_at DESC, n.id DESC';
                $matched = $passage === '' ? null : $passage;
                $hit = '1';
            };

            if ($keywordOnly || $exact) {
                // No embedding call at all in this mode: an expression has no
                // meaning to rank by, and query embedding is per-search spend.
                if ($keywordQuery === '') {
                    return ['empty' => true, 'exact' => $exact];
                }
                $literalOnly();
            } else {
                // Query embedding is not gated by the vault's AI switch: the
                // search box has nobody to delegate to, and embeddings run on
                // the operator's key for everyone.
                //
                // It IS bounded per account (AUDIT3, 2026-08-25). This is the
                // one limiter in the app that degrades instead of refusing,
                // and the reasoning is in SpendLimiter::allowSearchEmbedding():
                // search is the read path, so throwing here would let one
                // agent loop take the operator's own search box away. Over
                // budget therefore produces exactly the state an ml-processor
                // outage produces — no vector — and falls through the same
                // branch below, which is already covered by MlOutageTest.
                //
                // Asked at the line that buys, not at the three controllers
                // that call search(), for the reason SpendLimiter and
                // CLAUDE.md §Spend both give: a limit applied where the money
                // leaves cannot be forgotten by the next caller. ExportController
                // reaches this same line with a query and would have been the
                // one that was missed.
                $withinBudget = $this->spendLimiter->allowSearchEmbedding();
                $queryVector = $withinBudget ? $this->mlClient->embedQuery(trim($query)) : null;
                // Read off the OUTCOME, not off the budget: a vector that never
                // arrived because ml-processor is down leaves the caller in
                // exactly the same position, and telling them so is the same
                // sentence.
                $semanticUnavailable = $queryVector === null;
                if ($queryVector === null) {
                    if ($keywordQuery === '') {
                        // Nothing but stop words, and no meaning to search by:
                        // an honest empty set rather than the unfiltered listing.
                        $where[] = '0';
                    } else {
                        $literalOnly();
                    }
                } else {
                    // Exact k-NN over the vault's chunks, then each note's
                    // nearest chunk, which is the one the cutoff judges.
                    $cte = 'WITH chunk_hits AS ('
                        .'SELECT rowid AS chunk_id, distance FROM note_embedding_chunk_vectors '
                        .'WHERE embedding MATCH :query_vector AND k = '.self::SEMANTIC_CHUNK_CANDIDATES.'), '
                        .'chunk_candidates AS ('
                        .'SELECT c.note_id, c.heading, h.distance AS dist, '
                        .'ROW_NUMBER() OVER (PARTITION BY c.note_id ORDER BY h.distance ASC, c.chunk_index ASC) AS nearest '
                        .'FROM chunk_hits h JOIN note_embedding_chunks c ON c.id = h.chunk_id), '
                        .'semantic_candidates AS ('
                        .'SELECT note_id, heading, dist FROM chunk_candidates WHERE nearest = 1) ';
                    $params['query_vector'] = pack('g*', ...$queryVector);
                    $types['query_vector'] = ParameterType::BINARY;
                    $cutoff = 'sem.dist < '.$this->space->model()->searchDistance();
                    if ($keywordQuery === '') {
                        $join = ' JOIN semantic_candidates sem ON sem.note_id = n.id';
                        $where[] = $cutoff;
                        $section = 'sem.heading';
                        $relevance = 'sem.dist ASC, n.id DESC';
                    } else {
                        // The notes holding every word are the answer, and a
                        // note without them joins only when it is nearer in
                        // meaning than every one of them. Measured 2026-10-17
                        // on the maintainer's vault (115 notes, 32 judged
                        // queries): "health insurance" had six notes that
                        // happen to say both words and hid the medical note
                        // that is nearest by far; blending both lists ranked
                        // it seventh, and admitting every note inside the
                        // cutoff put twenty-eight strangers after "webdesk"'s
                        // nine true matches. A rare name's own note is nearer
                        // than the strangers, so nothing passes it.
                        //
                        // Among the notes holding the words, the keyword and
                        // meaning ranks are fused (reciprocal rank, k = 60),
                        // so the note a query is about passes the one that
                        // mentions it in passing, and a tie goes to the keyword
                        // score, the order before this. A note with no vector yet
                        // stands at distance 0: with nothing to compare, the
                        // literal match wins, as it did before.
                        $cte .= ', kw AS ('.self::KEYWORD_HITS.'), '
                            .'literal AS (SELECT n.id AS note_id, kw.rank, '
                            .'CASE WHEN sem.dist IS NULL AND NOT EXISTS (SELECT 1 FROM note_embedding_chunks ec WHERE ec.note_id = n.id) THEN 0 ELSE sem.dist END AS bound, '
                            .'1.0 / (60 + ROW_NUMBER() OVER (ORDER BY kw.rank, n.id)) '
                            .'+ CASE WHEN sem.dist IS NULL THEN 0 ELSE 1.0 / (60 + ROW_NUMBER() OVER (ORDER BY sem.dist IS NULL, sem.dist, n.id)) END AS fused '
                            .'FROM notes n JOIN kw ON kw.note_id = n.id LEFT JOIN semantic_candidates sem ON sem.note_id = n.id '
                            .self::where($where).') ';
                        $join = ' LEFT JOIN literal lit ON lit.note_id = n.id LEFT JOIN semantic_candidates sem ON sem.note_id = n.id';
                        $where[] = '(lit.note_id IS NOT NULL OR ('.$cutoff.' AND sem.dist < (SELECT COALESCE(MIN(bound), 2) FROM literal)))';
                        $section = 'CASE WHEN lit.note_id IS NULL THEN sem.heading END';
                        $relevance = 'lit.note_id IS NOT NULL, CASE WHEN lit.note_id IS NULL THEN sem.dist END, lit.fused DESC, lit.rank ASC, n.updated_at DESC, n.id DESC';
                        $matched = $passage === '' ? null : $passage;
                        $hit = 'lit.note_id IS NOT NULL';
                    }
                }
            }
        }

        return [
            'empty' => false,
            'where' => self::where($where),
            'params' => $params,
            'types' => $types,
            'cte' => $cte,
            'join' => $join,
            'section' => $section,
            'relevance' => $relevance,
            'semantic_unavailable' => $semanticUnavailable,
            'exact' => $exact,
            'matched' => $matched,
            'hit' => $hit,
        ];
    }

    /**
     * The ORDER BY clause for one search.
     *
     * Every branch ends in an `n.id` tiebreak in the SAME direction as its
     * leading column: rows sharing a timestamp would otherwise come back in
     * whatever order the planner liked that call, and an offset walk over an
     * unstable sort silently skips and repeats notes — the exact failure a
     * coverage guarantee cannot survive.
     *
     * `$order` only replaces the sort, never the WHERE: an explicit column
     * order alongside a query still searches, so "the oldest notes matching X"
     * is expressible.
     */
    private function orderBy(?string $order, ?string $relevance): string
    {
        $order ??= $relevance !== null ? self::ORDER_RELEVANCE : self::ORDER_UPDATED_DESC;
        // Relevance with neither tier has no score to rank by (no query, or the
        // embedding call failed on a query nothing matches literally) — fall
        // back rather than error.
        if ($order === self::ORDER_RELEVANCE && $relevance === null) {
            $order = self::ORDER_UPDATED_DESC;
        }

        return match ($order) {
            self::ORDER_RELEVANCE => 'ORDER BY '.$relevance,
            self::ORDER_UPDATED_ASC => 'ORDER BY n.updated_at ASC, n.id ASC',
            self::ORDER_CREATED_DESC => 'ORDER BY n.created_at DESC, n.id DESC',
            self::ORDER_CREATED_ASC => 'ORDER BY n.created_at ASC, n.id ASC',
            default => 'ORDER BY n.updated_at DESC, n.id DESC',
        };
    }

    /**
     * The passage of each note's body around the words a keyword search
     * matched, so a reader can often answer from the result alone. A note
     * whose body has no match, because only its title did, gets none.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function passages(Connection $conn, string $words, array $ids): array
    {
        // Control characters as the marks, because no note holds them, so a
        // passage carrying one is a passage FTS5 found a match in.
        $rows = $conn->executeQuery(
            "SELECT rowid AS id, snippet(notes_fts, 1, char(2), char(3), '…', ".self::PASSAGE_TOKENS.") AS passage
             FROM notes_fts WHERE notes_fts MATCH :words AND rowid IN (:ids)",
            ['words' => $words, 'ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        )->fetchAllAssociative();

        $passages = [];
        foreach ($rows as $row) {
            $passage = (string) $row['passage'];
            if (!str_contains($passage, "\x02")) {
                continue;
            }
            $passages[(int) $row['id']] = trim(str_replace(["\x02", "\x03"], '', $passage));
        }

        return $passages;
    }

    /** @param list<string> $conditions */
    private static function where(array $conditions): string
    {
        return $conditions === [] ? '' : 'WHERE '.implode(' AND ', $conditions);
    }
}
