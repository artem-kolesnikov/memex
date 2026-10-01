<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\AiCredentials;
use App\Service\MlClient;
use App\Tests\Support\ServiceHealthRecorder;

/**
 * Audit B-2: an ml-processor outage used to be indistinguishable from empty
 * data, and neither the code nor this suite could tell them apart.
 *
 * {@see MlClient} catches `\Throwable` in every method and returns null, so
 * that a slow or dead ml-processor degrades the feature instead of breaking
 * the request. **That rule is right and these tests hold it in place.** What
 * was missing is that the null said nothing: a note with no summary because
 * the provider was down looked exactly like a note with no summary because
 * nobody asked for one, and the difference was invisible in the log, on the
 * screen and in the enrichment backlog.
 *
 * The suite could not see it either, which is the more interesting half. Every
 * stub in MockMlResponder answered 201 forever, so no test had ever exercised
 * the catch blocks that make up the class's actual failure behaviour. The
 * `failWith` switch added for this file is the first way to say "and now the
 * service is down" — and that is the shape of the whole audit: the invariants
 * nothing asserted were the ones that mattered.
 *
 * Two properties, and both have to hold at once:
 *
 *  1. the caller still gets its harmless null (no request breaks)
 *  2. somebody can now find out (the failure is reported, {@see \App\Service\ServiceHealth})
 *
 * Plus the boundary that keeps the alerter honest: things that are NOT
 * outages — text generation switched off, a legitimately empty answer — must
 * record nothing at all, because an alerter that fires on ordinary data stops
 * being read.
 */
class MlOutageTest extends DatabaseTestCase
{
    private MlClient $ml_client;
    private ServiceHealthRecorder $health;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ml_client = self::getContainer()->get(MlClient::class);
        $this->health = self::getContainer()->get(ServiceHealthRecorder::class);
    }

    public function testAnUnreachableServiceIsRecordedAndTheCallerStillGetsNull(): void
    {
        $this->ml->failWith = ['/summarize' => 'throw'];

        $summary = $this->ml_client->summarize('anything', new AiCredentials(textEnabled: true));

        self::assertNull($summary, 'The never-break-the-request contract is unchanged');
        self::assertSame(
            [MlClient::SERVICE_KEY],
            $this->openKeys(),
            'And the outage is now something a person can find out about'
        );
    }

    public function testAnErrorStatusIsRecorded(): void
    {
        $this->ml->failWith = ['/summarize' => 502];

        self::assertNull($this->ml_client->summarize('anything', new AiCredentials(textEnabled: true)));
        self::assertSame([MlClient::SERVICE_KEY], $this->openKeys());
        self::assertStringContainsString('HTTP 502', $this->failure()['detail']);
    }

    public function testEveryTextMethodReportsThroughTheSameProblem(): void
    {
        // One dead service is one problem, however many of its endpoints the
        // request happened to touch. Keying each endpoint separately would
        // send three emails about one outage — which is the dedup rule
        // defeated by an implementation detail rather than by a decision.
        $this->ml->failWith = ['/api/v1/' => 'throw'];
        $creds = new AiCredentials(textEnabled: true);

        $this->ml_client->summarize('a', $creds);
        $this->ml_client->suggestTitle('a', $creds);
        $this->ml_client->suggestTags('t', 'a', [], $creds);
        $this->ml_client->embedContent('a');

        self::assertCount(1, $this->health->failing);
        self::assertSame(4, $this->failure()['occurrences']);
    }

    /**
     * The one case where a 2xx counts as the service being broken. The vector's
     * dimension is a contract rather than a judgement: 1536 or the row cannot
     * be stored and cannot be compared to anything already stored. Returning
     * null quietly here is how an embedding sweep reports SUCCESS having
     * embedded nothing, which is the neighbouring open finding M-11.
     */
    public function testAVectorOfTheWrongLengthIsTheServiceBeingBroken(): void
    {
        $this->ml->failWith = [];
        $this->ml->shortVector = true;

        self::assertNull($this->ml_client->embedContent('anything'));
        self::assertSame([MlClient::SERVICE_KEY], $this->openKeys());
        self::assertStringContainsString('1536', $this->failure()['detail']);
    }

    public function testTextGenerationBeingSwitchedOffIsNotAnOutage(): void
    {
        // A knowledge base with server-side AI off is the DEFAULT, and it is
        // the shape of every account that has not opted in. If that opened an
        // incident, the health screen would show a permanent fault on a box
        // where nothing is wrong, and the alerter would be worthless on day one.
        $summary = $this->ml_client->summarize('anything', new AiCredentials(textEnabled: false));

        self::assertNull($summary);
        self::assertSame([], $this->health->failing);
        self::assertSame(0, $this->ml->callCount('/summarize'), 'And nothing was bought');
    }

    public function testAnEmptyAnswerIsDataRatherThanAFault(): void
    {
        $this->ml->emptySummary = true;

        self::assertNull($this->ml_client->summarize('anything', new AiCredentials(textEnabled: true)));
        self::assertSame([], $this->health->failing, 'A 2xx with nothing useful in it is an answer, not an outage');
    }

    public function testTheServiceComingBackEndsTheFailure(): void
    {
        $this->ml->failWith = ['/summarize' => 'throw'];
        $this->ml_client->summarize('anything', new AiCredentials(textEnabled: true));
        self::assertCount(1, $this->health->failing);

        $this->ml->failWith = [];
        $summary = $this->ml_client->summarize('anything', new AiCredentials(textEnabled: true));

        self::assertNotNull($summary);
        self::assertSame([], $this->health->failing, 'Recovery needs no intervention');
    }

    /** @return list<string> */
    private function openKeys(): array
    {
        return array_keys($this->health->failing);
    }

    /** @return array{detail: string, occurrences: int} */
    private function failure(): array
    {
        $open = $this->health->failing;
        self::assertNotEmpty($open, 'Expected a reported failure');

        return array_values($open)[0];
    }
}
