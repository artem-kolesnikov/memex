<?php

declare(strict_types=1);

namespace App\Storage;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\SQLite3\Connection as SQLite3Connection;

/**
 * The vault connection opens the bound vault's file and nothing else:
 * the configured path is never used, and with no vault bound it refuses.
 */
#[AsMiddleware(connections: ['vault'])]
final class VaultConnectionMiddleware implements Middleware
{
    public function __construct(
        private readonly VaultContext $context,
        private readonly DataDir $dataDir,
        private readonly VaultDatabase $database,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this->context, $this->dataDir, $this->database) extends AbstractDriverMiddleware {
            public function __construct(
                Driver $driver,
                private readonly VaultContext $context,
                private readonly DataDir $dataDir,
                private readonly VaultDatabase $database,
            ) {
                parent::__construct($driver);
            }

            public function connect(array $params): DriverConnection
            {
                $path = $this->dataDir->vaultPath($this->context->current()->key);
                if (!is_file($path)) {
                    throw new \RuntimeException('The bound vault has no file.');
                }

                return new ImmediateTransactions(new SQLite3Connection($this->database->open($path)));
            }
        };
    }
}
