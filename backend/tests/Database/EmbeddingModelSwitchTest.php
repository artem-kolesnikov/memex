<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\AccountLimits;
use App\Service\EmbeddingModel;
use App\Service\EmbeddingSpace;
use App\Service\EnrichmentSettings;
use App\Tests\Support\Embeddings;
use App\Tests\Support\MockMlResponder;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A vault's vectors are one model's. A new vault takes the edition's first
 * model; changing it empties every vector and the sweep embeds each note again
 * at the new model's size, and a vector bought before the change is never
 * stored after it.
 */
final class EmbeddingModelSwitchTest extends ApiTestCase
{
    public function testANewVaultEmbedsWithTheEditionsFirstModel(): void
    {
        $this->kb->a->note('A first note', 'Something to find.');

        $this->kb->a->enter();
        self::assertSame(Embeddings::model(self::getContainer()), $this->space()->model());
        self::assertSame(Embeddings::model(self::getContainer())->value, $this->ml->lastBody('/create-embeddings')['model'] ?? null);
        self::assertSame(Embeddings::model(self::getContainer())->dimensions(), $this->width());
    }

    public function testSwitchingEmptiesEveryVectorAndTheSweepEmbedsEachNoteAgainAtTheNewSize(): void
    {
        $this->onOpenAiWithAKey();
        if (self::getContainer()->get(AccountLimits::class)->embeddingModel() !== null) {
            self::markTestSkipped('This edition names each vault\'s model; the sweep keeps it there.');
        }
        $this->kb->a->note('Harbour charts', 'Tide tables for the north quay.');
        $this->kb->a->note('Quay lights', 'Who lights the lamps, and when.');
        $this->kb->a->enter();
        $notes = $this->rows('notes');
        self::assertSame(['embedded' => $notes, 'notes' => $notes], $this->space()->progress());
        self::assertSame(EmbeddingModel::OpenAi->dimensions(), $this->width());
        $bought = $this->rows("provider_spend WHERE surface = 'embedding'");

        $this->space()->switchTo(EmbeddingModel::Local);

        self::assertSame(0, $this->rows('note_embeddings'));
        self::assertSame(0, $this->rows('note_embedding_chunks'));
        self::assertSame(0, $this->rows('note_neighbours'));
        self::assertSame(['embedded' => 0, 'notes' => $notes], $this->space()->progress());

        $this->leave();
        $command = new CommandTester((new Application(self::$kernel))->find('app:embed'));
        $command->execute([]);

        $this->kb->a->enter();
        self::assertSame(['embedded' => $notes, 'notes' => $notes], $this->space()->progress());
        self::assertSame(EmbeddingModel::Local->dimensions(), $this->width());
        $body = $this->ml->lastBody('/create-embeddings');
        self::assertSame(EmbeddingModel::Local->value, $body['model'] ?? null);
        self::assertSame('document', $body['purpose'] ?? null);
        self::assertArrayNotHasKey('api_key', $body, 'a key travelled with a vector nobody sells');
        self::assertSame($bought, $this->rows("provider_spend WHERE surface = 'embedding'"), 'a local vector was written down as bought');
    }

    public function testAVectorBoughtBeforeASwitchIsNotStoredAfterIt(): void
    {
        $this->onOpenAiWithAKey();
        $space = $this->space();
        $this->ml->vectorFor = function (string $text) use ($space): array {
            $this->ml->vectorFor = null;
            $space->switchTo(EmbeddingModel::Local);

            return MockMlResponder::vector();
        };

        $this->kb->a->note('Written across a switch', 'The model changes while this is embedded.');

        $this->kb->a->enter();
        self::assertSame(EmbeddingModel::Local, $this->space()->model());
        self::assertSame(0, $this->rows('note_embeddings'), 'an OpenAI vector was stored in a vault that had moved to the local model');
        self::assertSame(EmbeddingModel::Local->dimensions(), $this->width());
    }

    public function testASearchIsEmbeddedAsAQueryInTheVaultsModel(): void
    {
        $this->kb->a->note('Harbour charts', 'Tide tables for the north quay.');
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/notes?q='.rawurlencode('when the sea comes in'));

        self::assertSame(200, $this->httpStatus());
        $body = $this->ml->lastBody('/create-embeddings');
        self::assertSame('query', $body['purpose'] ?? null);
        self::assertSame(Embeddings::model(self::getContainer())->value, $body['model'] ?? null);
    }

    public function testSettingsReportTheModelTheChoicesAndHowFarTheNotesAreEmbedded(): void
    {
        $this->kb->a->note('One', 'First.');
        $this->kb->a->note('Two', 'Second.');
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/settings/ai');

        $search = $this->jsonResponse()['search'];
        self::assertSame(Embeddings::model(self::getContainer())->value, $search['model']);
        self::assertSame(self::getContainer()->getParameter('memex.embedding_models'), $search['models']);
        self::assertTrue($search['ready']);
        self::assertGreaterThanOrEqual(2, $search['notes']);
        self::assertSame($search['notes'], $search['embedded']);
    }

    public function testAModelThisServerDoesNotOfferIsRefusedAndNothingIsEmptied(): void
    {
        $this->kb->a->note('Kept', 'Its vector stays.');
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PUT', '/api/settings/ai/search-model', ['model' => 'text-embedding-3-small']);

        self::assertSame(400, $this->httpStatus());
        $this->kb->a->enter();
        self::assertSame($this->rows('notes'), $this->rows('note_embeddings'));
    }

    private function space(): EmbeddingSpace
    {
        return self::getContainer()->get(EmbeddingSpace::class);
    }

    /** OpenAI's model on the vault's own key, which runs in either edition. */
    private function onOpenAiWithAKey(): void
    {
        $this->kb->a->enter();
        $saved = self::getContainer()->get(EnrichmentSettings::class)->saveKey('openai', 'OpenAI', 'sk-a-valid-key', EnrichmentSettings::SECTION_EMBED);
        self::assertTrue($saved['ok'], (string) $saved['error']);
        $this->space()->switchTo(EmbeddingModel::OpenAi);
    }

    private function rows(string $from): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$from);
    }

    /** The width the vault's vector tables were made for. */
    private function width(): int
    {
        $tables = $this->em->getConnection()->fetchFirstColumn(
            "SELECT sql FROM sqlite_master WHERE name IN ('note_embedding_vectors', 'note_embedding_chunk_vectors')"
        );
        $widths = array_unique(array_map(static fn (string $sql): int => preg_match('/float\[(\d+)\]/', $sql, $m) === 1 ? (int) $m[1] : 0, $tables));
        self::assertCount(1, $widths, 'the two vector tables disagree on their width');

        return $widths[0];
    }
}
