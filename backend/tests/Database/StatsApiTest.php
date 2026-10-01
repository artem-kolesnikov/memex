<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * The counts behind Settings → Data.
 *
 * Two things are worth a test rather than a glance. The numbers are one
 * vault's, and a stats endpoint that read another would leak the size and shape
 * of another tenant's knowledge base even though it returns no content. And
 * "waiting to be indexed" has to be derived from this vault's own two counts,
 * because that number is what tells someone their search is incomplete rather
 * than broken.
 */
final class StatsApiTest extends ApiTestCase
{
    public function testAnEmptyKnowledgeBaseCountsZeroRatherThanFailing(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/stats');

        self::assertSame(200, $this->httpStatus());
        $stats = $this->jsonResponse();
        self::assertSame(0, $stats['notes']['total']);
        self::assertSame(0, $stats['limbo']['restorable']);
        self::assertSame(0, $stats['indexing']['waiting']);
    }

    public function testTheCountsDescribeThisTeamAndNotTheOther(): void
    {
        $this->kb->a->note('A first note');
        $this->kb->a->note('A second note');
        $this->kb->b->note('B has one note');
        $this->kb->b->note('B has another');
        $this->kb->b->note('B has a third');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/stats');

        self::assertSame(2, $this->jsonResponse()['notes']['total'], "A must not be counting B's notes");
    }

    public function testAnUndescribedNoteIsCountedAsOne(): void
    {
        // Server-side enrichment is off for a new vault, so a note written with
        // no summary stays without one. That count is the backlog the user is
        // told to hand to an assistant.
        $this->kb->a->note('Nobody described this');
        $this->kb->a->note('This one has a summary', summary: 'What it is about.');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/stats');

        self::assertSame(1, $this->jsonResponse()['notes']['undescribed']);
    }

    public function testIndexingCountsComeFromThisTeamsOwnEmbeddings(): void
    {
        $this->kb->a->note('An indexed note');
        $this->kb->b->note('Another team, also indexed');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/stats');

        $indexing = $this->jsonResponse()['indexing'];
        self::assertSame(1, $indexing['embedded']);
        self::assertSame(0, $indexing['waiting']);
    }

    public function testStatsRefuseABearerToken(): void
    {
        // Same rule as the rest of Settings: an assistant has no business
        // reading how big its user's knowledge base is.
        $this->request('GET', '/api/stats', $this->kb->a->curatorBearer);

        self::assertSame(403, $this->httpStatus());
    }
}
