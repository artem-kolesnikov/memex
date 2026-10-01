<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Storage\VaultContext;
use App\Storage\VaultDatabase;
use App\Storage\VaultFiles;
use App\Storage\VaultOwner;
use App\Storage\VaultKey;
use App\Storage\VaultScope;
use App\Storage\BoundVault;
use App\Storage\DataDir;
use App\Tests\Support\Embeddings;
use App\Tests\Support\TestData;
use App\Storage\NoVaultBound;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;

final class VaultStoreTest extends KernelTestCase
{
    private VaultFiles $files;
    private VaultScope $scope;
    private VaultContext $context;
    private Connection $vault;

    protected function setUp(): void
    {
        TestData::fresh();
        self::bootKernel();
        $container = self::getContainer();
        $this->files = $container->get(VaultFiles::class);
        $this->scope = $container->get(VaultScope::class);
        $this->context = $container->get(VaultContext::class);
        $this->vault = $container->get('doctrine.dbal.vault_connection');
    }

    public function testTheVaultConnectionRefusesWhenNoVaultIsBound(): void
    {
        $this->expectException(NoVaultBound::class);
        $this->vault->fetchOne('SELECT 1');
    }

    public function testEachVaultReachesOnlyItsOwnFile(): void
    {
        $a = $this->newVault('aaaa');
        $b = $this->newVault('bbbb');

        $this->scope->run($a, function (): void {
            $this->vault->executeStatement('CREATE TABLE scratch (title TEXT)');
            $this->vault->executeStatement("INSERT INTO scratch VALUES ('only in A')");
        });

        $tables = $this->scope->run($b, fn () => $this->vault->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table'"));
        self::assertNotContains('scratch', $tables);
        self::assertSame(
            'only in A',
            $this->scope->run($a, fn () => $this->vault->fetchOne('SELECT title FROM scratch')),
        );
    }

    public function testWorkingInTwoVaultsAtOnceIsRefused(): void
    {
        $a = $this->newVault('aaaa');
        $b = $this->newVault('bbbb');

        $this->expectExceptionMessage('Already working in another vault.');
        $this->scope->run($a, fn () => $this->scope->run($b, fn () => null));
    }

    public function testTheSameVaultMayBeEnteredAgainFromInside(): void
    {
        $a = $this->newVault('aaaa');

        self::assertSame(1, $this->scope->run($a, fn () => $this->scope->run($a, fn () => (int) $this->vault->fetchOne('SELECT 1'))));
    }

    public function testLeavingAVaultClosesItsFile(): void
    {
        $a = $this->newVault('aaaa');

        $this->scope->run($a, fn () => $this->vault->fetchOne('SELECT 1'));

        self::assertFalse($this->vault->isConnected());
        $this->expectException(NoVaultBound::class);
        $this->vault->fetchOne('SELECT 1');
    }

    public function testAVaultWithNoFileIsNotGivenOne(): void
    {
        $key = VaultKey::generate();
        $path = self::getContainer()->get(DataDir::class)->vaultPath($key);

        try {
            $this->scope->run(new BoundVault($key, 'nofile'), fn () => $this->vault->fetchOne('SELECT 1'));
            self::fail('Opening a vault with no file must fail.');
        } catch (\RuntimeException $e) {
            self::assertSame('The bound vault has no file.', $e->getMessage());
        }
        self::assertFileDoesNotExist($path);
    }

    public function testATransactionTakesTheWriteLockWhenItBegins(): void
    {
        $a = $this->newVault('aaaa');
        $path = self::getContainer()->get(DataDir::class)->vaultPath($a->key);

        $this->scope->run($a, function () use ($path): void {
            $this->vault->beginTransaction();
            try {
                $other = new \SQLite3($path);
                $other->enableExceptions(true);
                $other->busyTimeout(0);
                try {
                    $other->exec('BEGIN IMMEDIATE');
                    self::fail('A second writer must wait for the open transaction.');
                } catch (\Exception $e) {
                    self::assertStringContainsString('locked', $e->getMessage());
                } finally {
                    $other->close();
                }
            } finally {
                $this->vault->rollBack();
            }
        });
    }

    public function testAVaultFileIsWalWithVectorsAndFullText(): void
    {
        $a = $this->newVault('aaaa');

        $this->scope->run($a, function (): void {
            self::assertSame('wal', $this->vault->fetchOne('PRAGMA journal_mode'));
            self::assertSame(1, (int) $this->vault->fetchOne('PRAGMA foreign_keys'));
            self::assertSame('v0.1.9', $this->vault->fetchOne('SELECT vec_version()'));
            $this->vault->executeStatement('CREATE VIRTUAL TABLE scratch_fts USING fts5(body)');
            $this->vault->executeStatement('CREATE VIRTUAL TABLE scratch_vec USING vec0(embedding float[2] distance_metric=cosine)');
        });
    }

    public function testARequestIsBoundToTheVaultItAuthenticatedAs(): void
    {
        $a = $this->newVault('aaaa');
        $b = $this->newVault('bbbb');
        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(new StubHolder($a), 'main', ['ROLE_USER']),
        );

        self::assertTrue($this->context->current()->is($a));
        try {
            $this->scope->run($b, fn () => null);
            self::fail('A request bound to A must not enter B.');
        } catch (\LogicException $e) {
            self::assertSame('Already working in another vault.', $e->getMessage());
        }
        $this->expectExceptionMessage('This request is bound to another vault.');
        $this->context->bind($b);
    }

