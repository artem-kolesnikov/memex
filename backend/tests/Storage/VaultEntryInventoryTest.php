<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Tests\Support\PhpSource;
use App\Tests\Support\VaultEntrySource;
use PHPUnit\Framework\TestCase;

/**
 * A request is bound once, at the edge, to its principal's vault, and the
 * vault connection opens nothing else. What could break that is code that
 * opens a SQLite file itself, or enters a vault by other means than the
 * principal. Both are listed here with why, so a new one fails this test
 * until somebody writes down which vault it enters and on whose word.
 */
final class VaultEntryInventoryTest extends TestCase
{
    /**
     * Code outside src/Storage, src/Command and the edition's own directories
     * that enters a vault (VaultScope, EveryVault, VaultContext::bind), by file.
     */
    private const ENTRIES = [
        'src/Security/ApiTokenAuthenticator.php' => 'The vault of the account the bearer token belongs to',
        'src/Controller/OAuthController.php' => 'At token exchange, the vault of the account that consented to the code',
        'src/Service/AccountOpener.php' => 'At sign-up, the vault it has just created for the new account',
    ];

    /** Where a SQLite file may be opened, and why. */
    private const FILE_OPENERS = [
        'src/Storage/VaultDatabase.php' => 'Opens the bound vault for the vault connection, and a new vault once',
        'src/Storage/DirectoryDatabase.php' => 'Opens the directory',
        'src/Controller/HealthController.php' => 'An in-memory database, to check sqlite-vec loads',
    ];

    public function testEveryWayIntoAVaultIsDeclared(): void
    {
        $found = [];
        foreach (PhpSource::filesUnder(self::src()) as $file) {
            $relative = self::relative($file);
            if (str_starts_with($relative, 'src/Storage/') || str_starts_with($relative, 'src/Command/')) {
                continue;
            }
            foreach (VaultEntrySource::editionDirs() as $dir) {
                if (str_starts_with($relative, $dir)) {
                    continue 2;
                }
            }
            if (VaultEntrySource::entersAVault((string) file_get_contents($file))) {
                $found[] = $relative;
            }
        }
        sort($found);
        $declared = array_keys(self::ENTRIES);
        sort($declared);

        self::assertSame($declared, $found, "Code that enters a vault changed. Declare in ENTRIES which vault each enters and why it is the request's own, or remove a stale entry.");
    }

    public function testOnlyTheStoreOpensADatabaseFile(): void
    {
        $found = [];
        foreach (PhpSource::filesUnder(self::src()) as $file) {
            if (VaultEntrySource::opensADatabase((string) file_get_contents($file))) {
                $found[] = self::relative($file);
            }
        }
        sort($found);
        $declared = array_keys(self::FILE_OPENERS);
        sort($declared);

        self::assertSame($declared, $found, 'Something outside the store opens a database file. Vault files open only through the vault connection.');
    }

    public function testTheHealthCheckOpensOnlyMemory(): void
    {
        $source = (string) file_get_contents(self::src().'/Controller/HealthController.php');

        self::assertSame(1, preg_match_all('/new\s+\\\\?SQLite3\s*\(/', $source));
        self::assertSame(1, preg_match_all("/new\s+\\\\?SQLite3\s*\(\s*':memory:'\s*\)/", $source));
    }

    private static function src(): string
    {
        return dirname(__DIR__, 2).'/src';
    }

    private static function relative(string $file): string
    {
        return substr($file, strlen(dirname(__DIR__, 2)) + 1);
    }
}
