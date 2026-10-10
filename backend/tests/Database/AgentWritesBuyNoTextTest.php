<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;
use App\Service\AiProviders;
use App\Service\EmbeddingSpend;
use App\Service\EnrichmentSettings;
use App\Service\NoteWriter;
use App\Service\ReviewVerdicts;
use App\Tests\Support\KbFixture;

/**
 * What an assistant writes, it describes itself: memex buys its embedding and
 * no text, whether the write applies at once, is approved later, or arrives
 * with no description at all. The owner's own saves keep descriptions and tags
 * where text is on.
 */
final class AgentWritesBuyNoTextTest extends DatabaseTestCase
{
    private const VERDICT = ['comment' => null, 'precedent' => false];

    private NoteWriter $writer;
    private KbFixture $kb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = self::getContainer()->get(NoteWriter::class);
        $this->kb = new KbFixture(self::getContainer());

        // Text is ON, so a call that is not made was refused by authorship and
        // not by a vault with no provider.
        $settings = self::getContainer()->get(EnrichmentSettings::class);
        $key = $settings->saveKey(AiProviders::OPENAI, 'OpenAI', 'sk-valid-key')['credential'];
        $settings->save(enabled: true, credential: $key);
        $this->ml->calls = [];
    }

    public function testTheOwnersSaveIsDescribedAndTagged(): void
    {
        $this->writer->create(null, 'An owner note', 'A body the owner typed.', Note::SOURCE_MANUAL, null, [], enrich: EmbeddingSpend::Metered);

        self::assertCount(1, $this->callsTo('summarize'));
        self::assertCount(1, $this->callsTo('suggest-tags'));
    }

    public function testAnAssistantsNewNoteBuysOnlyItsEmbedding(): void
    {
        $note = $this->writer->create($this->kb->agentToken, 'An agent note', 'A body an assistant wrote.', Note::SOURCE_AGENT, null, ['chosen'], enrich: EmbeddingSpend::Metered)['note'];

        $this->assertOnlyEmbedded();
        self::assertNull($note->getSummary(), 'memex described a note an assistant saved without a description');
    }

    public function testACuratorsAppliedEditBuysOnlyItsEmbedding(): void
    {
        $note = $this->kb->note($this->writer, 'A curated note', 'Body.');
        $this->ml->calls = [];

        $this->writer->propose($note, $this->kb->curatorToken, null, null, null, patch: [['find' => 'Body.', 'replace' => 'A rewritten body.']]);

        $this->assertOnlyEmbedded();
    }

    public function testApprovingAnAssistantsEditBuysOnlyItsEmbedding(): void
    {
        $note = $this->kb->note($this->writer, 'A held note', 'The original body.');
        $proposal = $this->writer->propose($note, $this->kb->agentToken, null, 'A rewritten body.', null);
        $this->ml->calls = [];

        self::getContainer()->get(ReviewVerdicts::class)->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));

        $this->assertOnlyEmbedded();
    }

    private function assertOnlyEmbedded(): void
    {
        self::assertNotSame([], $this->callsTo('create-embeddings'), 'Nothing was embedded — the test proves nothing');
        self::assertSame([], $this->callsTo('summarize'), 'An assistant\'s write bought a description');
        self::assertSame([], $this->callsTo('suggest-tags'), 'An assistant\'s write bought tag suggestions');
    }

    /** @return list<array<string, mixed>> */
    private function callsTo(string $endpoint): array
    {
        return array_values(array_filter($this->ml->calls, static fn (array $call) => str_contains($call['url'], $endpoint)));
    }
}
