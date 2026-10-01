<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\CuratorLogEntry;
use App\Entity\EditProposal;
use App\Service\NoteWriter;

/**
 * Filtering the Activity Journal (operator, 2026-08-23: "add filters by action
 * and by agent, add search").
 *
 * All three filters are applied in SQL, before the row limit, because the
 * endpoint has always returned a capped page. Filtering a page in the browser
 * would answer "which of the most recent 200 rows match" — a different
 * question, and one whose answer changes as the log grows underneath it.
 *
 * The writer filter is by TOKEN, not by the name on the screen. That is the
 * same rule the byline itself follows (App\Service\ActorView, 2026-08-23):
 * renaming a connection must not split its history into a before and an after,
 * and two connections may perfectly well be called the same thing.
 */
final class ActivityFiltersTest extends ApiTestCase
{
    private NoteWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = self::getContainer()->get(NoteWriter::class);
    }

    public function testFilteringByActionReturnsOnlyThatAction(): void
    {
        $this->seed();
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/curator-log?action='.CuratorLogEntry::ACTION_EDIT);
        self::assertSame(200, $this->httpStatus());
        $entries = $this->jsonResponse()['entries'];

        self::assertNotEmpty($entries);
        self::assertSame(
            [CuratorLogEntry::ACTION_EDIT],
            array_values(array_unique(array_column($entries, 'action'))),
        );
    }

    public function testFilteringByWriterFollowsTheTokenThroughARename(): void
    {
        $this->seed();
        $token = $this->kb->a->curatorToken();
        $tokenId = $token->getId();

        // The rename happens between the two writes, so the log holds rows
        // recorded under both names. Filtering by the connection must return
        // all of them: the operator is asking "what did this assistant do",
        // not "what did it do while it was called that".
        $token->setDisplayName('Renamed curator');
        $this->em->flush();
        $this->writeEdit($this->kb->a->note('Later note', 'Later body.'), 'After the rename');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/curator-log?writer=token:'.$tokenId);
        $entries = $this->jsonResponse()['entries'];

        self::assertGreaterThanOrEqual(2, count($entries));
        self::assertSame(
            ['Renamed curator'],
            array_values(array_unique(array_column($entries, 'by'))),
            'Every row of that connection, under the name it goes by now',
        );
    }

    public function testSearchMatchesTheWordsARowShows(): void
    {
        $this->kb->a->note('Findable note', 'Body.');
        $this->writeEdit($this->kb->a->note('Haystack', 'Body.'), 'A distinctive phrase');
        $this->writeEdit($this->kb->a->note('Other', 'Body.'), 'Something else entirely');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/curator-log?q='.urlencode('DISTINCTIVE'));
        $entries = $this->jsonResponse()['entries'];

        self::assertCount(1, $entries, 'Case is not part of the question a search box asks');
        self::assertStringContainsString('distinctive phrase', $entries[0]['description']);
    }

    /**
     * A search for `note_id` must not match `noteXid`. LIKE reads `_` as
     * "any character", so a search box that passes its text straight
     * through quietly returns rows nobody asked for.
     */
    public function testSearchTreatsWildcardCharactersAsText(): void
    {
        $this->writeEdit($this->kb->a->note('Underscore', 'Body.'), 'mentions note_id here');
        $this->writeEdit($this->kb->a->note('NotIt', 'Body.'), 'mentions noteXid here');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/curator-log?q='.urlencode('note_id'));
        $entries = $this->jsonResponse()['entries'];

        self::assertCount(1, $entries);
        self::assertStringContainsString('note_id', $entries[0]['description']);
    }

    public function testTheFilterMenusOfferOnlyWhatTheLogHolds(): void
    {
        $this->seed();
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/curator-log');
        $filters = $this->jsonResponse()['filters'];

        $actions = array_column($filters['actions'], 'value');
        self::assertContains(CuratorLogEntry::ACTION_EDIT, $actions);
        self::assertNotContains(
            CuratorLogEntry::ACTION_REJECTED,
            $actions,
            'An action nobody has taken is a menu entry that returns nothing',
        );

        self::assertSame(
            ['token:'.$this->kb->a->curatorToken()->getId()],
            array_column($filters['writers'], 'value'),
        );
    }

    public function testAnotherTeamsRowsAreNeitherListedNorOfferedAsAFilter(): void
    {
        $this->writeEdit($this->kb->b->note('Theirs', 'Body.'), 'Team B activity', tenant: 'b');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/curator-log');
        $answer = $this->jsonResponse();

        self::assertSame([], $answer['entries']);
        self::assertSame([], $answer['filters']['actions']);
        self::assertSame([], $answer['filters']['writers']);
        self::assertStringNotContainsString('Team B activity', $this->body());
    }

    /** Two edit rows and a create row, written by A's curator. */
    private function seed(): void
    {
        $this->writeEdit($this->kb->a->note('First', 'First body.'), 'First change');
        $this->writeEdit($this->kb->a->note('Second', 'Second body.'), 'Second change');
    }

    /** A curator-role edit, which applies at once and writes an `edit` row. */
    private function writeEdit(\App\Entity\Note $note, string $comment, string $tenant = 'a'): void
    {
        $side = 'a' === $tenant ? $this->kb->a : $this->kb->b;
        $this->writer->propose(
            $note,
            $side->curatorToken(),
            null,
            null,
            null,
            $comment,
            summary: $comment,
        );
    }
}
