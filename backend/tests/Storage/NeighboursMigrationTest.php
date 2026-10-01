<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Storage\SchemaMigrator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A vault from before the neighbour lists has vectors and no lists. The
 * migration queues every embedded note, so the sweep builds the lists and
 * duplicates find the pairs they found before, without a re-embed.
 */
final class NeighboursMigrationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/memex-neighbours-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/v1', 0777, true);
        copy(\dirname(__DIR__, 2).'/schema/vault/0001-initial.sql', $this->dir.'/v1/0001-initial.sql');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testEveryEmbeddedNoteIsQueued(): void
    {
        $db = new \SQLite3($this->dir.'/vault.sqlite');
        $db->enableExceptions(true);
        $db->loadExtension(PHP_OS_FAMILY === 'Darwin' ? 'vec0.dylib' : 'vec0.so');
        $db->createFunction('memex_body_over_limit', static fn (int $bytes): int => 0, 1);
        SchemaMigrator::migrate($db, $this->dir.'/v1');

        foreach ([1, 2, 3] as $id) {
            $db->exec("INSERT INTO notes (id, title, body_md, source, status, created_at, updated_at) VALUES ($id, 'Note $id', 'Body', 'manual', 'verified', '2026-09-01', '2026-09-01')");
        }
        foreach ([1, 3] as $id) {
            $db->exec("INSERT INTO note_embeddings (note_id, token_est, embedded_text_hash, embedded_at) VALUES ($id, 1, x'00', '2026-09-01')");
        }

        SchemaMigrator::migrate($db, \dirname(__DIR__, 2).'/schema/vault');

        $queued = [];
        $rows = $db->query('SELECT note_id FROM note_neighbours_stale ORDER BY note_id');
        while (($row = $rows->fetchArray(SQLITE3_NUM)) !== false) {
            $queued[] = $row[0];
        }
        self::assertSame([1, 3], $queued);
    }
}
