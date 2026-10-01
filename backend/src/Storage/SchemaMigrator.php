<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Brings a SQLite file up to the schema this code knows, when the file is
 * opened. The file's version is `PRAGMA user_version`; migration N is the file
 * `NNNN-name.sql` in the schema directory, applied in one transaction with the
 * version bump, so a file is either at the old version or the new one.
 *
 * A second schema directory can migrate the same file as a named stream, whose
 * version is a row in `schema_streams`: the edition's own tables, numbered on
 * their own so that neither edition's migrations shift the other's.
 */
final class SchemaMigrator
{
    /** @var array<string, array<int, string>> */
    private static array $scanned = [];

    /**
     * @return array<int, string> version => path, contiguous from 1
     */
    public static function migrations(string $dir): array
    {
        return self::$scanned[$dir] ??= self::scan($dir);
    }

    public static function latest(string $dir): int
    {
        return \count(self::migrations($dir));
    }

    public static function version(\SQLite3 $db, ?string $stream = null): int
    {
        if ($stream === null) {
            return (int) $db->querySingle('PRAGMA user_version');
        }
        if ($db->querySingle("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'schema_streams'") === null) {
            return 0;
        }
        $statement = $db->prepare('SELECT version FROM schema_streams WHERE name = :name');
        $statement->bindValue(':name', $stream);
        $row = $statement->execute()->fetchArray(SQLITE3_NUM);

        return $row === false ? 0 : (int) $row[0];
    }

    public static function migrate(\SQLite3 $db, string $dir, ?string $stream = null): void
    {
        $migrations = self::migrations($dir);
        $latest = \count($migrations);
        if (self::checked($db, $latest, $stream) === $latest) {
            return;
        }

        $db->exec('BEGIN IMMEDIATE');
        try {
            for ($version = self::checked($db, $latest, $stream) + 1; $version <= $latest; ++$version) {
                $db->exec((string) file_get_contents($migrations[$version]));
            }
            if ($stream === null) {
                $db->exec('PRAGMA user_version = '.$latest);
            } else {
                $db->exec('CREATE TABLE IF NOT EXISTS schema_streams (name VARCHAR(32) NOT NULL PRIMARY KEY, version INTEGER NOT NULL)');
                $statement = $db->prepare('INSERT INTO schema_streams (name, version) VALUES (:name, :version) ON CONFLICT (name) DO UPDATE SET version = excluded.version');
                $statement->bindValue(':name', $stream);
                $statement->bindValue(':version', $latest, SQLITE3_INTEGER);
                $statement->execute();
            }
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            try {
                $db->exec('ROLLBACK');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private static function checked(\SQLite3 $db, int $latest, ?string $stream): int
    {
        $version = self::version($db, $stream);
        if ($version > $latest) {
            $schema = $stream === null ? 'Database schema' : "The $stream schema";
            throw new \RuntimeException("$schema version $version is newer than this code knows ($latest).");
        }

        return $version;
    }

    /**
     * @return array<int, string>
     */
    private static function scan(string $dir): array
    {
        if (!is_dir($dir)) {
            throw new \LogicException("No schema directory at $dir.");
        }
        $found = [];
        foreach (glob($dir.'/*.sql') ?: [] as $path) {
            if (preg_match('/^(\d{4})-[a-z0-9-]+\.sql$/D', basename($path), $m) !== 1) {
                throw new \LogicException('Schema file names are NNNN-name.sql: '.basename($path));
            }
            $found[(int) $m[1]] = $path;
        }
        ksort($found);
        $expected = 1;
        foreach (array_keys($found) as $version) {
            if ($version !== $expected++) {
                throw new \LogicException("Schema versions in $dir must run 1, 2, 3… without gaps.");
            }
        }

        return $found;
    }
}
