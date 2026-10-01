<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A verdict arrived for something already decided.
 *
 * Not an error in the caller's request so much as a race they lost: another
 * tab, a duplicated POST, or a retry after a timeout that had in fact
 * succeeded. The work is not repeated and the caller is told, which is the
 * whole point — the alternative was doing it twice.
 */
final class AlreadyDecidedException extends \RuntimeException
{
}
