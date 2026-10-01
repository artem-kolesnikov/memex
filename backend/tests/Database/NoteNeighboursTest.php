<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;
use App\Service\CurationQueue;
use App\Service\EmbeddingSpend;
use App\Service\NoteEnricher;
use App\Service\NoteLimbo;
use App\Service\NoteNeighbours;
use App\Tests\Support\Embeddings;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Duplicates are read from kept neighbour lists instead of measuring every
 * pair, so the lists have to say what measuring every pair would. Each case
 * checks them against that measurement, taken the old way on the same stored
 * vectors, after the writes that can leave a list wrong: new vectors beside
 * settled lists, vectors that move, notes that go.
 */
class NoteNeighboursTest extends ApiTestCase
{
    /** @var array<int, Note> seed => note */
    private array $notes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ml->vectorFor = static fn (string $text): array => preg_match('/vec(\d+)/', $text, $m) === 1
            ? self::vectorOf((int) $m[1])
            : self::vectorOf(0);
    }

    public function testNewNotesFindTheirPlaceInListsAlreadySettled(): void
    {
        $this->write(range(1, 20));
        $this->assertListsMatchEveryPair();

        $this->write(range(21, 40));
        $this->assertListsMatchEveryPair();
    }

    public function testAVectorThatMovesLeavesNoListItWasIn(): void
    {
        $this->write(range(1, 40));
        $this->assertListsMatchEveryPair();

        foreach ([3, 8, 11, 17, 26, 33] as $seed) {
            $this->move($seed, $seed + 2);
        }
        $this->assertListsMatchEveryPair();
    }

    public function testANoteThatGoesIsReplacedInEveryListItWasIn(): void
    {
        $this->write(range(1, 40));
        $this->assertListsMatchEveryPair();

        $limbo = self::getContainer()->get(NoteLimbo::class);
        foreach ([5, 10, 15, 20, 25] as $seed) {
            $limbo->retire($this->notes[$seed], 'test');
        }
        $this->assertListsMatchEveryPair();
    }

    /** Seven notes at distance zero from each other: which five a note keeps is decided by id, as the measurement decides it. */
    public function testTiesAreKeptByIdAsTheMeasurementKeepsThem(): void
    {
        $this->write([1, 2, 3]);
        for ($i = 0; $i < 7; ++$i) {
            $this->notes[100 + $i] = $this->kb->a->note("Twin $i", 'vec100');
        }
        $this->assertListsMatchEveryPair();

        // The oldest note becomes an eighth twin: in every full list it ties
        // with the fifth, and wins on id.
        $this->move(1, 100);
        $this->assertListsMatchEveryPair();
    }

    /**
     * A stored distance is the measured one to the last bit: a tie a stored
     * copy rounds apart is decided by the rounding, not by the id.
     */
    public function testAStoredDistanceIsTheMeasuredOneExactly(): void
    {
        $this->write(range(1, 40));
        foreach ([1 => 37, 2 => 38, 6 => 31] as $seed => $to) {
            $this->move($seed, $to);
        }
        $this->kb->a->enter();
        self::getContainer()->get(NoteNeighbours::class)->settle(PHP_INT_MAX);

        $rows = $this->em->getConnection()->fetchAssociative(
            'SELECT COUNT(*) AS kept, SUM(n.distance <> vec_distance_cosine(a.embedding, b.embedding)) AS rounded
             FROM note_neighbours n
             JOIN note_embedding_vectors a ON a.rowid = n.note_id
             JOIN note_embedding_vectors b ON b.rowid = n.neighbour_id',
        );
        self::assertGreaterThan(100, (int) $rows['kept']);
        self::assertSame(0, (int) $rows['rounded']);
    }

    public function testAReadSettlesNoMoreThanItsBudgetAndSaysWhatIsLeft(): void
    {
        $this->write(range(1, 12));
        $neighbours = self::getContainer()->get(NoteNeighbours::class);

        self::assertSame(11, $neighbours->settle(1));
        self::assertSame(9, $neighbours->settle(24));
        self::assertSame(0, $neighbours->settle(PHP_INT_MAX));
    }

    public function testTheSweepSettlesWhatTheWritesLeft(): void
    {
        $this->write(range(1, 12));
        $this->leave();

        $command = new CommandTester((new Application(self::$kernel))->find('app:embed'));
        $command->execute([]);

        self::assertStringContainsString('settled the neighbours of 12 notes, 0 left', $command->getDisplay());
        $this->kb->a->enter();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM note_neighbours_stale'));
    }

    /** @param list<int> $seeds */
    private function write(array $seeds): void
    {
        foreach ($seeds as $seed) {
            $this->notes[$seed] = $this->kb->a->note("Note $seed", "vec$seed");
        }
    }

    private function move(int $seed, int $to): void
    {
        $note = $this->notes[$seed];
        $this->kb->a->enter();
        $this->em->getConnection()->executeStatement('UPDATE notes SET body_md = :body WHERE id = :id', ['body' => "vec$to", 'id' => $note->getId()]);
        $this->em->refresh($note);
        self::assertTrue(self::getContainer()->get(NoteEnricher::class)->storeEmbedding($note, EmbeddingSpend::OwnerInitiated));
    }

    private function assertListsMatchEveryPair(): void
    {
        $this->kb->a->enter();
        $queue = self::getContainer()->get(CurationQueue::class);
        $horizon = Embeddings::model(self::getContainer())->neighbourHorizon();
        foreach (array_unique([...array_filter([0.05, 0.12, 0.3, 0.5], static fn (float $c): bool => $c < $horizon), $horizon]) as $cutoff) {
            $read = $queue->duplicates($cutoff, 1000, 0, true);
            self::assertSame(0, $read['unsettled']);
            $pairs = array_map(static fn (array $p): string => $p['left']['note_id'].'-'.$p['right']['note_id'], $read['pairs']);
            sort($pairs);
            $measured = $this->measured($cutoff);
            self::assertNotSame([], $measured, "no pair inside $cutoff: the fixture proves nothing there");
            self::assertSame($measured, $pairs, "at $cutoff");
        }
    }

    /**
     * Every pair of stored vectors measured, each note's five nearest inside
     * the cutoff, a pair kept when either note lists the other.
     *
     * @return list<string>
     */
    private function measured(float $cutoff): array
    {
        $rows = $this->em->getConnection()->fetchFirstColumn(
            'WITH vectors AS MATERIALIZED (
                 SELECT rowid AS note_id, embedding FROM note_embedding_vectors
             ), close AS MATERIALIZED (
                 SELECT a_id, b_id, dist FROM (
                     SELECT a.note_id AS a_id, b.note_id AS b_id, vec_distance_cosine(a.embedding, b.embedding) AS dist
                     FROM vectors a JOIN vectors b ON b.note_id > a.note_id
                 ) WHERE dist < CAST(:cutoff AS REAL)
             ), ranked AS (
                 SELECT from_id, to_id,
                        ROW_NUMBER() OVER (PARTITION BY from_id ORDER BY dist ASC, to_id ASC) AS nearest
                 FROM (SELECT a_id AS from_id, b_id AS to_id, dist FROM close UNION ALL SELECT b_id, a_id, dist FROM close)
             )
             SELECT DISTINCT MIN(from_id, to_id) || \'-\' || MAX(from_id, to_id) FROM ranked WHERE nearest <= '.NoteNeighbours::KEPT,
            ['cutoff' => $cutoff],
        );
        sort($rows);

        return $rows;
    }

    /**
     * Five clusters in twelve dimensions, each note offset from its
     * cluster's centre by its seed, so neighbours compete for the five places.
     *
     * @return list<float>
     */
    private static function vectorOf(int $seed): array
    {
        $vector = array_fill(0, 1536, 0.0);
        $cluster = $seed % 5;
        for ($d = 0; $d < 12; ++$d) {
            $vector[$d] = sin(($cluster + 1) * 7.1 + $d * 1.3) + 0.45 * sin($seed * 3.7 + $d * 2.9);
        }

        return $vector;
    }
}
