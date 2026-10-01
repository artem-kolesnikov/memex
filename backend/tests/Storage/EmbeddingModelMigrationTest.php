<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Storage\SchemaMigrator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Every vault made before a vault named its embedding model holds OpenAI's
 * vectors, and says so once migrated; its vectors stay.
 */
final class EmbeddingModelMigrationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/memex-embedding-model-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/v2', 0777, true);
        foreach (['0001-initial.sql', '0002-note-neighbours.sql'] as $file) {
            copy(\dirname(__DIR__, 2).'/schema/vault/'.$file, $this->dir.'/v2/'.$file);
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testAnExistingVaultIsOnOpenAisModelWithItsVectorsKept(): void
    {
        $db = new \SQLite3($this->dir.'/vault.sqlite');
        $db->enableExceptions(true);
        $db->loadExtension(PHP_OS_FAMILY === 'Darwin' ? 'vec0.dylib' : 'vec0.so');
        $db->createFunction('memex_body_over_limit', static fn (int $bytes): int => 0, 1);
        SchemaMigrator::migrate($db, $this->dir.'/v2');
        $db->exec("INSERT INTO notes (id, title, body_md, source, status, created_at, updated_at) VALUES (1, 'Note', 'Body', 'manual', 'verified', '2026-09-01', '2026-09-01')");
        $db->exec("INSERT INTO note_embedding_vectors (rowid, embedding) VALUES (1, '[".implode(',', array_fill(0, 1536, 0.25))."]')");

        SchemaMigrator::migrate($db, \dirname(__DIR__, 2).'/schema/vault');

        self::assertSame('text-embedding-3-large', $db->querySingle('SELECT embedding_model FROM settings'));
        self::assertSame(1, $db->querySingle('SELECT COUNT(*) FROM note_embedding_vectors'));
        $db->close();
    }
}
