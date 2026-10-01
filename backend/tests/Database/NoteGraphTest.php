<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\NoteGraph;
use App\Tests\Support\Embeddings;
use App\Tests\Support\MockMlResponder;

/**
 * The local neighbourhood map (`/api/notes/{id}/graph`) and the collection map
 * (`/api/graph`).
 *
 * The claims worth pinning are the ones a drawing hides rather than shows: a
 * map that quietly stops at one hop still looks like a map, a map that draws a
 * neighbour it should not have reached looks exactly as convincing, and a
 * cross-team edge is invisible to the eye that is looking at a picture.
 */
final class NoteGraphTest extends ApiTestCase
{
    /** @return array<string, mixed> */
    private function graphOf(int $noteId, int $depth = 1): array
    {
        $this->sessionRequest('GET', "/api/notes/$noteId/graph?depth=$depth");
        self::assertSame(200, $this->httpStatus(), $this->body());

        return $this->jsonResponse();
    }

    /** @param array<string, mixed> $graph @return int[] */
    private function nodeIds(array $graph): array
    {
        $ids = array_map(static fn (array $n) => $n['id'], $graph['nodes']);
        sort($ids);

        return $ids;
    }

    /** @param array<string, mixed> $graph @return int[] The notes a path of links actually reaches. */
    private function hopIds(array $graph): array
    {
        $ids = array_map(
            static fn (array $n) => $n['id'],
            array_filter($graph['nodes'], static fn (array $n) => $n['hop'] !== null)
        );
        sort($ids);

        return $ids;
    }

    public function testTheNeighbourhoodIsTheNoteAndWhatTouchesIt(): void
    {
        $centre = $this->kb->a->note('Hermes VM', 'The box.');
        $out = $this->kb->a->note('Deploy checklist', 'Read [[Hermes VM]] first.');
        $in = $this->kb->a->note('Restart runbook', 'See [[Hermes VM]].');
        $far = $this->kb->a->note('Unrelated', 'Nothing here.');
        $this->loginAs($this->kb->a);

        $graph = $this->graphOf((int) $centre->getId());

        self::assertNotContains((int) $far->getId(), $this->hopIds($graph),
            'a note with no path to the centre was drawn as a neighbour');
        self::assertContains((int) $out->getId(), $this->hopIds($graph));
        self::assertContains((int) $in->getId(), $this->hopIds($graph),
            'a note that CITES the centre belongs to its neighbourhood — links are followed both ways');

        $centreNode = $this->nodeById($graph, (int) $centre->getId());
        self::assertSame(0, $centreNode['hop']);
        self::assertSame(1, $this->nodeById($graph, (int) $in->getId())['hop']);
        self::assertCount(2, array_filter($graph['edges'], static fn (array $e) => $e['kind'] === 'link'));
    }

    public function testDepthTwoReachesTheSecondHopAndDepthOneDoesNot(): void
    {
        $centre = $this->kb->a->note('Centre', 'Body.');
        $near = $this->kb->a->note('Near', 'Links to [[Centre]].');
        $second = $this->kb->a->note('Second', 'Links to [[Near]].');
        $this->loginAs($this->kb->a);

        self::assertNotContains((int) $second->getId(), $this->hopIds($this->graphOf((int) $centre->getId(), 1)),
            'depth 1 drew a note two hops away');

        $deep = $this->graphOf((int) $centre->getId(), 2);
        self::assertContains((int) $second->getId(), $this->hopIds($deep));
        self::assertSame(2, $this->nodeById($deep, (int) $second->getId())['hop']);
        self::assertSame(2, $deep['depth']);
    }

    public function testDepthIsClampedToTheSupportedRange(): void
    {
        $centre = $this->kb->a->note('Centre', 'Body.');
        $this->loginAs($this->kb->a);

        self::assertSame(NoteGraph::MAX_DEPTH, $this->graphOf((int) $centre->getId(), 9)['depth']);
        self::assertSame(1, $this->graphOf((int) $centre->getId(), 0)['depth']);
    }

