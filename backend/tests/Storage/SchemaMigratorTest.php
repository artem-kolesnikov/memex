<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Storage\SchemaMigrator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SchemaMigratorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/memex-schema-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/schema', 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testAppliesEachMigrationOnceInOrder(): void
    {
        $this->schema('0001-first.sql', 'CREATE TABLE a (x INTEGER); INSERT INTO a VALUES (1);');
        $this->schema('0002-second.sql', 'CREATE TABLE b (y INTEGER); INSERT INTO b SELECT x + 1 FROM a;');
        $db = $this->db();

        SchemaMigrator::migrate($db, $this->schemaDir());
        SchemaMigrator::migrate($db, $this->schemaDir());

        self::assertSame(2, SchemaMigrator::version($db));
        self::assertSame(1, $db->querySingle('SELECT count(*) FROM a'));
        self::assertSame(2, $db->querySingle('SELECT y FROM b'));
    }

    public function testAppliesOnlyWhatTheFileHasNotHad(): void
    {
        $this->schema('0001-first.sql', 'CREATE TABLE a (x INTEGER);');
        $db = $this->db();
        $db->exec('CREATE TABLE a (x INTEGER); PRAGMA user_version = 1;');

        SchemaMigrator::migrate($db, $this->schemaDir());

        self::assertSame(1, SchemaMigrator::version($db));
    }

    public function testAFailedMigrationLeavesTheFileAsItWas(): void
    {
        $this->schema('0001-first.sql', 'CREATE TABLE a (x INTEGER);');
        $this->schema('0002-broken.sql', 'CREATE TABLE b (y INTEGER); THIS IS NOT SQL;');
        $db = $this->db();

        try {
            SchemaMigrator::migrate($db, $this->schemaDir());
            self::fail('A broken migration must throw.');
        } catch (\Exception) {
        }

        self::assertSame(0, SchemaMigrator::version($db));
        self::assertSame(0, $db->querySingle("SELECT count(*) FROM sqlite_master WHERE name IN ('a', 'b')"));
    }

    public function testRefusesAFileNewerThanTheCode(): void
    {
        $this->schema('0001-first.sql', 'CREATE TABLE a (x INTEGER);');
        $db = $this->db();
        $db->exec('PRAGMA user_version = 2');

        $this->expectExceptionMessage('newer than this code knows');
        SchemaMigrator::migrate($db, $this->schemaDir());
    }

    public function testRefusesAGapInTheVersions(): void
    {
        $this->schema('0001-first.sql', 'SELECT 1;');
        $this->schema('0003-third.sql', 'SELECT 1;');

        $this->expectExceptionMessage('without gaps');
        SchemaMigrator::migrations($this->schemaDir());
    }

    public function testRefusesAFileNameItCannotNumber(): void
    {
        $this->schema('first.sql', 'SELECT 1;');

        $this->expectExceptionMessage('NNNN-name.sql');
        SchemaMigrator::migrations($this->schemaDir());
    }

    public function testAnEmptySchemaDirectoryIsVersionZero(): void
    {
        $db = $this->db();

        SchemaMigrator::migrate($db, $this->schemaDir());

        self::assertSame(0, SchemaMigrator::version($db));
    }

    public function testAStreamIsNumberedApartFromTheFile(): void
    {
        $this->schema('0001-first.sql', 'CREATE TABLE a (x INTEGER);');
        $this->schema('0002-second.sql', 'CREATE TABLE b (y INTEGER);');
        mkdir($this->dir.'/stream');
        file_put_contents($this->dir.'/stream/0001-own.sql', 'CREATE TABLE c (z INTEGER);');
        $db = $this->db();

        SchemaMigrator::migrate($db, $this->schemaDir());
        SchemaMigrator::migrate($db, $this->dir.'/stream', 'extra');
        SchemaMigrator::migrate($db, $this->dir.'/stream', 'extra');

        self::assertSame(2, SchemaMigrator::version($db));
        self::assertSame(1, SchemaMigrator::version($db, 'extra'));
        self::assertSame(0, SchemaMigrator::version($db, 'another'));
        self::assertSame(1, $db->querySingle("SELECT count(*) FROM sqlite_master WHERE name = 'c'"));
    }

    public function testAFailedStreamMigrationLeavesTheFileAsItWas(): void
    {
        mkdir($this->dir.'/stream');
        file_put_contents($this->dir.'/stream/0001-own.sql', 'CREATE TABLE c (z INTEGER); THIS IS NOT SQL;');
        $db = $this->db();

        try {
            SchemaMigrator::migrate($db, $this->dir.'/stream', 'extra');
            self::fail('A broken migration must throw.');
        } catch (\Exception) {
        }

        self::assertSame(0, SchemaMigrator::version($db, 'extra'));
        self::assertSame(0, $db->querySingle("SELECT count(*) FROM sqlite_master WHERE name IN ('c', 'schema_streams')"));
    }

    private function schemaDir(): string
    {
        return $this->dir.'/schema';
    }

    private function schema(string $name, string $sql): void
    {
        file_put_contents($this->schemaDir().'/'.$name, $sql);
    }

    private function db(): \SQLite3
    {
        $db = new \SQLite3($this->dir.'/test.sqlite');
        $db->enableExceptions(true);

        return $db;
    }
}
