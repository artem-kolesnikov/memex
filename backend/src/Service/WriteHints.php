<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Note;
use App\Entity\Tag;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Prevention, delivered where the content is already in hand.
 *
 * Curation's fourth principle: *the cheapest curation is the
 * one never needed.* Every defect the curation queue finds was preventable at
 * the moment the note was written — the duplicate had a twin already in the
 * vault, the disconnected note mentioned three notes it did not link, the
 * untagged one used words the vocabulary already had. Finding those afterwards
 * costs a task, a review and the operator's attention. Saying so at write time
 * costs a query.
 *
 * **Nothing here calls a provider, and that is the design rather than a happy
 * accident.** All three hints are computed from rows memex already has:
 *
 *  - duplicates from a vector that already exists (see {@see forNote()});
 *  - links from string matching against the vault's own titles;
 *  - tags from string matching against the vault's own vocabulary.
 *
 * So they work for a vault that has configured no AI at all, they cannot fail
 * an outage, and they add nothing to anybody's bill. A hint that cost money
 * would be a hint people switch off, and a prevention nobody runs prevents
 * nothing.
 *
 * **The server never acts on any of this.** It reports; the agent or the
 * person decides. That is the same line curation's first principle draws
 * — the server plans, verifies and detects, the model judges.
 *
 * Reporting is not neutral for two words. {@see SystemTags} names the only tags
 * the server itself branches on, and they are excluded from the suggestions
 * below: a hint is a thing an agent may act on, a curator-role connection acts
 * without review, and putting one of those two on a note changes what every
 * connected assistant is served.
 *
 * ## Empty sections are absent, not empty
 *
 * A hint with nothing to say is omitted entirely rather than returned as an
 * empty list. Every MCP write result carries this, on every call, for every
 * user; three always-present empty arrays would be a permanent tax on the
 * context window of every connected assistant for the case where there is
 * nothing to report — which is the common case. Curation's seventh principle
 * is about exactly this kind of creep.
 */
class WriteHints
{
    /** How many neighbours to name. A hint is a prompt to look, not a report. */
    private const NEIGHBOURS = 5;

    /**
     * How far past the handful shown the count goes.
     *
     * `HybridSearch` learned this on the live vault (2026-08-08): showing five
     * with no total reads as "these are all of them", and at its 0.40 cutoff
     * that was wrong for 99 notes out of 146. At 0.20 it will be wrong far
     * less often, which is a reason to keep the count cheap, not a reason to
     * drop it — the failure is silent either way.
     */
    private const COUNT_CAP = 25;

