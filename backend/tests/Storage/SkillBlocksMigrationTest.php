<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Storage\SchemaMigrator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A skill limited to some connections becomes a skill kept from the others,
 * so the connections that could use it still can and those that could not
 * still cannot; one added later may.
 */
final class SkillBlocksMigrationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/memex-skill-blocks-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/before', 0777, true);
        foreach (glob(\dirname(__DIR__, 2).'/schema/vault/000[1-5]-*.sql') as $file) {
            copy($file, $this->dir.'/before/'.basename($file));
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testEachLimitedSkillIsKeptFromTheConnectionsItWasNotGivenTo(): void
    {
        $db = new \SQLite3($this->dir.'/vault.sqlite');
        $db->enableExceptions(true);
        $db->loadExtension(PHP_OS_FAMILY === 'Darwin' ? 'vec0.dylib' : 'vec0.so');
        $db->createFunction('memex_body_over_limit', static fn (int $bytes): int => 0, 1);
        $db->exec('PRAGMA foreign_keys = ON');
        SchemaMigrator::migrate($db, $this->dir.'/before');

        $db->exec("INSERT INTO api_tokens (id, name, role, created_at) VALUES (1, 'codex', 'agent', '2026-09-01'), (2, 'claude', 'agent', '2026-09-01')");
        $db->exec("INSERT INTO api_tokens (id, name, role, created_at, revoked_at) VALUES (3, 'gone', 'agent', '2026-09-01', '2026-09-02')");
        foreach ([10, 11, 12, 13] as $id) {
            $db->exec("INSERT INTO notes (id, title, body_md, source, status, created_at, updated_at) VALUES ($id, 'Skill $id', 'Steps.', 'manual', 'verified', '2026-09-01', '2026-09-01')");
        }
        // 10: Codex only. 11: only a revoked connection, so everyone. 12: no
        // limit. 13: both live connections, so nobody is kept out.
        $db->exec('INSERT INTO skill_grants (note_id, token_id) VALUES (10, 1), (11, 3), (13, 1), (13, 2)');

        SchemaMigrator::migrate($db, \dirname(__DIR__, 2).'/schema/vault');

        $blocks = [];
        $result = $db->query('SELECT note_id, token_id FROM skill_blocks ORDER BY note_id, token_id');
        while (($row = $result->fetchArray(SQLITE3_NUM)) !== false) {
            $blocks[] = $row;
        }
        self::assertSame([[10, 2]], $blocks);
        self::assertSame(0, $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE name = 'skill_grants'"));
    }
}
