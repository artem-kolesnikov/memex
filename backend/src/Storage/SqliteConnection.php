<?php

declare(strict_types=1);

namespace App\Storage;

use Doctrine\DBAL\Connection;

/**
 * DBAL counts a transaction as begun before the driver begins it, so a
 * BEGIN IMMEDIATE that gave up waiting for the lock would leave the
 * connection believing it was inside one, and every later transaction on it
 * would nest in nothing. Closing puts the count back; the next query opens
 * the same file again.
 */
final class SqliteConnection extends Connection
{
    public function beginTransaction(): void
    {
        $outermost = !$this->isTransactionActive();
        try {
            parent::beginTransaction();
        } catch (\Throwable $e) {
            if ($outermost) {
                $this->close();
            }
            throw $e;
        }
    }
}