    public function testNodesCarryTheQueuesOwnDefectsAndTheOperatorsFlag(): void
    {
        $centre = $this->kb->a->note('Centre', 'Points at [[Nowhere at all]].');
        $bare = $this->kb->a->note('Bare', 'Links to [[Centre]].');
        $this->loginAs($this->kb->a);

        $this->sessionRequest('POST', '/api/notes/'.$centre->getId().'/flag', ['comment' => 'Check this one.']);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $graph = $this->graphOf((int) $centre->getId());
        $centreNode = $this->nodeById($graph, (int) $centre->getId());

        self::assertContains('dangling_links', $centreNode['defects'],
            'a link that leads nowhere is the defect the map exists to show');
        self::assertTrue($centreNode['flagged'], 'the operator flag never reached the map');
        self::assertContains('untagged', $this->nodeById($graph, (int) $bare->getId())['defects']);

        foreach ($graph['nodes'] as $node) {
            self::assertNotSame('Nowhere at all', $node['title'],
                'a dangling link invented a node for a note that does not exist');
        }
    }

    public function testASemanticNeighbourIsDrawnOnlyWhenNothingLinksItAndItIsClose(): void
    {
        $edge = Embeddings::model(self::getContainer())->graphDistance();
        $angles = ['centre' => 0.0, 'close' => $edge / 4, 'distant' => $edge + 0.2, 'linked' => $edge / 8];
        $this->ml->vectorFor = static function (string $text) use ($angles): array {
            foreach ($angles as $word => $distance) {
                if (str_contains($text, $word)) {
                    return MockMlResponder::spread($distance);
                }
            }

            return MockMlResponder::spread(1.0);
        };

        $centre = $this->kb->a->note('Centre', 'centre');
        $close = $this->kb->a->note('Close in meaning', 'close');
        $this->kb->a->note('Distant in meaning', 'distant');
        $this->kb->a->note('Already linked', 'linked, and points at [[Centre]].');

        $this->loginAs($this->kb->a);
        $graph = $this->graphOf((int) $centre->getId());

        $semantic = array_values(array_filter($graph['edges'], static fn (array $e) => $e['kind'] === 'semantic'));
        self::assertSame([(int) $close->getId()], array_column($semantic, 'to'),
            'the dashed edge is a SUGGESTION: only an unlinked note that is genuinely close belongs on it');
        self::assertNull($this->nodeById($graph, (int) $close->getId())['hop'],
            'a semantic neighbour is not a hop away — nothing links it');
    }

    /**
     * A capped map must draw the suggestions BETWEEN THE NOTES IT DREW.
     *
     * The k-NN join ranked each note's nearest against the whole vault and then
     * discarded the ones that had been truncated away, so a note whose two
     * closest were both off the map contributed nothing — including to a note
     * that WAS on the map and well inside the cutoff. The picture then said
     * "nothing is close to this" about a pair it had both halves of.
     */
    public function testACappedMapStillSuggestsBetweenTheNotesItDrew(): void
    {
        // `kept` and `partner` are close to each other; `hidden` is closer to
        // `kept` than `partner` is, and is the one the cap removes.
        $angles = ['kept' => 0.0, 'hiddenone' => 0.02, 'hiddentwo' => 0.03, 'partner' => 0.10];
        $this->ml->vectorFor = static function (string $text) use ($angles): array {
            foreach ($angles as $word => $distance) {
                if (str_contains($text, $word)) {
                    return MockMlResponder::spread($distance);
                }
            }

            return MockMlResponder::spread(1.0);
        };

        // Flagged, so the truncation ranking keeps exactly these two.
        $kept = $this->kb->a->note('Kept', 'kept');
        $partner = $this->kb->a->note('Partner', 'partner');
        $this->kb->a->note('Hidden one', 'hiddenone');
        $this->kb->a->note('Hidden two', 'hiddentwo');

        $this->loginAs($this->kb->a);
        foreach ([$kept, $partner] as $note) {
            $this->sessionRequest('POST', '/api/notes/'.$note->getId().'/flag', ['comment' => 'Keep.']);
            self::assertSame(200, $this->httpStatus(), $this->body());
        }

        $this->in($this->kb->a);
        $graph = self::getContainer()->get(NoteGraph::class)->map(semantic: true, cap: 2);

        self::assertTrue($graph['truncated'], 'the fixture did not actually truncate');
        self::assertSame(
            [(int) $kept->getId(), (int) $partner->getId()],
            $this->nodeIds($graph),
            'the cap kept the wrong two notes, so the rest of this proves nothing',
        );

        $semantic = array_values(array_filter($graph['edges'], static fn (array $e) => $e['kind'] === 'semantic'));
        self::assertCount(1, $semantic, 'the two notes on the map are close and unlinked, and got no suggestion');
        self::assertSame(
            [(int) $kept->getId(), (int) $partner->getId()],
            [min($semantic[0]['from'], $semantic[0]['to']), max($semantic[0]['from'], $semantic[0]['to'])],
        );
    }

