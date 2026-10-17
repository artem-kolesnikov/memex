<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Tests\Support\Embeddings;
use App\Tests\Support\MockMlResponder;

/**
 * A search lists the notes holding every word, and a note without them only
 * when it is nearer in meaning than every one of them, ahead of them.
 *
 * Distances are content-addressed and set as fractions of the vault model's
 * own cutoff, so the same fixture holds for every edition's model: `zznear`,
 * `zzmid` and `zzfar` sit inside the cutoff at a quarter, half and three
 * quarters of it, and the queries sit at zero.
 */
final class CombinedSearchTest extends ApiTestCase
{
    private const QUERIES = ['health insurance', 'webdesk', 'veterinarian'];

    protected function setUp(): void
    {
        parent::setUp();
        $cutoff = Embeddings::model(static::getContainer())->searchDistance();
        $this->ml->vectorFor = static fn (string $content): array => match (true) {
            in_array($content, self::QUERIES, true) => MockMlResponder::spread(0.0),
            str_contains($content, 'zznear') => MockMlResponder::spread($cutoff * 0.25),
            str_contains($content, 'zzmid') => MockMlResponder::spread($cutoff * 0.5),
            str_contains($content, 'zzfar') => MockMlResponder::spread($cutoff * 0.75),
            default => MockMlResponder::spread(1.0),
        };
    }

    /** @return array<string, mixed> */
    private function search(string $query): array
    {
        $this->request('GET', '/api/notes?q='.urlencode($query), $this->kb->a->agentBearer);
        self::assertSame(200, $this->httpStatus(), $this->body());

        return $this->jsonResponse();
    }

    /** @return list<string> */
    private static function titles(array $result): array
    {
        return array_column($result['items'], 'title');
    }

    public function testANoteNearerThanEveryLiteralMatchLeadsThem(): void
    {
        $this->kb->a->note('Medical — providers and plans', 'Doctors, dentists and what the plan covers. zznear');
        $this->kb->a->note('Course module seven', 'Governance, and health insurance as one example. zzfar');
        $this->kb->a->note('Course module four', 'Risk, with health insurance claims as a case. zzmid');

        $result = $this->search('health insurance');

        self::assertSame('Medical — providers and plans', self::titles($result)[0] ?? null);
        self::assertEqualsCanonicalizing(
            ['Medical — providers and plans', 'Course module seven', 'Course module four'],
            self::titles($result),
        );
        self::assertSame(3, $result['total']);
    }

    public function testANoteFartherThanTheNearestLiteralMatchStaysOut(): void
    {
        $this->kb->a->note('Webdesk architecture', 'The webdesk is the operator console. zznear');
        $this->kb->a->note('Publishing assistant', 'Drafts posts for the web team. zzmid');

        $result = $this->search('webdesk');

        self::assertSame(['Webdesk architecture'], self::titles($result));
        self::assertSame(1, $result['total']);
    }

    public function testWithNoLiteralMatchMeaningAnswersAlone(): void
    {
        $this->kb->a->note('Pets', 'Two cats and their medicine. zzmid');
        $this->kb->a->note('Hardware', 'Every machine in the house.');

        self::assertSame(['Pets'], self::titles($this->search('veterinarian')));
    }

    /**
     * A literal match not yet embedded cannot be compared, so it wins as a
     * literal match always did: nothing nearer is let in ahead of it.
     */
    public function testALiteralMatchWithNoVectorYetKeepsMeaningOut(): void
    {
        $plan = $this->kb->a->note('Webdesk plan', 'Where the webdesk goes next. zzfar');
        $this->kb->a->note('Publishing assistant', 'Drafts posts for the web team. zznear');
        $this->in($this->kb->a);
        $this->em->getConnection()->executeStatement('DELETE FROM note_embedding_chunks WHERE note_id = :id', ['id' => $plan->getId()]);

        self::assertSame(['Webdesk plan'], self::titles($this->search('webdesk')));
    }

    /** The map dims what the list leaves out, so both read the same rule. */
    public function testTheMapMatchesWhatTheListShows(): void
    {
        $medical = $this->kb->a->note('Medical — providers and plans', 'Doctors and the plan. zznear');
        $module = $this->kb->a->note('Course module four', 'Risk, with health insurance claims as a case. zzmid');
        $this->kb->a->note('Course module five', 'Nothing on the subject. zzfar');

        $this->request('GET', '/api/notes/ids?q='.urlencode('health insurance'), $this->kb->a->agentBearer);
        self::assertSame(200, $this->httpStatus(), $this->body());

        self::assertEqualsCanonicalizing([$medical->getId(), $module->getId()], $this->jsonResponse()['ids']);
    }

    public function testOnlyALiteralMatchCarriesAPassage(): void
    {
        $this->kb->a->note('Medical — providers and plans', 'Doctors, dentists and what the insurance plan covers. zznear');
        $this->kb->a->note('Course module four', 'Risk, with health insurance claims as a case. zzmid');

        $this->request('POST', '/mcp', $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'search', 'arguments' => ['query' => 'health insurance']],
        ]);
        $items = json_decode($this->jsonResponse()['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR)['items'];
        $byTitle = array_column($items, null, 'title');

        self::assertArrayNotHasKey('matched_text', $byTitle['Medical — providers and plans']);
        self::assertStringContainsString('health insurance', $byTitle['Course module four']['matched_text'] ?? '');
    }
}