    /**
     * Titles considered as link candidates. Well past any real vault (the
     * operator's is ~290) and a bound on the one query here that grows with
     * the knowledge base rather than with the note.
     */
    private const MAX_TITLES = 5000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WikiLinkSuggest $wikiLinks,
        private readonly EmbeddingSpace $space,
    ) {
    }

    /**
     * Hints for a note that has just been saved.
     *
     * **This is the free path, and the reason is worth stating**: a save has
     * already embedded the note ({@see NoteEnricher::storeEmbedding}, in the
     * same request), so the duplicate check reads the vector out of
     * `note_embedding_vectors` instead of buying a second one. The same
     * question asked before the save — {@see forDraft()} — has to pay for an
     * embedding, because there is no note yet to have a vector.
     *
     * A note with no stored vector (the embedding failed, or an import created
     * it with `enrich: null`) simply has no `duplicates` section. The other
     * two hints do not depend on it and are unaffected.
     *
     * @return array<string, mixed> Only the sections that have something to say
     */
    public function forNote(Note $note): array
    {
        return $this->assemble(
            $this->neighboursOfNote((int) $note->getId()),
            $note->getTitle(),
            $note->getBodyMd(),
            array_map(static fn (Tag $t): string => $t->getName(), $note->getTags()->toArray()),
        );
    }

    /**
     * Hints for text that is not a note yet — the pre-save check in the editor.
     *
     * The one caller that pays: `$vector` is an embedding of the draft, which
     * somebody had to buy. It is passed in rather than fetched here so this
     * class stays free of provider calls and the spend stays visible at the
     * call site, next to the limiter that bounds it. Null (an outage, or no
     * embedding asked for) drops the duplicate section and keeps the other two.
     *
     * @param float[]|null $vector    The draft's embedding in the vault's model, or null
     * @param int|null     $excludeId The note being edited, so it is not its own duplicate
     * @param string[]     $tagNames  Tags the draft already carries
     *
     * @return array<string, mixed>
     */
    public function forDraft(
        string $title,
        string $bodyMd,
        ?array $vector,
        ?int $excludeId = null,
        array $tagNames = [],
    ): array {
        return $this->assemble(
            $vector === null ? ['items' => [], 'total' => 0] : $this->neighboursOfVector($vector, $excludeId),
            $title,
            $bodyMd,
            $tagNames,
        );
    }

    /**
     * @param array{items: array<int, array<string, mixed>>, total: int} $duplicates
     * @param string[]                                                  $ownTags
     *
     * @return array<string, mixed>
     */
    private function assemble(
        array $duplicates,
        string $title,
        string $bodyMd,
        array $ownTags,
    ): array {
        $hints = [];
        if ($duplicates['items'] !== []) {
            $hints['duplicates'] = $duplicates['items'];
            // Only when it would otherwise mislead: five shown out of five is
            // not a truncation and does not need saying.
            if ($duplicates['total'] > count($duplicates['items'])) {
                $hints['duplicates_total'] = $duplicates['total'];
            }
        }
        $links = $this->linkCandidates($title, $bodyMd);
        if ($links !== []) {
            $hints['links'] = $links;
        }
        $tags = $this->tagCandidates($title, $bodyMd, $ownTags);
        if ($tags !== []) {
            $hints['tags'] = $tags;
        }

        return $hints;
    }

    /**
     * Nearest neighbours of a note that already has a vector — no provider
     * call, because the vector is a row.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    private function neighboursOfNote(int $noteId): array
    {
        $vector = $this->em->getConnection()->fetchOne(
            'SELECT embedding FROM note_embedding_vectors WHERE rowid = :note',
            ['note' => $noteId],
            ['note' => ParameterType::INTEGER]
        );
        if (!is_string($vector)) {
            return ['items' => [], 'total' => 0];
        }

        return $this->nearest($vector, ParameterType::BINARY, $noteId);
    }

    /**
     * Nearest neighbours of a vector somebody bought — the draft path.
     *
     * @param float[] $vector
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    private function neighboursOfVector(array $vector, ?int $excludeId): array
    {
        return $this->nearest('['.implode(',', $vector).']', ParameterType::STRING, $excludeId);
    }

    /** @return array{items: array<int, array<string, mixed>>, total: int} */
    private function nearest(string $vector, ParameterType $vectorType, ?int $excludeId): array
    {
        $params = ['vector' => $vector, 'k' => self::COUNT_CAP + 1, 'limit' => self::COUNT_CAP];
        $types = ['vector' => $vectorType, 'k' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER];
        $exclude = '';
        if ($excludeId !== null) {
            $exclude = ' AND knn.rowid <> :exclude';
            $params['exclude'] = $excludeId;
            $types['exclude'] = ParameterType::INTEGER;
        }

        return $this->shape($this->em->getConnection()->fetchAllAssociative(
            'SELECT n.id, n.title, n.status, n.summary, knn.distance AS dist
             FROM (SELECT rowid, distance FROM note_embedding_vectors WHERE embedding MATCH :vector AND k = :k) knn
             JOIN notes n ON n.id = knn.rowid
             WHERE knn.distance < '.$this->space->model()->hintDistance().$exclude.'
             ORDER BY knn.distance
             LIMIT :limit',
            $params,
            $types
        ));
    }

    /**
     * One neighbour, as both an agent and the note form read it.
     *
     * `distance` and `similarity` are the same number twice, deliberately:
     * the curation verbs speak distance and the note form has always shown
     * similarity, and computing it in two places is how the operator and their
     * curator end up reading different scales for one fact.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    private function shape(array $rows): array
    {
        $duplicate = $this->space->model()->duplicateDistance();
        $items = array_map(static function (array $r) use ($duplicate): array {
            $distance = (float) $r['dist'];

            return [
                'note_id' => (int) $r['id'],
                'title' => (string) $r['title'],
                'status' => (string) $r['status'],
                'summary' => $r['summary'] !== null ? (string) $r['summary'] : null,
                'distance' => round($distance, 3),
                'similarity' => round(1 - $distance, 3),
                // Said in words, because a caller that has to interpret 0.14
                // for itself will interpret it differently every time.
                'reading' => $distance <= $duplicate
                    ? 'likely the same note — read both before saving a second one'
                    : 'related but probably distinct — a link is usually right, a merge usually is not',
            ];
        }, array_slice($rows, 0, self::NEIGHBOURS));

        return ['items' => $items, 'total' => count($rows)];
    }

    /**
     * Notes this text mentions by name and does not link.
     *
     * {@see WikiLinkSuggest} does the work and its refusals — inside code,
     * inside an existing link, inside a URL, part of a longer word — are what
     * make the answer worth showing rather than a list of coincidences. The
     * anchored patch comes back with it, so an agent that agrees can send it
     * to `propose(patch:)` verbatim instead of rewriting the body.
     *
     * **This is the operation a connected assistant is structurally worst at**
     * and the server is trivially good at: an assistant cannot hold every
     * title in mind while reading one note, so it links what it happens to
     * remember. The server compares every title against the text for nothing.
     *
     * @return array<int, array{note_id: int|null, title: string, find: string, replace: string}>
     */
    private function linkCandidates(string $title, string $bodyMd): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, title FROM notes ORDER BY id LIMIT :limit',
            ['limit' => self::MAX_TITLES],
            ['limit' => ParameterType::INTEGER]
        );
        $candidates = array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['title']],
            $rows
        );

        $byTitle = [];
        foreach ($candidates as $candidate) {
            $byTitle[mb_strtolower($candidate['title'])] = $candidate['id'];
        }

        $out = [];
        foreach ($this->wikiLinks->patchFor($bodyMd, $candidates, $title) as $op) {
            // The linked title is what the operation wrapped in brackets —
            // recovered from the replacement rather than re-matched, so the
            // name shown is always the one the patch will actually write.
            if (preg_match('/\[\[(.+?)\]\]/u', $op['replace'], $m) !== 1) {
                continue;
            }
            $out[] = [
                'note_id' => $byTitle[mb_strtolower($m[1])] ?? null,
                'title' => $m[1],
                'find' => $op['find'],
                'replace' => $op['replace'],
            ];
        }

        return $out;
    }

    /**
     * Vocabulary words this text uses and the note is not filed under.
     *
     * String matching against the vault's own tag names, so a vault with no AI
     * configured still gets the filing suggestion that on-save enrichment
     * would otherwise be the only source of. Never invents a name: a tag that
     * is not already in the vocabulary cannot appear here, which is the same
     * rule the operator ruled for enrichment on 2026-08-24 (vocabulary is
     * applied, invented names are only offered) arriving at the free path.
     *
     * A name the owner RETIRED cannot appear here, and the reason is worth
     * writing down because the obvious guard for it would be wrong.
     * {@see TagAdmin::withoutRetired()} exists to filter names a MODEL
     * invented, which is a different list; this one reads the `tags` table,
     * and retiring a tag deletes its row ({@see TagAdmin::rewrite()}). So the
     * vocabulary IS the filter. Adding `withoutRetired()` on top would be dead
     * on every path today and actively harmful the moment it were not:
     * {@see NoteWriter} un-retires a name as soon as a person applies it
     * again, and a `retired_tags` row that outlived that would suppress a tag
     * the owner is currently using.
     *
     * @param string[] $ownTags
     *
     * @return string[]
     */
    private function tagCandidates(string $title, string $bodyMd, array $ownTags): array
    {
        $haystack = mb_strtolower($title."\n".$bodyMd);
        $already = array_map('mb_strtolower', $ownTags);

        $names = $this->em->getConnection()->fetchFirstColumn(
            'SELECT name FROM tags ORDER BY '.SystemTags::sqlRank('name').', name'
        );

        $hits = [];
        foreach ($names as $name) {
            $name = (string) $name;
            $lower = mb_strtolower($name);
            if (in_array($lower, $already, true) || mb_strlen($lower) < self::MIN_TAG_LENGTH) {
                continue;
            }
            // The two words the SERVER branches on are never suggested. Every
            // other hint here is "you might file it under this"; `skill` would
            // be "you might publish this note to every assistant you have
            // connected", and `live-state` "you might attach a standing
            // instruction to every retrieval of it". A curator-role connection
            // acting on the suggestion applies it without review, which is the
            // same unattended grant enrichment used to make on its own.
            if (SystemTags::isSystem($lower)) {
                continue;
            }
            // A tag is a word in the text, not a substring of one: "ai" must
            // not fire on "said", and kebab-case names have to match the
            // spaced form people actually write ("ml processor" for ml-processor).
            if (self::mentions($haystack, $lower) || self::mentions($haystack, str_replace('-', ' ', $lower))) {
                $hits[] = $name;
            }
        }

        return $hits;
    }

    /**
     * Short names match too much ordinary prose to be worth offering — the
     * same reason {@see WikiLinkSuggest} has a floor, and the same number.
     */
    private const MIN_TAG_LENGTH = 4;

    private static function mentions(string $haystack, string $needle): bool
    {
        return preg_match('/(?<![\p{L}\p{N}_-])'.preg_quote($needle, '/').'(?![\p{L}\p{N}_-])/u', $haystack) === 1;
    }
}
