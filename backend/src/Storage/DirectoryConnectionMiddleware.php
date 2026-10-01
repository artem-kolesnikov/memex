<?php

declare(strict_types=1);

namespace App\Storage;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\SQLite3\Connection as SQLite3Connection;

#[AsMiddleware(connections: ['directory'])]
final class DirectoryConnectionMiddleware implements Middleware
{
    public function __construct(private readonly DirectoryDatabase $database)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this->database) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly DirectoryDatabase $database)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): DriverConnection
            {
                return new ImmediateTransactions(new SQLite3Connection($this->database->open()));
            }
        };
    }
}
