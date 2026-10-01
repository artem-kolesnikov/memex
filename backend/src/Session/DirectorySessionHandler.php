<?php

declare(strict_types=1);

namespace App\Session;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\AbstractSessionHandler;

/**
 * PHP sessions in the directory's `sessions` table. No lock is held across a
 * request: SQLite locks the whole file, so a per-session lock would make every
 * signed-in request on the box wait for every other. Two requests from one
 * browser that both change the session keep the later write.
 */
final class DirectorySessionHandler extends AbstractSessionHandler
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.directory_connection')]
        private readonly Connection $directory,
        #[Autowire('%app.session_lifetime%')]
        private readonly int $ttl,
    ) {
    }

    public function close(): bool
    {
        return true;
    }

    public function gc(int $maxlifetime): int|false
    {
        return (int) $this->directory->executeStatement(
            'DELETE FROM sessions WHERE sess_lifetime < :now',
            ['now' => time()],
        );
    }

    public function updateTimestamp(#[\SensitiveParameter] string $sessionId, string $data): bool
    {
        $this->directory->executeStatement(
            'UPDATE sessions SET sess_lifetime = :expires, sess_time = :now WHERE sess_id = :id',
            ['expires' => time() + $this->ttl, 'now' => time(), 'id' => $sessionId],
        );

        return true;
    }

    protected function doRead(#[\SensitiveParameter] string $sessionId): string
    {
        $data = $this->directory->fetchOne(
            'SELECT sess_data FROM sessions WHERE sess_id = :id AND sess_lifetime >= :now',
            ['id' => $sessionId, 'now' => time()],
        );

        return $data === false ? '' : (string) $data;
    }

    protected function doWrite(#[\SensitiveParameter] string $sessionId, string $data): bool
    {
        $this->directory->executeStatement(
            'INSERT INTO sessions (sess_id, sess_data, sess_lifetime, sess_time) VALUES (:id, :data, :expires, :now)
             ON CONFLICT (sess_id) DO UPDATE SET sess_data = excluded.sess_data, sess_lifetime = excluded.sess_lifetime, sess_time = excluded.sess_time',
            ['id' => $sessionId, 'data' => $data, 'expires' => time() + $this->ttl, 'now' => time()],
            ['data' => ParameterType::LARGE_OBJECT],
        );

        return true;
    }

    protected function doDestroy(#[\SensitiveParameter] string $sessionId): bool
    {
        $this->directory->executeStatement('DELETE FROM sessions WHERE sess_id = :id', ['id' => $sessionId]);

        return true;
    }
}
