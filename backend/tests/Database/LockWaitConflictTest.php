<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\EventListener\WriteConflictListener;
use App\Service\EmbeddingSpend;
use App\Service\NoteWriter;
use App\Storage\DataDir;
use App\Storage\Sqlite;
use App\Storage\VaultContext;
use App\Storage\VaultDatabase;
use App\Tests\Support\KbFixture;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Giving up on a lock is a refusal, not a crash.
 *
 * Every write takes the vault file's write lock when its transaction begins,
 * so a slow holder makes an unrelated write wait until the busy timeout ends
 * it. Nothing is written when that happens, which is the same fact the 409
 * already carries.
 */
final class LockWaitConflictTest extends DatabaseTestCase
{
    public function testAWaitThatTimesOutReachesTheCallerAsAConflict(): void
    {
        $writer = self::getContainer()->get(NoteWriter::class);
        $kb = new KbFixture(self::getContainer());
        $note = $kb->note($writer, 'Contended', 'Body.');
        $noteId = $note->getId();
        $native = $this->em->getConnection()->getNativeConnection();

        $holder = $this->otherConnection();
        $holder->exec('BEGIN IMMEDIATE');
        $native->busyTimeout(100);
        try {
            $writer->update($note, null, 'Written while another writer held the vault.', null, EmbeddingSpend::Metered, applyTags: false);
            self::fail('The second writer should not have got the vault');
        } catch (\Throwable $waited) {
            self::assertSame(Response::HTTP_CONFLICT, $this->statusFor($waited));
        } finally {
            $native->busyTimeout(Sqlite::BUSY_TIMEOUT_MS);
            $holder->exec('ROLLBACK');
            $holder->close();
        }

        $check = $this->otherConnection();
        try {
            self::assertSame('Body.', $check->querySingle('SELECT body_md FROM notes WHERE id = '.(int) $noteId), 'Nothing was written');
        } finally {
            $check->close();
        }
    }

    /**
     * A connection whose BEGIN gave up waiting is not inside a transaction: the
     * next one takes the lock and commits, rather than nesting in one that
     * never began.
     */
    public function testALockWaitThatGaveUpLeavesNoTransactionBehind(): void
    {
        $writer = self::getContainer()->get(NoteWriter::class);
        $kb = new KbFixture(self::getContainer());
        $noteId = $kb->note($writer, 'Contended', 'Body.')->getId();
        $conn = $this->em->getConnection();
        $native = $conn->getNativeConnection();

        $holder = $this->otherConnection();
        $holder->exec('BEGIN IMMEDIATE');
        $native->busyTimeout(100);
        try {
            $conn->beginTransaction();
            self::fail('The second writer should not have got the vault');
        } catch (LockWaitTimeoutException) {
        } finally {
            $native->busyTimeout(Sqlite::BUSY_TIMEOUT_MS);
            $holder->exec('ROLLBACK');
            $holder->close();
        }

        self::assertFalse($conn->isTransactionActive());
        $conn->transactional(fn ($c) => $c->executeStatement("UPDATE notes SET title = 'Written after' WHERE id = ?", [$noteId]));
        $check = $this->otherConnection();
        try {
            self::assertSame('Written after', $check->querySingle('SELECT title FROM notes WHERE id = '.(int) $noteId));
        } finally {
            $check->close();
        }
    }

    public function testAnOrdinaryFailureIsStillAnError(): void
    {
        self::assertNull($this->responseFor(new \RuntimeException('Something else went wrong')));
    }

    private function otherConnection(): \SQLite3
    {
        $container = self::getContainer();

        return $container->get(VaultDatabase::class)->open(
            $container->get(DataDir::class)->vaultPath($container->get(VaultContext::class)->current()->key),
        );
    }

    private function statusFor(\Throwable $thrown): int
    {
        $response = $this->responseFor($thrown);
        self::assertNotNull($response, 'A lock wait that timed out was not recognised as a conflict: '.$thrown::class.': '.$thrown->getMessage());

        return $response->getStatusCode();
    }

    private function responseFor(\Throwable $thrown): ?Response
    {
        $event = new ExceptionEvent(
            self::getContainer()->get('http_kernel'),
            Request::create('/api/notes/1', 'PUT'),
            HttpKernelInterface::MAIN_REQUEST,
            $thrown,
        );
        (new WriteConflictListener())($event);

        return $event->getResponse();
    }
}
