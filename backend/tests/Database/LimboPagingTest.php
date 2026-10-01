<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;
use App\Service\NoteLimbo;
use App\Service\NoteWriter;
use App\Tests\Support\KbFixture;

/**
 * The listing must be able to reach every retired note.
 *
 * It was `LIMIT 200` with no offset and no total, which is a wall rather than a
 * limit: past two hundred rows a note the owner had every right to restore was
 * both invisible and unreachable, and nothing on the page said so.
 */
final class LimboPagingTest extends DatabaseTestCase
{
    private NoteWriter $writer;
    private NoteLimbo $limbo;
    private KbFixture $kb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = self::getContainer()->get(NoteWriter::class);
        $this->limbo = self::getContainer()->get(NoteLimbo::class);
        $this->kb = new KbFixture(self::getContainer());
    }

    /** @return string[] titles, newest deletion first */
    private function retire(int $count): array
    {
        $titles = [];
        for ($i = 1; $i <= $count; ++$i) {
            $title = sprintf('Retired note %02d', $i);
            $note = $this->kb->note($this->writer, $title, 'Body.');
            $this->limbo->retire($note, Note::ACTOR_HUMAN, null);
            $titles[] = $title;
        }

        // `deleted_at` has second resolution, so within one test every row can
        // share a timestamp; the listing's tiebreak is `id DESC`, which is the
        // reverse of creation.
        return array_reverse($titles);
    }

    public function testEveryRetiredNoteIsReachableThroughSomePage(): void
    {
        $expected = $this->retire(25);

        $seen = [];
        $perPage = 10;
        $pages = (int) ceil($this->limbo->count() / $perPage);
        for ($page = 1; $page <= $pages; ++$page) {
            foreach ($this->limbo->list(false, $perPage, ($page - 1) * $perPage) as $row) {
                $seen[] = $row['title'];
            }
        }

        self::assertSame($expected, $seen);
    }

    public function testAPageAfterTheFirstIsNotTheFirstPageAgain(): void
    {
        $this->retire(25);

        $first = $this->limbo->list(false, 10, 0);
        $second = $this->limbo->list(false, 10, 10);

        self::assertCount(10, $first);
        self::assertCount(10, $second);
        self::assertSame(
            [],
            array_intersect(array_column($first, 'note_id'), array_column($second, 'note_id')),
            'the second page repeats rows from the first',
        );
    }

    public function testTheLastPageIsShorterRatherThanPadded(): void
    {
        $this->retire(25);

        $last = $this->limbo->list(false, 10, 20);

        self::assertCount(5, $last);
    }

    public function testCountAgreesWithWhatTheListingCanReach(): void
    {
        $this->retire(7);

        self::assertSame(7, $this->limbo->count());
        self::assertCount(7, $this->limbo->list(false, 50, 0));
    }

    public function testTombstonesAreCountedOnlyWhenTheyAreAskedFor(): void
    {
        $titles = $this->retire(4);
        $purged = $this->limbo->list(false, 50, 0)[0];
        self::assertTrue($this->limbo->purge((int) $purged['note_id']));

        self::assertSame(3, $this->limbo->count(), 'a tombstone is counted as restorable');
        self::assertSame(4, $this->limbo->count(true));
        self::assertCount(3, $this->limbo->list(false, 50, 0));
        self::assertCount(4, $this->limbo->list(true, 50, 0));
        self::assertNotEmpty($titles);
    }

    public function testSearchNarrowsThePageAndItsTotalTogether(): void
    {
        foreach (['Kettle descaling schedule', 'Kettle spare parts', 'Tax return 2024'] as $title) {
            $note = $this->kb->note($this->writer, $title, 'Body.');
            $this->limbo->retire($note, Note::ACTOR_HUMAN, null);
        }

        self::assertSame(2, $this->limbo->count(false, 'kettle'), 'the total ignores the search');
        self::assertCount(2, $this->limbo->list(false, 50, 0, 'kettle'));
        self::assertCount(1, $this->limbo->list(false, 1, 0, 'kettle'), 'a search page is not paged');
        self::assertCount(1, $this->limbo->list(false, 1, 1, 'kettle'));
    }

    public function testSearchAlsoReadsTheDeletionReason(): void
    {
        $note = $this->kb->note($this->writer, 'Innocuous title', 'Body.');
        $this->limbo->retire($note, Note::ACTOR_HUMAN, 'Rejected from the review inbox');

        self::assertSame(1, $this->limbo->count(false, 'review inbox'));
    }

    /** A wildcard typed into the box is a character, not a query. */
    public function testSearchTreatsWildcardsAsText(): void
    {
        $note = $this->kb->note($this->writer, 'Plain title', 'Body.');
        $this->limbo->retire($note, Note::ACTOR_HUMAN, null);

        self::assertSame(0, $this->limbo->count(false, '%'));
        self::assertSame(0, $this->limbo->count(false, '_lain'));
    }

    /** A per-page nobody can raise past the ceiling, whatever the query string says. */
    public function testPerPageIsBounded(): void
    {
        $this->retire(3);

        self::assertCount(1, $this->limbo->list(false, 0, 0));
        self::assertCount(3, $this->limbo->list(false, 10_000, 0));
    }
}
