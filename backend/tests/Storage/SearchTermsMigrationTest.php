<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Service\SearchTerms;
use App\Storage\SchemaMigrator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A vault from before `notes_terms` gets every note it already holds indexed
 * by the migration, and the index then follows every write.
 */
final class SearchTermsMigrationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/memex-terms-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/v11', 0777, true);
        foreach (glob(\dirname(__DIR__, 2).'/schema/vault/*.sql') as $file) {
            if ((int) basename($file) <= 11) {
                copy($file, $this->dir.'/v11/'.basename($file));
            }
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testExistingNotesAreIndexedAndWritesFollow(): void
    {
        $db = new \SQLite3($this->dir.'/vault.sqlite');
        $db->enableExceptions(true);
        $db->loadExtension(PHP_OS_FAMILY === 'Darwin' ? 'vec0.dylib' : 'vec0.so');
        $db->createFunction('memex_body_over_limit', static fn (int $bytes): int => 0, 1);
        SchemaMigrator::migrate($db, $this->dir.'/v11');
        $db->exec("INSERT INTO notes (id, title, body_md, source, status, created_at, updated_at) VALUES (1, 'Аренда', 'Срок действия договора истёк.', 'manual', 'verified', '2026-10-01', '2026-10-01')");
        $db->exec("INSERT INTO notes (id, title, body_md, source, status, created_at, updated_at) VALUES (2, '会议记录', '我们讨论了价格表。', 'manual', 'verified', '2026-10-01', '2026-10-01')");

        $db->createFunction('memex_search_terms', static fn (?string $text): string => SearchTerms::index((string) $text), 1);
        SchemaMigrator::migrate($db, \dirname(__DIR__, 2).'/schema/vault');

        self::assertSame([1], $this->found($db, '"договор"'));
        self::assertSame([2], $this->found($db, '"价格"'));

        $db->exec("UPDATE notes SET body_md = 'Договоры продлены.' WHERE id = 1");
        self::assertSame([1], $this->found($db, '"продл"'));
        self::assertSame([], $this->found($db, '"истек"'));

        $db->exec('DELETE FROM notes WHERE id = 2');
        self::assertSame([], $this->found($db, '"价格"'));
    }

    /** @return list<int> */
    private function found(\SQLite3 $db, string $expression): array
    {
        $statement = $db->prepare('SELECT rowid FROM notes_terms WHERE notes_terms MATCH :q ORDER BY rowid');
        $statement->bindValue(':q', $expression);
        $rows = $statement->execute();
        $ids = [];
        while (($row = $rows->fetchArray(SQLITE3_NUM)) !== false) {
            $ids[] = $row[0];
        }

        return $ids;
    }
}
