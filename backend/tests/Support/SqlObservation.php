<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Logging\Connection as LoggingConnection;
use Doctrine\DBAL\Logging\Middleware;
use Psr\Log\AbstractLogger;

final class SqlObservation extends AbstractLogger
{
    private int $transactions = 0;
    private bool $open = false;

    public function __construct(private readonly \Closure $observe)
    {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ((string) $message === 'Beginning transaction') {
            ++$this->transactions;
            $this->open = true;
        } elseif (\in_array((string) $message, ['Committing transaction', 'Rolling back transaction'], true)) {
            $this->open = false;
        } elseif (isset($context['sql'])) {
            ($this->observe)($context['sql'], $context['params'] ?? [], $this->open ? $this->transactions : null);
        }
    }

    /**
     * Show $observe every statement $conn runs during $work, with its
     * parameters and which transaction it ran in (null outside one): on the
     * open connection, and on any it opens afresh, as a request does after it
     * has closed the vault the test entered.
     *
     * @param \Closure(string, array<int|string, mixed>, ?int): void $observe
     */
    public static function during(Connection $conn, \Closure $observe, \Closure $work): mixed
    {
        $logger = new self($observe);
        $driver = new \ReflectionProperty(Connection::class, 'driver');
        $live = new \ReflectionProperty(Connection::class, '_conn');
        $original = $driver->getValue($conn);
        $driver->setValue($conn, (new Middleware($logger))->wrap($original));
        $open = $live->getValue($conn);
        $wrapped = $open === null ? null : new LoggingConnection($open, $logger);
        if ($wrapped !== null) {
            $live->setValue($conn, $wrapped);
        }
        try {
            return $work();
        } finally {
            $driver->setValue($conn, $original);
            if ($wrapped !== null && $live->getValue($conn) === $wrapped) {
                $live->setValue($conn, $open);
            } elseif ($live->getValue($conn) !== null) {
                $conn->close();
            }
        }
    }

    /** Whether another connection would have to wait to write this SQLite file. */
    public static function isWriteLocked(string $path): bool
    {
        $other = new \SQLite3($path, SQLITE3_OPEN_READWRITE);
        $other->enableExceptions(true);
        $other->busyTimeout(0);
        try {
            $other->exec('BEGIN IMMEDIATE');
            $other->exec('ROLLBACK');

            return false;
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'database is locked')) {
                throw $e;
            }

            return true;
        } finally {
            $other->close();
        }
    }
}
