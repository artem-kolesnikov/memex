<?php

declare(strict_types=1);

namespace App\Service;

use App\Command\EmbedCommand;
use App\Storage\VectorTables;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The bound vault's embedding model: which it is, which this server offers,
 * whether it can run, and changing it.
 */
final class EmbeddingSpace
{
    /** @var list<EmbeddingModel> */
    private readonly array $offered;

    /** @param list<string> $offered */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EnrichmentSettings $settings,
        #[Autowire('%memex.embedding_models%')]
        array $offered,
        #[Autowire('%memex.embedding_server_key%')]
        private readonly bool $serverKey,
    ) {
        $this->offered = array_map(EmbeddingModel::from(...), $offered);
    }

    public function model(): EmbeddingModel
    {
        return $this->settings->load()->getEmbeddingModel();
    }

    /** @return list<EmbeddingModel> */
    public function offered(): array
    {
        return $this->offered;
    }

    /** False when the model, the vault's unless named, is OpenAI's and there is no key to buy with. */
    public function canEmbed(?EmbeddingModel $model = null): bool
    {
        return ($model ?? $this->model())->isLocal() || $this->serverKey || $this->settings->embeddingCredentials()->isOwn();
    }

    /**
     * Every vector goes, and the tables are made again at the new model's size;
     * the embedding sweep then embeds every note with it. Writes only: nothing
     * here buys an embedding.
     */
    public function switchTo(EmbeddingModel $model): void
    {
        $conn = $this->em->getConnection();
        $conn->transactional(static function () use ($conn, $model): void {
            foreach (VectorTables::rebuild($model) as $statement) {
                $conn->executeStatement($statement);
            }
        });
        $this->em->refresh($this->settings->load());
        $this->settings->forget();
    }

    /** @return array{embedded: int, notes: int} */
    public function progress(): array
    {
        $conn = $this->em->getConnection();
        $notes = (int) $conn->fetchOne('SELECT COUNT(*) FROM notes');
        $owed = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM notes n LEFT JOIN note_embeddings ne ON ne.note_id = n.id WHERE '.EmbedCommand::STALE_SQL
        );

        return ['embedded' => $notes - $owed, 'notes' => $notes];
    }
}
