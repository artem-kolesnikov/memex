<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Storage\VaultFiles;
use App\Storage\VaultScope;
use App\Tests\Support\TestData;
use App\Storage\BoundVault;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The schema files and the entity mappings describe the same tables. A column
 * the ORM writes that the SQL never created, or a type the two disagree on,
 * shows up here as an ALTER the schema tool would want to run.
 *
 * DBAL reads neither a partial index's predicate back from SQLite (it is
 * taken from sqlite_master here) nor whether a lone INTEGER primary key
 * autoincrements (SQLite makes every such key the rowid).
 */
final class SchemaMappingTest extends KernelTestCase
{
    protected function setUp(): void
    {
        TestData::fresh();
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TestData::discard();
    }

    public function testTheVaultSchemaMatchesTheVaultEntities(): void
    {
        $container = self::getContainer();
        $vault = new BoundVault($container->get(VaultFiles::class)->create(), 'schema');

        $container->get(VaultScope::class)->run($vault, fn () => $this->assertMatches(
            $container->get('doctrine.orm.vault_entity_manager'),
        ));
    }

    public function testTheDirectorySchemaMatchesTheDirectoryEntities(): void
    {
        $this->assertMatches(self::getContainer()->get('doctrine.orm.directory_entity_manager'));
    }

    private function assertMatches(EntityManagerInterface $em): void
    {
        $connection = $em->getConnection();
        $schemaManager = $connection->createSchemaManager();
        $stored = $schemaManager->introspectSchema();
        $mapped = (new SchemaTool($em))->getSchemaFromMetadata($em->getMetadataFactory()->getAllMetadata());

        $this->readPartialPredicates($connection, $stored);
        $this->treatLoneIntegerKeysAsRowids($mapped);

        $diff = $schemaManager->createComparator()->compareSchemas($stored, $mapped);
        self::assertSame([], $connection->getDatabasePlatform()->getAlterSchemaSQL($diff));
    }

    private function readPartialPredicates(Connection $connection, Schema $schema): void
    {
        $rows = $connection->fetchAllAssociative("SELECT tbl_name, name, sql FROM sqlite_master WHERE type = 'index' AND sql LIKE '% WHERE %'");
        foreach ($rows as $row) {
            if (!$schema->hasTable($row['tbl_name'])) {
                continue;
            }
            $table = $schema->getTable($row['tbl_name']);
            $index = $table->getIndex($row['name']);
            $options = ['where' => preg_replace('/^.*\sWHERE\s+/is', '', $row['sql'])] + $index->getOptions();
            $table->dropIndex($index->getName());
            $index->isUnique()
                ? $table->addUniqueIndex($index->getColumns(), $index->getName(), $options)
                : $table->addIndex($index->getColumns(), $index->getName(), $index->getFlags(), $options);
        }
    }

    private function treatLoneIntegerKeysAsRowids(Schema $schema): void
    {
        foreach ($schema->getTables() as $table) {
            $key = $table->getPrimaryKey()?->getColumns() ?? [];
            if (\count($key) === 1 && $table->getColumn($key[0])->getType() instanceof IntegerType) {
                $table->getColumn($key[0])->setAutoincrement(true);
            }
        }
    }
}
