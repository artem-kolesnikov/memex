<?php

declare(strict_types=1);

namespace App\Storage;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;

/**
 * Every transaction takes the file's write lock when it begins. A deferred
 * one that reads and then writes can find another writer committed in
 * between, and SQLite then fails it with SQLITE_BUSY instead of waiting; a
 * file is one vault, so locking all of it is what a row lock was.
 */
final class ImmediateTransactions extends AbstractConnectionMiddleware
{
    public function beginTransaction(): void
    {
        $this->exec('BEGIN IMMEDIATE');
    }
}