    public function testTheMapCoversTheCollectionAndOnlyDrawsItsOwnEdges(): void
    {
        $one = $this->kb->a->note('One', 'Links to [[Two]].');
        $two = $this->kb->a->note('Two', 'Body.');
        $this->kb->b->note('Two', 'A note of the same name in another team.');
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/graph');
        self::assertSame(200, $this->httpStatus(), $this->body());
        $map = $this->jsonResponse();

        $ids = $this->nodeIds($map);
        self::assertContains((int) $one->getId(), $ids);
        self::assertContains((int) $two->getId(), $ids);
        self::assertSame(count($map['nodes']), $map['total_notes']);
        self::assertFalse($map['truncated']);
        self::assertSame(
            [['from' => (int) $one->getId(), 'to' => (int) $two->getId(), 'kind' => 'link']],
            $map['edges']
        );
    }

    public function testAnotherTeamsNoteIsNotAMapAndItsNotesAreNeverDrawn(): void
    {
        // Team B gets the extra note, so it holds a NUMBER team A does not.
        // Since numbers restart per vault, both vaults hold a note 1 and asking
        // for 1 is a question about your own — the probe has to be a number
        // only the other vault has, or it tests nothing.
        $this->kb->b->note('Theirs', 'Body.');
        $theirs = $this->kb->b->note('Theirs as well', 'Body.');
        $mine = $this->kb->a->note('Mine', 'Body.');
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/notes/'.$theirs->getId().'/graph');
        self::assertSame(404, $this->httpStatus(), 'another team\'s note answered with a map');

        $this->sessionRequest('GET', '/api/graph');
        $ids = $this->nodeIds($this->jsonResponse());
        self::assertSame([(int) $mine->getId()], $ids, 'the map crossed a team boundary');
    }


    public function testTheShortestPathIsTheHopThatIsReported(): void
    {
        // A diamond with a shortcut: `far` is two hops one way and one the
        // other, and a traversal that records whichever it meets first reports
        // the wrong distance without drawing a wrong node.
        $centre = $this->kb->a->note('Centre', 'Points at [[Far]].');
        $middle = $this->kb->a->note('Middle', 'Points at [[Centre]] and [[Far]].');
        $far = $this->kb->a->note('Far', 'Body.');
        $this->loginAs($this->kb->a);

        $graph = $this->graphOf((int) $centre->getId(), 2);

        self::assertSame(1, $this->nodeById($graph, (int) $far->getId())['hop'],
            'a note reachable in one hop was reported as two');
        self::assertSame(1, $this->nodeById($graph, (int) $middle->getId())['hop']);
        self::assertCount(3, $graph['nodes'], 'a cycle was walked more than once');
    }

