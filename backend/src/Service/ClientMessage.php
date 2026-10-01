<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * What a client may read about a failure. Only what memex throws for the
 * person who asked carries its text out; a driver error, a library's
 * complaint or a transport failure names files, queries and hosts, so it is
 * logged and the client is told something went wrong inside memex.
 */
final class ClientMessage
{
    /** The text for $e, or null when its text is not for a client. */
    public static function of(\Throwable $e): ?string
    {
        if ($e instanceof HttpExceptionInterface
            || $e instanceof StorageLimitExceeded
            || $e instanceof NotePatchException
            || $e instanceof SystemTagException
            || $e instanceof AlreadyDecidedException
            || $e instanceof ProposalRevisedException
            || $e::class === \InvalidArgumentException::class) {
            return $e->getMessage();
        }

        return self::conflict($e);
    }

    /** A write that lost a race, said as such, or null for any other failure. */
    public static function conflict(\Throwable $e): ?string
    {
        for ($at = $e, $depth = 0; $at !== null && $depth < 6; $at = $at->getPrevious(), ++$depth) {
            if ($at instanceof OptimisticLockException) {
                return 'Somebody else changed this while you were working on it. Reload and try again.';
            }
            if ($at instanceof AlreadyDecidedException) {
                return 'This has already been decided — another window or an earlier click got there first.';
            }
            if ($at instanceof ProposalRevisedException) {
                return 'This item or a note it affects changed while you were reading it. Reload the item before approving.';
            }
            if ($at instanceof LockWaitTimeoutException) {
                return 'Another change to this knowledge base was still finishing, so this one was not applied. Try again.';
            }
        }

        return null;
    }
}
