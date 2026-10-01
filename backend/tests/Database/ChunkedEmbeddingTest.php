<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Command\EmbedCommand;
use App\Entity\Note;
use App\Service\EmbeddingSpend;
use App\Service\NoteChunker;
use App\Service\NoteEnricher;
use App\Service\NoteWriter;
use App\Tests\Support\MockMlResponder;

/**
 * A long note is embedded in sections and found by any of them.
 *
 * Before 2026-09-09 a note was one vector of its first 20,000 characters, so a
 * 67,000-character record was findable by meaning through its head and
 * invisible through its tail, and a hub covering fifteen subjects was the
 * average of all of them. Every test here builds a note the old embedder
 * would have cut, puts the only distinctive text past the cut, and asks
 * whether search reaches it.
 *
 * Vectors are content-addressed through the stub: text carrying the marker
 * sits on top of the query, everything else sits at right angles to it — so
 * far past the 0.68 cutoff that even the note's own mean vector (four filler
 * chunks and one marked one) stays outside it. A note is found through the
 * marked chunk or not at all, and a search that reads the note-level vector
 * instead of the chunks finds nothing.
 */
final class ChunkedEmbeddingTest extends ApiTestCase
{
    private const MARKER = 'zzmarker';
    private const QUERY = 'qqquery';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ml->vectorFor = static fn (string $content): array => str_contains($content, self::MARKER) || str_contains($content, self::QUERY)
            ? MockMlResponder::spread(0.0)
            : MockMlResponder::spread(1.0);
    }

    /** @return array<string, mixed> */
    private function search(string $bearer, string $query): array
    {
        $this->request('POST', '/mcp', $bearer, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'search', 'arguments' => ['query' => $query]],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $result = $this->jsonResponse()['result'];
        self::assertNotTrue($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));

        return json_decode($result['content'][0]['text'], true, 16, JSON_THROW_ON_ERROR);
    }

    /** @return list<array{chunk_index: int, heading: ?string, hash: string}> */
    private function chunkRows(int $noteId): array
    {
        $this->in($this->kb->a);

        return array_map(static fn (array $r): array => [
            'chunk_index' => (int) $r['chunk_index'],
            'heading' => $r['heading'],
            'hash' => $r['hash'],
        ], $this->em->getConnection()->fetchAllAssociative(
            'SELECT chunk_index, heading, lower(hex(chunk_text_hash)) AS hash
               FROM note_embedding_chunks WHERE note_id = :id ORDER BY chunk_index',
            ['id' => $noteId]
        ));
    }

    /** Every text sent to be embedded, in order, across every call so far. */
    private function textsEmbedded(): array
    {
        $texts = [];
        foreach ($this->ml->calls as $call) {
            if (!str_contains($call['url'], '/api/v1/create-embeddings')) {
                continue;
            }
            foreach ((array) ($call['body']['contents'] ?? [$call['body']['content'] ?? '']) as $text) {
                $texts[] = (string) $text;
            }
        }

        return $texts;
    }

    private function embedCalls(): int
    {
        return count(array_filter($this->ml->calls, static fn (array $c): bool => str_contains($c['url'], '/api/v1/create-embeddings')));
    }

    /**
     * A body the old embedder would have cut: 22,000 characters of filler
     * under one heading, then the marker under a second, past the cut.
     */
    private static function longBody(string $tailHeading = 'Tail', string $tail = 'The marker zzmarker sits here.'): string
    {
        $paragraph = rtrim(str_repeat('filler word ', 375));
        self::assertGreaterThan(NoteEnricher::MAX_TEXT_CHARS, mb_strlen($paragraph, 'UTF-8') * 5);

        return "## Head\n\n".implode("\n\n", array_fill(0, 5, $paragraph))."\n\n## $tailHeading\n\n$tail";
    }

    private function update(int $noteId, string $body): void
    {
        $this->in($this->kb->a);
        $note = $this->noteNumbered($noteId);
        self::getContainer()->get(NoteWriter::class)->update($note, null, $body, null, EmbeddingSpend::Metered);
    }

    public function testANoteIsFoundByMeaningThroughTextPastTheOldCut(): void
    {
        $note = $this->kb->a->note('A long record', self::longBody(), ['reference'], summary: 'Long.');

        $rows = $this->chunkRows($note->getId());
        self::assertGreaterThan(1, count($rows), 'a note this long is several chunks');
        self::assertSame('Tail', end($rows)['heading'], 'the last chunk is the tail section, under its own heading');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM note_embeddings WHERE note_id = :id', ['id' => $note->getId()]
        ), 'and the note still has its one vector for the map and the duplicate check');

        $found = $this->search($this->kb->a->agentBearer, self::QUERY);
        self::assertSame([$note->getId()], array_column($found['items'], 'id'),
            'the query matches nothing literally, so this is the meaning tier finding the tail');
        self::assertSame('Tail', $found['items'][0]['matched_section'], 'and it says which section answered');
    }

    public function testAShortNoteIsOneChunkOfExactlyTheTextItAlwaysEmbedded(): void
    {
        $note = $this->kb->a->note('Short', 'Two   sentences.  <b>That</b> is all.', ['reference'], summary: 'Short.');

        $rows = $this->chunkRows($note->getId());
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['heading']);
        self::assertSame(
            hash('sha256', 'Short Two sentences. That is all.'),
            $rows[0]['hash'],
            'the one chunk hashes exactly as the whole-note text did, so the backfill costs nothing for a short note'
        );
        self::assertSame(['Short Two sentences. That is all.'], $this->textsEmbedded());
    }

    public function testEditingOneSectionBuysOnlyThatSectionAgain(): void
    {
        $note = $this->kb->a->note('A long record', self::longBody(), ['reference'], summary: 'Long.');
        $before = $this->chunkRows($note->getId());
        $bought = count($this->textsEmbedded());
        self::assertSame(count($before), $bought, 'creation bought exactly one vector per chunk');

        $this->update($note->getId(), self::longBody(tail: 'The marker zzmarker moved to a rewritten tail.'));

        $texts = $this->textsEmbedded();
        self::assertSame($bought + 1, count($texts), 'one chunk changed, one vector bought');
        self::assertStringContainsString('rewritten tail', end($texts));
        $after = $this->chunkRows($note->getId());
        self::assertCount(count($before), $after);
        self::assertSame(array_slice(array_column($before, 'hash'), 0, -1), array_slice(array_column($after, 'hash'), 0, -1),
            'the untouched chunks keep their vectors');
        self::assertNotSame(end($before)['hash'], end($after)['hash']);
    }

    public function testASectionRemovedTakesItsChunkAndItsFindabilityWithIt(): void
    {
        $note = $this->kb->a->note('A long record', self::longBody(), ['reference'], summary: 'Long.');
        $count = count($this->chunkRows($note->getId()));
        self::assertSame([$note->getId()], array_column($this->search($this->kb->a->agentBearer, self::QUERY)['items'], 'id'));

        $body = self::longBody();
        $this->update($note->getId(), substr($body, 0, (int) strpos($body, '## Tail')));

        self::assertCount($count - 1, $this->chunkRows($note->getId()), 'no stale chunk survives an edit');
        self::assertSame([], $this->search($this->kb->a->agentBearer, self::QUERY)['items'],
            'a vector for text the note no longer holds would find the note for words it no longer contains');
    }

    public function testAnUnchangedNoteBuysNothingAndStopsBeingOfferedToTheSweep(): void
    {
        $note = $this->kb->a->note('A long record', self::longBody(), ['reference'], summary: 'Long.');
        $bought = $this->embedCalls();

        // What the 2026-09-09 migration leaves behind: the chunk rows at the
        // epoch, the note's own timestamp untouched.
        $this->em->getConnection()->executeStatement(
            "UPDATE note_embedding_chunks SET embedded_at = '1970-01-01' WHERE note_id = :id", ['id' => $note->getId()]
        );
        self::assertContains($note->getId(), $this->sweepQueue(), 'a backfilled chunk asks to be revisited');

        $fresh = $this->em->getRepository(Note::class)->find($note->getId());
        self::assertTrue(self::getContainer()->get(NoteEnricher::class)->storeEmbedding($fresh, EmbeddingSpend::OwnerInitiated));

        self::assertSame($bought, $this->embedCalls(), 'every chunk hashed the same, so nothing was bought');
        self::assertNotContains($note->getId(), $this->sweepQueue(), 'and the rows were marked as looked at');
    }

    public function testANoteWhoseOwnVectorIsMissingGetsItBackWithoutBuying(): void
    {
        $note = $this->kb->a->note('A long record', self::longBody(), ['reference'], summary: 'Long.');
        $bought = $this->embedCalls();
        $this->em->getConnection()->executeStatement('DELETE FROM note_embeddings WHERE note_id = :id', ['id' => $note->getId()]);
        self::assertContains($note->getId(), $this->sweepQueue());

        $fresh = $this->em->getRepository(Note::class)->find($note->getId());
        self::assertTrue(self::getContainer()->get(NoteEnricher::class)->storeEmbedding($fresh, EmbeddingSpend::OwnerInitiated));

        self::assertSame($bought, $this->embedCalls(), 'every chunk is still there, so the mean is recomputed and nothing is bought');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM note_embeddings WHERE note_id = :id', ['id' => $note->getId()]));
        self::assertNotContains($note->getId(), $this->sweepQueue(),
            'a note with every chunk and no vector of its own would otherwise be offered to the sweep forever');
    }

    public function testTheSweepEmbedsTheNoteAsItIsInTheDatabaseNotAsItWasLoaded(): void
    {
        $note = $this->kb->a->note('Short', 'The first body.', ['reference'], summary: 'Short.');
        $stale = $this->em->getRepository(Note::class)->find($note->getId());
        $this->em->getConnection()->executeStatement(
            "UPDATE notes SET body_md = 'A second body nobody embedded.', updated_at = :now WHERE id = :id",
            ['id' => $note->getId(), 'now' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')]
        );

        self::assertTrue(self::getContainer()->get(NoteEnricher::class)->storeEmbedding($stale, EmbeddingSpend::OwnerInitiated));

        self::assertSame(
            hash('sha256', 'Short A second body nobody embedded.'),
            $this->chunkRows($note->getId())[0]['hash'],
            'an object loaded before another request committed must not stamp the old vectors as current'
        );
        self::assertNotContains($note->getId(), $this->sweepQueue());
    }

    public function testAnEditThatLandsWhileTheProviderAnswersIsNotOverwritten(): void
    {
        $note = $this->kb->a->note('Short', 'The first body.', ['reference'], summary: 'Short.');
        $before = $this->chunkRows($note->getId());
        $conn = $this->em->getConnection();
        $conn->executeStatement("UPDATE notes SET body_md = 'Second body.' WHERE id = :id", ['id' => $note->getId()]);

        // The provider is slow and somebody edits the note while it answers.
        // `updated_at` is deliberately NOT moved: it is stored to the second, so two
        // edits in one second carry the same stamp, and a guard that compared
        // clocks would write the second body's vectors over the third.
        $this->ml->vectorFor = static function (string $content) use ($conn, $note): array {
            $conn->executeStatement("UPDATE notes SET body_md = 'Third body.' WHERE id = :id", ['id' => $note->getId()]);

            return MockMlResponder::spread(0.0);
        };
        $fresh = $this->em->getRepository(Note::class)->find($note->getId());

        self::assertFalse(self::getContainer()->get(NoteEnricher::class)->storeEmbedding($fresh, EmbeddingSpend::OwnerInitiated),
            'writing the vectors of the second body over a note that now holds the third is the wrong answer');
        self::assertSame($before, $this->chunkRows($note->getId()), 'nothing was written');
        self::assertContains($note->getId(), $this->sweepQueue(),
            'and the sweep comes back for the third body even though its stamp is the same second as the vectors it refused');
    }

    public function testAnEmbeddingTheProviderFailedLeavesTheNoteForTheSweep(): void
    {
        $note = $this->kb->a->note('Short', 'The first body.', ['reference'], summary: 'Short.');
        self::assertNotContains($note->getId(), $this->sweepQueue());
        $conn = $this->em->getConnection();
        $conn->executeStatement("UPDATE notes SET body_md = 'Second body.' WHERE id = :id", ['id' => $note->getId()]);
        $this->ml->failWith['/api/v1/create-embeddings'] = 503;

        $fresh = $this->em->getRepository(Note::class)->find($note->getId());
        self::assertFalse(self::getContainer()->get(NoteEnricher::class)->storeEmbedding($fresh, EmbeddingSpend::OwnerInitiated));

        self::assertContains($note->getId(), $this->sweepQueue(),
            'a save whose embedding failed inside the same second as the save would otherwise look current forever');
    }

    public function testAKeywordHitNamesNoSection(): void
    {
        $note = $this->kb->a->note('A long record', self::longBody(), ['reference'], summary: 'Long.');

        $found = $this->search($this->kb->a->agentBearer, self::MARKER);
        self::assertSame([$note->getId()], array_column($found['items'], 'id'));
        self::assertArrayNotHasKey('matched_section', $found['items'][0],
            'a literal match matched the note as a whole; a section would be a guess');
    }

    /** @return list<int> */
    private function sweepQueue(): array
    {
        $this->in($this->kb->a);

        return array_map('intval', $this->em->getConnection()->fetchFirstColumn(
            'SELECT n.id FROM notes n LEFT JOIN note_embeddings ne ON ne.note_id = n.id
             WHERE '.EmbedCommand::STALE_SQL.' ORDER BY n.id ASC'
        ));
    }
}
