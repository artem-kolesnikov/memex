<?php

declare(strict_types=1);

namespace App\Storage;

use App\Service\EmbeddingModel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class VaultFiles
{
    /** @param list<string> $embeddingModels */
    public function __construct(
        private readonly DataDir $dataDir,
        private readonly VaultDatabase $database,
        #[Autowire('%memex.embedding_models%')]
        private readonly array $embeddingModels,
    ) {
    }

    /**
     * A new vault at the current schema, embedding with the edition's first
     * model. Returns its key.
     */
    public function create(): string
    {
        $dir = $this->dataDir->vaultsDir();
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the vaults directory.');
        }

        $key = VaultKey::generate();
        $path = $this->dataDir->vaultPath($key);
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Cannot create a vault.');
        }
        fclose($handle);
        chmod($path, 0o600);

        try {
            $db = $this->database->open($path);
            try {
                $this->embedWith($db, EmbeddingModel::from($this->embeddingModels[0]));
            } finally {
                $db->close();
            }
        } catch (\Throwable $e) {
            $this->delete($key);
            throw $e;
        }

        return $key;
    }

    private function embedWith(\SQLite3 $db, EmbeddingModel $model): void
    {
        if ($db->querySingle('SELECT embedding_model FROM settings') === $model->value) {
            return;
        }
        $db->exec('BEGIN IMMEDIATE');
        foreach (VectorTables::rebuild($model) as $statement) {
            $db->exec($statement);
        }
        $db->exec('COMMIT');
    }

    public function exists(string $key): bool
    {
        return is_file($this->dataDir->vaultPath($key));
    }

    public function delete(string $key): void
    {
        $path = $this->dataDir->vaultPath($key);
        foreach ([$path.'-wal', $path.'-shm', $path] as $file) {
            if (is_file($file) && !unlink($file)) {
                throw new \RuntimeException('Cannot delete a vault.');
            }
        }
    }
}
