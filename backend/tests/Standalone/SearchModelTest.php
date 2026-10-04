<?php

declare(strict_types=1);

namespace App\Tests\Standalone;

use App\Service\EmbeddingModel;
use App\Tests\Database\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * memex-local embeds on its own server and holds no OpenAI key of its
 * own: a vault starts on the local model, chooses OpenAI only with its own
 * key, and without that key is searched by its words alone.
 */
final class SearchModelTest extends ApiTestCase
{
    public function testANoteIsEmbeddedOnThisServerAndNothingIsBought(): void
    {
        $this->kb->a->note('Harbour charts', 'Tide tables for the north quay.');

        $body = $this->ml->lastBody('/create-embeddings');
        self::assertSame(EmbeddingModel::Local->value, $body['model'] ?? null);
        self::assertArrayNotHasKey('api_key', $body);
        $this->kb->a->enter();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM provider_spend WHERE surface = 'embedding'"));
    }

    public function testOpenAiIsChosenOnlyWithAKeyAndChoosingItEmbedsEveryNoteAgain(): void
    {
        $this->kb->a->note('Harbour charts', 'Tide tables for the north quay.');
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PUT', '/api/settings/ai/search-model', ['model' => EmbeddingModel::OpenAi->value]);
        self::assertSame(400, $this->httpStatus(), 'OpenAI was chosen with nothing to pay for it');

        $this->sessionRequest('POST', '/api/settings/ai/keys', ['provider' => 'openai', 'name' => 'OpenAI', 'api_key' => 'sk-a-valid-key', 'section' => 'embed']);
        self::assertSame(200, $this->httpStatus());
        $this->sessionRequest('PUT', '/api/settings/ai/search-model', ['model' => EmbeddingModel::OpenAi->value]);
        self::assertSame(200, $this->httpStatus());
        $search = $this->jsonResponse()['search'];
        self::assertSame(EmbeddingModel::OpenAi->value, $search['model']);
        self::assertSame(0, $search['embedded']);

        $this->sweep();

        self::assertSame('sk-a-valid-key', $this->ml->lastBody('/create-embeddings')['api_key'] ?? null);
        $this->sessionRequest('GET', '/api/settings/ai');
        $search = $this->jsonResponse()['search'];
        self::assertSame($search['notes'], $search['embedded']);
    }

    public function testAVaultOnOpenAiWithoutItsKeyIsPassedOverAndSaysSo(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/settings/ai/keys', ['provider' => 'openai', 'name' => 'OpenAI', 'api_key' => 'sk-a-valid-key', 'section' => 'embed']);
        $key = $this->jsonResponse()['keys'][0]['id'];
        $this->sessionRequest('PUT', '/api/settings/ai/search-model', ['model' => EmbeddingModel::OpenAi->value]);
        $this->sessionRequest('DELETE', '/api/settings/ai/keys/'.$key);
        $this->kb->a->note('Written with no key', 'Found by its words only.');

        $this->sessionRequest('GET', '/api/settings/ai');
        self::assertFalse($this->jsonResponse()['search']['ready']);

        $this->ml->calls = [];
        $output = $this->sweep();
        self::assertStringContainsString('has no key to embed with; skipped', $output);
        self::assertNull($this->ml->lastBody('/create-embeddings'), 'an embedding was bought on a key this server does not have');
    }

    private function sweep(): string
    {
        $this->leave();
        $command = new CommandTester((new Application(self::$kernel))->find('app:embed'));
        $command->execute([]);

        return $command->getDisplay();
    }
}
