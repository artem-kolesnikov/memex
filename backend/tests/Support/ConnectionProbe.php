<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\Bundle\DoctrineBundle\Middleware\ConnectionNameAwareInterface;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Test only, the outermost middleware on both connections. It records every
 * file a connection opens, read back from SQLite itself rather than from the
 * code that chose it, and every ATTACH. Armed, it makes statements on one
 * connection fail with {@see FAULT}, the kind of text a broken disk or a
 * corrupt file produces, so a test can look for that text in what a client
 * receives.
 */
#[AsMiddleware(priority: -1000)]
final class ConnectionProbe implements Middleware, ConnectionNameAwareInterface
{
    public const FAULT = 'disk I/O error in /srv/memex/vaults/probe-fault.sqlite (probe-fault-7f3a)';

    /** @var list<array{connection: string, file: string}> */
    private static array $opened = [];
    /** @var array<string, string> connection => 'all' or 'writes' */
    private static array $failing = [];
    /** @var array<string, string> faults that start once the controller is called */
    private static array $pending = [];

    private string $connection = '';

    public function setConnectionName(string $name): void
    {
        $this->connection = $name;
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this->connection) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly string $connection)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): DriverConnection
            {
                $connection = parent::connect($params);
                foreach ($connection->query('PRAGMA database_list')->fetchAllAssociative() as $row) {
                    ConnectionProbe::record($this->connection, (string) $row['file']);
                }

                return new class($connection, $this->connection) extends AbstractConnectionMiddleware {
                    public function __construct(DriverConnection $connection, private readonly string $name)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        ConnectionProbe::statement($this->name, $sql);

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        ConnectionProbe::statement($this->name, $sql);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        ConnectionProbe::statement($this->name, $sql);

                        return parent::exec($sql);
                    }

                    public function beginTransaction(): void
                    {
                        ConnectionProbe::statement($this->name, 'BEGIN');
                        parent::beginTransaction();
                    }
                };
            }
        };
    }

    /** @internal */
    public static function record(string $connection, string $file): void
    {
        self::$opened[] = ['connection' => $connection, 'file' => $file === '' ? '' : (realpath($file) ?: $file)];
    }

    /** @internal */
    public static function statement(string $connection, string $sql): void
    {
        if (preg_match('/^\s*ATTACH\b/i', $sql) === 1) {
            self::record($connection, 'ATTACH: '.$sql);
        }
        $mode = self::$failing[$connection] ?? null;
        if ($mode === null) {
            return;
        }
        if ($mode === 'writes' && preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql) !== 1) {
            return;
        }
        throw new class(self::FAULT) extends AbstractException {
        };
    }

    /**
     * The files opened since the last call, by connection name.
     *
     * @return list<array{connection: string, file: string}>
     */
    public static function takeOpened(): array
    {
        $opened = self::$opened;
        self::$opened = [];

        return $opened;
    }

    /**
     * From the moment the next request's controller is called, statements on
     * $connection fail: every one ('all'), or only those that write.
     */
    public static function failFromController(string $connection, string $mode): void
    {
        self::$pending[$connection] = $mode;
    }

    /** Statements on $connection fail from now on. */
    public static function failNow(string $connection, string $mode): void
    {
        self::$failing[$connection] = $mode;
    }

    /** @internal for {@see ConnectionProbeArming} */
    public static function armPending(): void
    {
        self::$failing = self::$pending + self::$failing;
        self::$pending = [];
    }

    public static function stop(): void
    {
        self::$failing = [];
        self::$pending = [];
    }
}