    public function testResetUnbindsAndClosesTheFile(): void
    {
        $a = $this->newVault('aaaa');
        $this->context->bind($a);
        $this->vault->fetchOne('SELECT 1');

        $this->context->reset();

        self::assertFalse($this->vault->isConnected());
        self::assertNull($this->context->resolve());
    }

    public function testDeletingAVaultRemovesItsFile(): void
    {
        $a = $this->newVault('aaaa');
        $path = self::getContainer()->get(DataDir::class)->vaultPath($a->key);
        $this->scope->run($a, fn () => $this->vault->executeStatement('CREATE TABLE scratch (x INTEGER)'));

        $this->files->delete($a->key);

        self::assertFileDoesNotExist($path);
        self::assertFileDoesNotExist($path.'-wal');
    }

    public function testTheDirectoryIsCreatedInTheDataDirectory(): void
    {
        $directory = self::getContainer()->get('doctrine.dbal.directory_connection');

        self::assertSame('wal', $directory->fetchOne('PRAGMA journal_mode'));
        self::assertFileExists(self::getContainer()->get(DataDir::class)->directoryPath());
    }

    public function testVaultsAndTheDirectoryAreReadableByTheirOwnerAlone(): void
    {
        $a = $this->newVault('aaaa');
        $dataDir = self::getContainer()->get(DataDir::class);
        $this->scope->run($a, fn () => $this->vault->executeStatement('CREATE TABLE scratch (x INTEGER)'));
        self::getContainer()->get('doctrine.dbal.directory_connection')->fetchOne('SELECT 1');

        $vault = $dataDir->vaultPath($a->key);
        $this->scope->run($a, function () use ($vault): void {
            $this->vault->executeStatement('INSERT INTO scratch VALUES (1)');
            self::assertSame(0o600, fileperms($vault.'-wal') & 0o777);
        });
        self::assertSame(0o600, fileperms($vault) & 0o777);
        self::assertSame(0o700, fileperms($dataDir->vaultsDir()) & 0o777);
        self::assertSame(0o600, fileperms($dataDir->directoryPath()) & 0o777);
        self::assertSame(0o600, fileperms($dataDir->directoryPath().'-wal') & 0o777);
    }

    public function testAVaultCopiedWithoutSqliteVecOpensWithItsNotesAndVectors(): void
    {
        $a = $this->newVault('aaaa');
        $path = self::getContainer()->get(DataDir::class)->vaultPath($a->key);
        $vector = json_encode(array_fill(0, Embeddings::model(self::getContainer())->dimensions(), 0.25));
        $this->scope->run($a, function () use ($vector): void {
            $this->vault->executeStatement(
                "INSERT INTO notes (title, body_md, source, status, created_at, updated_at) VALUES ('Harbour charts', 'Tide tables for the north quay', 'manual', 'verified', '2026-09-27 10:00:00', '2026-09-27 10:00:00')",
            );
            $this->vault->executeStatement('INSERT INTO note_embedding_vectors (rowid, embedding) VALUES (1, ?)', [$vector]);
        });

        $copy = \dirname($path).'/copy.sqlite';
        $plain = new \SQLite3($path);
        $plain->enableExceptions(true);
        $plain->exec("VACUUM INTO '".$copy."'");
        $plain->close();
        $check = new \SQLite3($copy);
        self::assertSame('ok', $check->querySingle('PRAGMA integrity_check'));
        $check->close();

        $db = self::getContainer()->get(VaultDatabase::class)->open($copy);
        try {
            self::assertSame(1, $db->querySingle("SELECT rowid FROM notes_fts WHERE notes_fts MATCH 'tide'"));
            self::assertSame(1, $db->querySingle(sprintf("SELECT rowid FROM note_embedding_vectors WHERE embedding MATCH '%s' AND k = 1", $vector)));
        } finally {
            $db->close();
        }
    }

    public function testAVaultKeyCannotNameAnotherPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::getContainer()->get(DataDir::class)->vaultPath('../directory');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TestData::discard();
    }

    private function newVault(string $handle): BoundVault
    {
        return new BoundVault($this->files->create(), $handle);
    }
}

final class StubHolder implements UserInterface, VaultOwner
{
    public function __construct(private readonly BoundVault $vault)
    {
    }

    public function vault(): BoundVault
    {
        return $this->vault;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->vault->handle;
    }
}
