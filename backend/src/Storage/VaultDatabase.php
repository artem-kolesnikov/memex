<?php

declare(strict_types=1);

namespace App\Storage;

use App\Service\SearchTerms;
use App\Service\StorageLimits;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Opening a vault: connection settings, sqlite-vec, the functions its
 * triggers call, and the schema migrations it has not had yet.
 */
final class VaultDatabase
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/schema/vault')]
        private readonly string $schemaDir,
        private readonly StorageLimits $limits,
    ) {
    }

    /**
     * Opens an existing file; a missing one is an error, never created here.
     */
    public function open(string $path): \SQLite3
    {
        $db = new \SQLite3($path, SQLITE3_OPEN_READWRITE);
        $this->prepare($db);

        return $db;
    }

    public function prepare(\SQLite3 $db): void
    {
        Sqlite::configure($db);
        Sqlite::loadVectors($db);
        $db->createFunction('memex_body_over_limit', fn (int $bytes): int => (int) $this->limits->bodyOverLimit($bytes), 1);
        $db->createFunction('memex_search_terms', static fn (?string $text): string => SearchTerms::index((string) $text), 1, SQLITE3_DETERMINISTIC);
        SchemaMigrator::migrate($db, $this->schemaDir);
    }

    public function schemaDir(): string
    {
        return $this->schemaDir;
    }
}