    public function testNoRowOfAnotherTeamsCanChangeThisTeamsMap(): void
    {
        $mine = $this->kb->a->note('Mine', 'Body.');
        $theirs = $this->kb->b->note('Theirs', 'Body.');
        $theirOther = $this->kb->b->note('Theirs as well', 'Body.');
        self::assertSame($mine->getId(), $theirs->getId(), 'Precondition: B holds a note by the number of A\'s');
        $conn = $this->em->getConnection();

        // Ids restart per vault, so rows about B's note of the same number —
        // hand-written in B's vault: a tag no write path would coin, and a
        // link into it — name A's note by number too.
        $conn->executeStatement('INSERT INTO tags (name) VALUES (:name)', ['name' => 'Their Tag']);
        $theirTag = (int) $conn->lastInsertId();
        $conn->executeStatement('INSERT INTO note_tag (note_id, tag_id) VALUES (:note, :tag)',
            ['note' => (int) $theirs->getId(), 'tag' => $theirTag]);
        $conn->executeStatement(
            'INSERT INTO note_links (from_note_id, to_note_id, raw_target) VALUES (:from, :to, :target)',
            ['from' => (int) $theirOther->getId(), 'to' => (int) $theirs->getId(), 'target' => 'Mine']
        );

        $this->loginAs($this->kb->a);
        $graph = $this->graphOf((int) $mine->getId());
        $node = $this->nodeById($graph, (int) $mine->getId());

        self::assertSame([(int) $mine->getId()], $this->nodeIds($graph),
            'another team\'s link put their note on this map');
        self::assertContains('untagged', $node['defects'],
            'another team\'s tag made this note look filed');
        self::assertContains('disconnected', $node['defects'],
            'another team\'s link made this note look connected');
        self::assertNotContains('nonstandard_tags', $node['defects'],
            'another team\'s tag name became a finding about this note');
    }

    public function testASuggestionSurvivesANoteThatIsAlreadyWellLinked(): void
    {
        // The index is asked for more candidates than survive, because it
        // filters after it scans: ask for exactly four and a note whose four
        // nearest are all already linked gets no suggestion at all.
        $this->ml->vectorFor = static function (string $text): array {
            preg_match('/n(\d+)/', $text, $m);

            return MockMlResponder::spread(0.01 * (float) ($m[1] ?? 40));
        };

        $centre = $this->kb->a->note('Centre', 'n0 '.implode(' ', array_map(
            static fn (int $i) => "[[Near $i]]",
            range(1, 6)
        )));
        for ($i = 1; $i <= 6; ++$i) {
            $this->kb->a->note("Near $i", "n$i");
        }
        $unlinked = $this->kb->a->note('Unlinked but close', 'n7');

        $this->loginAs($this->kb->a);
        $graph = $this->graphOf((int) $centre->getId());

        $semantic = array_values(array_filter($graph['edges'], static fn (array $e) => $e['kind'] === 'semantic'));
        self::assertSame([(int) $unlinked->getId()], array_column($semantic, 'to'),
            'the only unlinked near note was crowded out by notes that are already linked');
    }

    public function testACappedMapKeepsWhatNeedsWorkAndSaysItIsCapped(): void
    {
        $this->in($this->kb->a);
        $conn = $this->em->getConnection();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        // Written straight to the table: this is a test about a number, and six
        // hundred notes through the write path is a minute of enrichment.
        for ($i = 0; $i < NoteGraph::MAP_NODE_CAP; ++$i) {
            $conn->executeStatement(
                "INSERT INTO notes (title, body_md, source, status, summary, created_at, updated_at)
                 VALUES (:title, 'Body.', 'manual', 'verified', 'A summary.', :now, :now)",
                ['title' => "Filler $i", 'now' => $now]
            );
        }
        $late = $this->kb->a->note('The last note, and the flagged one', 'Body.', ['tagged'], 'A summary.');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/notes/'.$late->getId().'/flag', ['comment' => 'This one matters.']);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('GET', '/api/graph');
        self::assertSame(200, $this->httpStatus(), $this->body());
        $map = $this->jsonResponse();

        self::assertTrue($map['truncated']);
        self::assertCount(NoteGraph::MAP_NODE_CAP, $map['nodes']);
        self::assertGreaterThan(NoteGraph::MAP_NODE_CAP, $map['total_notes']);
        self::assertContains((int) $late->getId(), $this->nodeIds($map),
            'the cap dropped the one note the owner flagged — which is the only reason to open the map');
    }

    /** @param array<string, mixed> $graph @return array<string, mixed> */
    private function nodeById(array $graph, int $id): array
    {
        foreach ($graph['nodes'] as $node) {
            if ($node['id'] === $id) {
                return $node;
            }
        }

        self::fail("Note $id is not on the map");
    }
}
