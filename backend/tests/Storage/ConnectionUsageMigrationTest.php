<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Service\SearchTerms;
use App\Storage\SchemaMigrator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A connection's last real use starts from the evidence an older vault holds,
 * its newest journal row or skill served, so control does not call an
 * assistant that wrote last week "never used" until it next calls.
 */
final class ConnectionUsageMigrationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/memex-connection-usage-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/before', 0777, true);
        foreach (glob(\dirname(__DIR__, 2).'/schema/vault/00*.sql') as $file) {
            if (basename($file) < '0013') {
                copy($file, $this->dir.'/before/'.basename($file));
            }
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testEachConnectionStartsFromItsNewestWriteOrSkillServed(): void
    {
        $db = new \SQLite3($this->dir.'/vault.sqlite');
        $db->enableExceptions(true);
        $db->loadExtension(PHP_OS_FAMILY === 'Darwin' ? 'vec0.dylib' : 'vec0.so');
        $db->createFunction('memex_body_over_limit', static fn (int $bytes): int => 0, 1);
        $db->createFunction('memex_search_terms', static fn (?string $text): string => SearchTerms::index((string) $text), 1);
        SchemaMigrator::migrate($db, $this->dir.'/before');

        $db->exec("INSERT INTO api_tokens (id, name, role, created_at, last_used_at) VALUES
            (1, 'oauth: Claude', 'curator', '2026-10-01 00:00:00', '2026-10-17 15:47:20'),
            (2, 'oauth: ChatGPT', 'agent', '2026-10-01 00:00:00', '2026-10-07 17:22:39'),
            (3, 'oauth: Cursor', 'agent', '2026-10-01 00:00:00', '2026-10-09 09:00:00')");
        $db->exec("INSERT INTO curator_log (token_id, token_name, action, description, is_precedent, created_at, actor) VALUES
            (1, 'oauth: Claude', 'create', 'Created “A”', 0, '2026-10-08 11:13:32', 'curator'),
            (1, 'oauth: Claude', 'edit', 'Edited “A”', 0, '2026-10-07 15:57:15', 'curator'),
            (null, 'Owner', 'approved', 'Approved “B”', 0, '2026-10-12 10:00:00', 'human')");
        $db->exec("INSERT INTO skill_serves (slug, path, served_at, token_id) VALUES
            ('memex-writing', 'tool', '2026-10-07 17:40:41', 1),
            ('memex-writing', 'tool', '2026-10-07 17:22:08', 2)");

        SchemaMigrator::migrate($db, \dirname(__DIR__, 2).'/schema/vault');

        $called = [];
        $result = $db->query('SELECT id, last_called_at FROM api_tokens ORDER BY id');
        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $called[$row['id']] = $row['last_called_at'];
        }
        self::assertSame([1 => '2026-10-08 11:13:32', 2 => '2026-10-07 17:22:08', 3 => null], $called);
        self::assertSame(0, $db->querySingle('SELECT COUNT(*) FROM connection_usage'));
    }
}
