<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Storage\SchemaMigrator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Every row written before the journal recorded everyone was a curator's, or
 * the owner's ruling on curation, or memex's. The migration says which, so the
 * curator's cooldown keeps counting what it counted before.
 */
final class JournalActorMigrationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/memex-journal-actor-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/before', 0777, true);
        foreach (glob(\dirname(__DIR__, 2).'/schema/vault/000[1-4]-*.sql') as $file) {
            copy($file, $this->dir.'/before/'.basename($file));
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testEachLegacyRowIsGivenTheWriterItHad(): void
    {
        $db = new \SQLite3($this->dir.'/vault.sqlite');
        $db->enableExceptions(true);
        $db->loadExtension(PHP_OS_FAMILY === 'Darwin' ? 'vec0.dylib' : 'vec0.so');
        $db->createFunction('memex_body_over_limit', static fn (int $bytes): int => 0, 1);
        SchemaMigrator::migrate($db, $this->dir.'/before');

        $db->exec("INSERT INTO api_tokens (id, name, role, created_at) VALUES (1, 'curator-1', 'curator', '2026-09-01')");
        $rows = [
            [1, 'curator-1', 'edit', 'Edited body of “A”'],
            [null, 'curator-gone', 'examined', 'Read during a curation pass'],
            [null, 'operator', 'approved', 'Operator approved the held edit of “A”.'],
            [null, 'memex', 'enrichment-run', 'Looked at 3 notes.'],
            [null, 'Owner A', 'tag-merged', 'Merged the tag “x” into “y” across 2 note(s).'],
            [null, 'Owner A', 'flag-raised', 'Flagged “A” for priority curation: stale'],
            [null, 'Owner A', 'flag-resolved', 'Withdrew the curation flag on “A”.'],
            [1, 'curator-1', 'flag-resolved', 'Resolved the flag on “A”.'],
        ];
        $insert = $db->prepare('INSERT INTO curator_log (token_id, token_name, action, description, is_precedent, created_at) VALUES (:t, :n, :a, :d, 0, :c)');
        foreach ($rows as [$token, $name, $action, $description]) {
            $insert->bindValue(':t', $token, $token === null ? SQLITE3_NULL : SQLITE3_INTEGER);
            $insert->bindValue(':n', $name);
            $insert->bindValue(':a', $action);
            $insert->bindValue(':d', $description);
            $insert->bindValue(':c', '2026-09-01 00:00:00');
            $insert->execute();
            $insert->reset();
        }

        SchemaMigrator::migrate($db, \dirname(__DIR__, 2).'/schema/vault');

        $actors = [];
        $result = $db->query('SELECT actor FROM curator_log ORDER BY id');
        while (($row = $result->fetchArray(SQLITE3_NUM)) !== false) {
            $actors[] = $row[0];
        }
        self::assertSame(['curator', 'curator', 'human', 'memex', 'human', 'human', 'human', 'curator'], $actors);
        self::assertSame(8, $db->querySingle('SELECT COUNT(*) FROM curator_log WHERE curation_work = 1'), 'Every older row counted as curation before, apart from the actions the reader excludes by name');
    }
}
