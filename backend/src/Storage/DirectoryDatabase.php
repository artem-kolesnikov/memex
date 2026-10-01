<?php

declare(strict_types=1);

namespace App\Storage;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Opening the directory, the one file every vault shares. It is created on
 * first open, so a new box needs nothing but the data directory. The edition's
 * own tables, when it has any, migrate after the core's as their own stream.
 */
final class DirectoryDatabase
{
    public function __construct(
        private readonly DataDir $dataDir,
        #[Autowire('%kernel.project_dir%/schema/directory')]
        private readonly string $schemaDir,
        #[Autowire('%kernel.project_dir%/schema/edition/directory')]
        private readonly string $editionSchemaDir,
    ) {
    }

    public function open(): \SQLite3
    {
        $path = $this->dataDir->directoryPath();
        if (!is_dir(\dirname($path)) && !@mkdir(\dirname($path), 0o700, true) && !is_dir(\dirname($path))) {
            throw new \RuntimeException('Cannot create the data directory.');
        }
        if (!is_file($path) && ($handle = @fopen($path, 'x')) !== false) {
            fclose($handle);
            chmod($path, 0o600);
        }
        $db = new \SQLite3($path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        Sqlite::configure($db);
        SchemaMigrator::migrate($db, $this->schemaDir);
        if (is_dir($this->editionSchemaDir)) {
            SchemaMigrator::migrate($db, $this->editionSchemaDir, 'edition');
        }

        return $db;
    }

    public function schemaDir(): string
    {
        return $this->schemaDir;
    }
}
