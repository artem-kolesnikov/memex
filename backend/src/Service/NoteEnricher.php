<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Note;
use App\Entity\Tag;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Capture/save-time enrichment: summary + embedding + tag suggestions, all
 * best-effort (each failure degrades independently; the app:embed sweep is the
 * safety net for missed embeddings). Also maintains the note's wiki-links.
 */
class NoteEnricher
{
    /**
     * How much of a note the TEXT calls see — the summary and the tag
     * suggestions read the head of a long note. Embeddings are not cut here:
     * they are chunked by {@see NoteChunker}, and every chunk is in its vector.
     */
    public const MAX_TEXT_CHARS = 20000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MlClient $mlClient,
        private readonly WikiLinkParser $wikiLinkParser,
        private readonly EnrichmentSettings $settings,
        private readonly TagAdmin $tags,
        private readonly SpendLimiter $spend,
        private readonly NoteChunker $chunker,
        private readonly EmbeddingSpace $space,
    ) {
    }

    /**
     * @param bool $generateSummary false = the caller supplied a written summary;
     *                              keep it (and save the OpenAI call)
     * @param bool $applyTags       false = do not spend on tags at all. True is
     *                              the answer for every ordinary save; the one
     *                              caller that says false is installing a
     *                              shipped skill, which arrives with its tag
     *                              and its description already written by the
     *                              catalogue
     * @return array{tag_ids: int[], new_tags: string[]} `tag_ids` is what was
     *                              APPLIED to the note; `new_tags` is what the
     *                              model invented and nothing wrote — for the
     *                              caller to offer
     */
    public function enrich(Note $note, EmbeddingSpend $spend, bool $generateSummary = true, bool $applyTags = true): array
    {
        $text = $this->embeddableText($note);
        // Which provider, which model, whose key, and whether the server may
        // write text at all — the vault decides, and MlClient refuses the text
        // calls when it has not. Embeddings below run either way, on the
        // operator's key, for every vault.
        $creds = $this->settings->forVault();

        // **Before the first text call, not after it.** `storeEmbedding()`
        // below asserts the embedding ceiling, which used to be the only check
        // on this path — so an account past its allowance bought the summary here
        // and was refused three lines later, once per note, for as long as the
        // loop ran (found by Codex, 2026-09-10).
        //
        // Once for the whole note rather than per provider call: the summary
        // and the tag suggestion are two requests, and refusing between them
        // would store half a note's enrichment. Skipped entirely when nothing
        // here will spend — `MlClient` refuses the text calls for a vault whose
        // credentials say no, and charging for a call that never happens is a
        // ceiling that lies.
        if ($creds->textEnabled && ($generateSummary || $applyTags)) {
            $this->spend->assertText();
        }

        if ($generateSummary) {
            $summary = $this->mlClient->summarize($text, $creds);
            if ($summary !== null) {
                // The one place memex describes a note itself, and the one
                // place `memex` is the honest answer to "who wrote this".
                $note->setSummary($summary, Note::SUMMARY_BY_MEMEX);
                // Only on success, and only when the account is paying: this
                // is what the settings screen reports as "last used".
                if ($creds->isOwnAccount()) {
                    $this->settings->markUsed($creds->credentialId);
                }
            }
        }

        $this->storeEmbedding($note, $spend);

        if (!$applyTags) {
            return ['tag_ids' => [], 'new_tags' => []];
        }

        // **A model may not add a system tag.** `skill` and `live-state` are the
        // only two words the SERVER branches on — one publishes a note to every
        // connected assistant as loadable instructions, the other attaches a
        // standing write-back instruction to every retrieval — so putting one on
        // a note changes what agents are served. That is the owner's decision,
        // and enrichment is not the owner.
        //
        // Left out of the vocabulary rather than filtered from the answer, so
        // the model is never invited to name one; the application loop below
        // refuses them a second time, because a stale id would otherwise walk
        // straight through.
        //
        // The failure this closes was found in the operator's own knowledge
        // base on 2026-08-28: he removed `skill` from a note ABOUT memex and it
        // came back inside the same request. The edit cleared his tags and
        // re-added the ones he kept, then this method read a note that
        // discusses skills constantly, the model picked the word out of the
        // vocabulary, and it was republished silently. There was no way to
        // remove it from the interface at all — every save restored it.
        $vocab = [];
        $byId = [];
        foreach ($this->em->getRepository(Tag::class)->findAll() as $tag) {
            if (SystemTags::isSystem($tag->getName())) {
                continue;
            }
            $vocab[] = ['id' => $tag->getId(), 'name' => $tag->getName()];
            $byId[$tag->getId()] = $tag;
        }

        $suggested = $this->mlClient->suggestTags($note->getTitle(), $text, $vocab, $creds);
        $suggested['tag_ids'] = array_values(array_filter($suggested['tag_ids'], static fn (int $id): bool => isset($byId[$id])));

        // A name the owner deliberately took out of their vocabulary is not
        // offered back to them. Without this, removing `inbox` from ninety
        // notes lasts until the next note arrives, the model reads it, decides
        // it looks like an inbox item, and invents the word again — so the
        // owner deletes it a second time, and a third.
        //
        // Only `new_tags` can carry one: `tag_ids` are rows that exist, and
        // retiring a tag deletes its row.
        $suggested['new_tags'] = $this->tags->withoutRetired($suggested['new_tags']);

        // **The vocabulary is APPLIED; invented names are only offered**
        // (operator, 2026-08-23). Until then this method returned both and the
        // website displayed neither, so every save bought a tag call whose
        // answer was discarded — the same shape as the summary, which has
        // always been written here rather than handed back.
        //
        // The split is not squeamishness. `tag_ids` are rows the owner's own
        // vocabulary already contains, so applying one files the note under a
        // heading they chose; `new_tags` are words a model made up, and models
        // reliably invent near-duplicates of what is already there. Writing
        // those automatically is how a taxonomy stops being a taxonomy, and it
        // is why `retired_tags` had to exist at all.
        //
        // ADDITIVE, always. A tag somebody chose is never removed by a save:
        // the model sees the whole vocabulary but not the reason a note was
        // filed the way it was.
        foreach ($suggested['tag_ids'] as $id) {
            // `$byId` already excludes the held words, so this is the second
            // guard rather than the only one: an id the model returned from a
            // vocabulary it saw earlier finds nothing here.
            if (isset($byId[$id])) {
                $note->addTag($byId[$id]);
            }
        }

        return $suggested;
    }

    /**
     * Embeds the note in chunks and stores one vector per chunk plus their
     * normalised mean as the note's own vector. Buys nothing for a chunk whose
     * text is unchanged: chunks are matched by the hash of their text, so an
     * edit inside one section re-embeds that section and the mean, and a
     * re-save that only moved a tag re-embeds nothing.
     */
    public function storeEmbedding(Note $note, EmbeddingSpend $spend): bool
    {
        $conn = $this->em->getConnection();
        $noteId = (int) $note->getId();
        // The text is read from the database, not from the object handed in:
        // the sweep loads a note, another request commits a newer body while
        // the provider answers, and an object hashed against the stored chunks
        // would stamp the old vectors as current for text they do not hold.
        // Before anything is written the row is read again inside the write
        // transaction, and the chunks recomputed from it must be the ones
        // about to be stored — content, not a timestamp, because `updated_at`
        // is stored to the second and two edits in one second read as none.
        $fresh = $conn->fetchAssociative('SELECT title, body_md FROM notes WHERE id = :id', ['id' => $noteId]);
        if ($fresh === false) {
            return false;
        }
        $model = $this->space->model();
        $chunks = $this->chunker->chunks((string) $fresh['title'], (string) $fresh['body_md'], $model->chunkChars());
        if ($chunks === []) {
            return false;
        }
        foreach ($chunks as $i => $chunk) {
            $chunks[$i]['hash'] = hash('sha256', $chunk['text'], true);
        }

        // Which of the wanted chunks the table already holds, matched by hash
        // so a section that moved keeps its vector.
        $wantedHex = array_map(static fn (array $c): string => bin2hex($c['hash']), $chunks);
        $wanted = array_flip($wantedHex);
        $storedChunks = $conn->fetchAllAssociative(
            'SELECT id, chunk_text_hash FROM note_embedding_chunks WHERE note_id = :id',
            ['id' => $noteId]
        );
        $reusable = [];
        foreach ($storedChunks as $row) {
            $hex = bin2hex((string) $row['chunk_text_hash']);
            if (!isset($wanted[$hex]) || isset($reusable[$hex])) {
                continue;
            }
            $vector = $conn->fetchOne(
                'SELECT embedding FROM note_embedding_chunk_vectors WHERE rowid = :id',
                ['id' => (int) $row['id']],
                ['id' => ParameterType::INTEGER]
            );
            if (is_string($vector)) {
                $reusable[$hex] = self::unpackVector($vector);
            }
        }

        $stored = count($storedChunks);
        // The note's own vector has to be there too, or a note whose chunks
        // survived some accident of its note row would be offered to the sweep
        // forever while every chunk hashed as unchanged.
        $hasOwnVector = $conn->fetchOne('SELECT 1 FROM note_embeddings WHERE note_id = :id', ['id' => $noteId]) !== false;
        $toEmbed = [];
        foreach ($chunks as $i => $chunk) {
            if (!isset($reusable[$wantedHex[$i]])) {
                $toEmbed[] = $i;
            }
        }
        $planned = array_column($chunks, 'hash');
        if ($toEmbed === [] && $stored === count($chunks) && $hasOwnVector) {
            // Every vector is right and costs nothing to keep — but the rows
            // have to be marked as looked at, or the sweep never stops offering
            // the note. `app:embed` selects on `embedded_at < n.updated_at`,
            // and plenty of edits bump `updated_at` without changing a word of
            // the embeddable text: a tag change, a retitle that only alters
            // case, an approval that rewrote the summary. Each one would sit at
            // the head of the sweep forever, reporting SUCCESS, starving the
            // backfill the sweep exists for.
            $stale = false;
            $conn->transactional(function () use ($conn, $noteId, $planned, $model, &$stale): void {
                if (!$this->stillChunksTo($conn, $noteId, $planned, $model)) {
                    $stale = true;
                    self::markForSweep($conn, $noteId);

                    return;
                }
                $now = self::now();
                $conn->executeStatement(
                    'UPDATE note_embeddings SET embedded_at = :now WHERE note_id = :id',
                    ['now' => $now, 'id' => $noteId]
                );
                $conn->executeStatement(
                    'UPDATE note_embedding_chunks SET embedded_at = :now WHERE note_id = :id',
                    ['now' => $now, 'id' => $noteId]
                );
            });

            return !$stale;
        }

        if ($toEmbed !== []) {
            // THE lines that spend. Everything above is free.
            //
            // The limit is asserted HERE rather than in the controllers because
            // the controllers are where it was forgotten. `assertAnalyze`
            // guards its one call site; this path had five and four were missed — MCP `propose`, POST /api/notes and
            // POST /api/upload each bought an unbounded number on the operator's
            // key, reachable by the agent tokens the review gate exists to
            // distrust. Enforced once, where the money leaves, is the same
            // argument CLAUDE.md §Spend makes for AiCredentials. One unit per
            // chunk bought, because a long note is several purchases.
            //
            // Throws TooManyRequestsHttpException, which SpendLimitListener
            // turns into a 429 with Retry-After.
            if ($spend === EmbeddingSpend::Metered) {
                foreach ($toEmbed as $_) {
                    $this->spend->assertEmbedding();
                }
            }
            $vectors = $this->mlClient->embedContents(
                array_map(static fn (int $i) => $chunks[$i]['text'], $toEmbed),
            );
            if ($vectors === null) {
                self::markForSweep($conn, $noteId);

                return false;
            }
            foreach ($toEmbed as $n => $i) {
                $reusable[$wantedHex[$i]] = array_values($vectors[$n]);
            }
        }

        $mean = array_fill(0, $model->dimensions(), 0.0);
        $tokenEst = 0;
        foreach ($chunks as $i => $chunk) {
            foreach ($reusable[$wantedHex[$i]] as $d => $v) {
                $mean[$d] += $v;
            }
            $chunks[$i]['token_est'] = (int) ceil(mb_strlen($chunk['text'], 'UTF-8') / 4);
            $tokenEst += $chunks[$i]['token_est'];
        }
        $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $mean)));
        if ($norm > 0) {
            $mean = array_map(static fn (float $v): float => $v / $norm, $mean);
        }
        $documentHash = hash('sha256', implode('', array_column($chunks, 'hash')), true);

        $stale = false;
        $conn->transactional(function () use ($conn, $noteId, $chunks, $reusable, $wantedHex, $mean, $tokenEst, $documentHash, $planned, $model, &$stale): void {
            // The provider round trip above took time; a newer edit, or a new
            // embedding model, may have landed meanwhile. Its vectors are not
            // these, so write nothing — the sweep comes back for it.
            if (!$this->stillChunksTo($conn, $noteId, $planned, $model)) {
                $stale = true;
                self::markForSweep($conn, $noteId);

                return;
            }
            $now = self::now();
            // Deleting a metadata row deletes its vector (trigger), so the
            // note's vectors are replaced whole.
            $conn->executeStatement('DELETE FROM note_embedding_chunks WHERE note_id = :id', ['id' => $noteId]);
            foreach ($chunks as $i => $chunk) {
                $conn->executeStatement(
                    'INSERT INTO note_embedding_chunks (note_id, chunk_index, heading, token_est, chunk_text_hash, embedded_at)
                     VALUES (:note_id, :i, :heading, :token_est, :hash, :now)',
                    [
                        'note_id' => $noteId,
                        'i' => $i,
                        'heading' => $chunk['heading'] === null ? null : mb_substr($chunk['heading'], 0, 500, 'UTF-8'),
                        'token_est' => $chunk['token_est'],
                        'hash' => $chunk['hash'],
                        'now' => $now,
                    ],
                    ['note_id' => ParameterType::INTEGER, 'i' => ParameterType::INTEGER, 'token_est' => ParameterType::INTEGER, 'hash' => ParameterType::BINARY]
                );
                $conn->executeStatement(
                    'INSERT INTO note_embedding_chunk_vectors (rowid, embedding) VALUES (:id, :embedding)',
                    ['id' => (int) $conn->lastInsertId(), 'embedding' => self::packVector($reusable[$wantedHex[$i]])],
                    ['id' => ParameterType::INTEGER, 'embedding' => ParameterType::BINARY]
                );
            }
            $conn->executeStatement('DELETE FROM note_embeddings WHERE note_id = :id', ['id' => $noteId]);
            $conn->executeStatement(
                'INSERT INTO note_embeddings (note_id, token_est, embedded_text_hash, embedded_at)
                 VALUES (:note_id, :token_est, :hash, :now)',
                ['note_id' => $noteId, 'token_est' => $tokenEst, 'hash' => $documentHash, 'now' => $now],
                ['note_id' => ParameterType::INTEGER, 'token_est' => ParameterType::INTEGER, 'hash' => ParameterType::BINARY]
            );
            $conn->executeStatement(
                'INSERT INTO note_embedding_vectors (rowid, embedding) VALUES (:id, :embedding)',
                ['id' => $noteId, 'embedding' => self::packVector($mean)],
                ['id' => ParameterType::INTEGER, 'embedding' => ParameterType::BINARY]
            );
        });

        return !$stale;
    }

    /**
     * Answers whether the vault still embeds with the model the vectors came
     * from, and the note's text, chunked now, is the set of hashes about to be
     * written or stamped. Called inside the caller's transaction, which holds
     * the vault's write lock from its start, so nothing changes in between.
     *
     * @param list<string> $planned raw sha256 of each chunk, in order
     */
    private function stillChunksTo(Connection $conn, int $noteId, array $planned, EmbeddingModel $model): bool
    {
        if ($conn->fetchOne('SELECT embedding_model FROM settings') !== $model->value) {
            return false;
        }
        $row = $conn->fetchAssociative('SELECT title, body_md FROM notes WHERE id = :id', ['id' => $noteId]);
        if ($row === false) {
            return false;
        }
        $now = array_map(
            static fn (array $c): string => hash('sha256', $c['text'], true),
            $this->chunker->chunks((string) $row['title'], (string) $row['body_md'], $model->chunkChars())
        );

        return $now === $planned;
    }

    /**
     * An embedding that did not land — the provider failed, or the note
     * changed while it answered — must not leave the note looking current.
     * `updated_at` and `embedded_at` are stored to the second, so a save and
     * its failed embedding inside one second read as equal and the sweep's
     * `embedded_at < updated_at` would never fire; the epoch is what the
     * sweep already treats as stale, and the search canary as not current.
     */
    private static function markForSweep(Connection $conn, int $noteId): void
    {
        $conn->executeStatement("UPDATE note_embeddings SET embedded_at = '1970-01-01 00:00:00' WHERE note_id = :id", ['id' => $noteId]);
        $conn->executeStatement("UPDATE note_embedding_chunks SET embedded_at = '1970-01-01 00:00:00' WHERE note_id = :id", ['id' => $noteId]);
    }

    /** @param list<float> $vector */
    private static function packVector(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /** @return list<float> */
    private static function unpackVector(string $blob): array
    {
        return array_values(unpack('g*', $blob) ?: []);
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /**
     * The note a wiki-link target names, or null.
     *
     * Tiers: exact title → exact import path → path suffix (covers
     * basename-of-path for plain targets AND requires the full path for
     * [[dir/name]] targets — a bare-basename match let [[projects/README]]
     * land on any path ending in README) → title = basename (guarded nonempty: [[dir/]] has
     * an empty basename and must resolve to nothing, not to whatever compares
     * equal to '').
     *
     * The target is tried EXACTLY first and only then with runs of space, tab
     * and newline collapsed. That order is the whole point: a target broken
     * over a line wrap carries the newline and the next line's indentation and
     * matches no title, but a note genuinely titled with a tab or a double
     * space is matched by a target spelled the same way, and collapsing before
     * trying would lose that link to repair a different one. Only the
     * characters a wrap actually produces are collapsed — not every Unicode
     * space — so an intentional non-breaking space still tells two titles
     * apart.
     */
    private function resolveTarget(Note $note, string $target): ?int
    {
        $toId = $this->em->getConnection()->fetchOne(
            'SELECT '.self::bestMatchSql(':target', 'n', 'n.id != :self'),
            ['target' => $target, 'self' => $note->getId()],
            ['self' => ParameterType::INTEGER]
        );

        return $toId === false || $toId === null ? null : (int) $toId;
    }

    /**
     * The id of the note a stored target names, or NULL: the first tier any
     * note matches, and the lowest id within it. Built once because three
     * queries need it and drifted twice when each carried its own copy. Each
     * tier is its own subquery because SQLite 3.45, the box's, cannot see an
     * outer column from a subquery's ORDER BY.
     *
     * Every EXACT tier outranks every repaired one: a note genuinely titled
     * with a double space or a tab is matched by a target spelled the same
     * way, and only a target that matches nothing exactly is retried with a
     * line wrap collapsed. The basename tier is guarded nonempty because
     * [[dir/]] has an empty basename and must resolve to nothing rather than
     * to whatever compares equal to '' — before that guard, any note with a
     * NULL import path claimed every such link.
     */
    private static function bestMatchSql(string $target, string $alias, string $scope): string
    {
        return 'COALESCE('.implode(', ', array_map(
            static fn (string $tier): string => "(SELECT min($alias.id) FROM notes $alias WHERE $scope AND $tier)",
            self::tiers($target, $alias),
        )).')';
    }

    /** Whether any tier matches: {@see self::bestMatchSql()} without the ranking. */
    private static function anyTierSql(string $target, string $alias): string
    {
        return '('.implode(' OR ', self::tiers($target, $alias)).')';
    }

    /** @return list<string> */
    private static function tiers(string $target, string $alias): array
    {
        $tiers = [];
        foreach ([$target, self::unwrapSql($target)] as $candidate) {
            // Everything after the last `/`: the prefix up to it is what is
            // left once every other character is trimmed from the right.
            $basename = "substr($candidate, length(rtrim($candidate, replace($candidate, '/', ''))) + 1)";
            $tiers[] = "LOWER($alias.title) = LOWER($candidate)";
            $tiers[] = "LOWER($alias.import_path) = LOWER($candidate)";
            $tiers[] = "LOWER(substr($alias.import_path, -(length($candidate) + 1))) = '/' || LOWER($candidate)";
            $tiers[] = "($basename != '' AND LOWER($alias.title) = LOWER($basename))";
        }

        return $tiers;
    }

    /**
     * `$sql` with every run of space, tab, CR and LF made one space and the
     * ends trimmed: a line wrap repaired. SQLite has no regex, so the wrap
     * characters become spaces and each pass then halves every run of them;
     * nine passes collapse any run a 500-character target can hold.
     */
    private static function unwrapSql(string $sql): string
    {
        $collapsed = "replace(replace(replace($sql, char(9), ' '), char(10), ' '), char(13), ' ')";
        for ($pass = 0; $pass < 9; ++$pass) {
            $collapsed = "replace($collapsed, '  ', ' ')";
        }

        return "trim($collapsed)";
    }

    /**
     * Re-resolves the links a rename can have changed the answer to.
     *
     * {@see self::syncLinks()} rebuilds a note's outgoing links and catches up
     * incoming ones that resolved to nothing; neither revisits a link that
     * already resolved. So a rename left every existing link still pointing at
     * this note under a name it no longer answers to, and the target it names
     * may now be a different note entirely — silently, until some unrelated
     * edit re-ran resolution on the linking note.
     *
     * Three kinds of link are decided by a rename, and no others: one that
     * points HERE, one that names the title this note has stopped answering
     * to, and one that names the title it has started answering to and
     * currently resolves somewhere else.
     *
     * Each is re-resolved on its own rather than by re-running syncLinks on
     * the linking note, which would rebuild all of that note's links from its
     * body — and a merge deliberately leaves a link whose raw target names the
     * ABSORBED note pointing at the keeper ({@see NoteWriter::merge()}). That
     * redirect has no body text behind it, so a rebuild would resolve the dead
     * name to nothing and silently discard an alias on a link nobody touched.
     */
    public function relinkReferrers(Note $note, ?string $previousTitle = null): void
    {
        $conn = $this->em->getConnection();
        $unwrapped = self::unwrapSql('nl.raw_target');
        $rows = $conn->fetchAllAssociative(
            "SELECT nl.from_note_id, nl.raw_target
             FROM note_links nl
             WHERE nl.from_note_id != :id
               AND (nl.to_note_id = :id
                 OR (:prev != '' AND LOWER(nl.raw_target) = LOWER(:prev))
                 OR (nl.to_note_id IS NOT :id
                     AND (LOWER(nl.raw_target) = LOWER(:now) OR LOWER($unwrapped) = LOWER(:now))))",
            [
                'id' => $note->getId(),
                'prev' => $previousTitle ?? '',
                'now' => $note->getTitle(),
            ],
            ['id' => ParameterType::INTEGER]
        );

        $repo = $this->em->getRepository(Note::class);
        foreach ($rows as $row) {
            $from = $repo->find((int) $row['from_note_id']);
            if ($from === null) {
                continue;
            }
            $toId = $this->resolveTarget($from, (string) $row['raw_target']);
            $conn->executeStatement(
                'UPDATE note_links SET to_note_id = :to WHERE from_note_id = :from AND raw_target = :raw',
                ['to' => $toId, 'from' => $from->getId(), 'raw' => $row['raw_target']]
            );
        }
    }

    /**
     * Rebuilds note_links from the current body. Targets resolve in priority
     * order: exact title → exact import path → import-path suffix ('/target')
     * → title equals the target's basename. Vaults link by path
     * ([[infra/hosts]]) or by filename while our display identity is the
     * (frontmatter) title — both must land (learned from the first real vault
     * import). A path-style target must match the whole path tail, and an
     * empty basename matches nothing (vault-audit bugs 1+2).
     */
    public function syncLinks(Note $note): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM note_links WHERE from_note_id = :id', ['id' => $note->getId()]);

        foreach ($this->wikiLinkParser->extractTargets($note->getBodyMd()) as $target) {
            // Tiers: exact title → exact import path → path suffix (covers
            // basename-of-path for plain targets AND requires the full path
            // for [[dir/name]] targets — a bare-basename match let
            // [[projects/README]] land on any */README) → title = basename
            // (guarded nonempty: [[dir/]] has an empty basename and must
            // resolve to nothing, not to whatever compares equal to '').
            $toId = $this->resolveTarget($note, $target);
            $conn->executeStatement(
                'INSERT INTO note_links (from_note_id, to_note_id, raw_target) VALUES (:from, :to, :raw)
                 ON CONFLICT (from_note_id, raw_target) DO NOTHING',
                ['from' => $note->getId(), 'to' => $toId, 'raw' => $target]
            );
        }

        // Catch-up: unresolved links elsewhere that this note's arrival can
        // answer. It is this note's identifiers that qualify a row (the
        // EXISTS), but the destination written is whichever note the target
        // ranks highest — the same answer a write to the linking note would
        // reach, rather than this note by virtue of being the one that moved.
        $best = self::bestMatchSql('nl.raw_target', 'n2', 'n2.id != nl.from_note_id');
        $any = self::anyTierSql('nl.raw_target', 'n2');
        $conn->executeStatement(
            "UPDATE note_links AS nl SET to_note_id = $best
             WHERE nl.to_note_id IS NULL
               AND nl.from_note_id != :id
               AND EXISTS (SELECT 1 FROM notes n2 WHERE n2.id = :id AND $any)",
            ['id' => $note->getId()],
            ['id' => ParameterType::INTEGER]
        );
    }

    /** Re-runs resolution over all unresolved links of the vault; returns fixed count. */
    public function relinkUnresolved(): int
    {
        $best = self::bestMatchSql('nl.raw_target', 'n2', 'n2.id != nl.from_note_id');

        return (int) $this->em->getConnection()->executeStatement(
            "WITH resolved AS (
               SELECT nl.id AS link_id, $best AS target_id
               FROM note_links nl
               WHERE nl.to_note_id IS NULL
             )
             UPDATE note_links SET to_note_id = resolved.target_id
             FROM resolved
             WHERE note_links.id = resolved.link_id AND resolved.target_id IS NOT NULL"
        );
    }

    public function embeddableText(Note $note): string
    {
        $text = $note->getTitle().' '.$note->getBodyMd();
        $text = strip_tags($text);
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if (mb_strlen($text, 'UTF-8') > self::MAX_TEXT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_TEXT_CHARS, 'UTF-8');
        }

        return $text;
    }
}
