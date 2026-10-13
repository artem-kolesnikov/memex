<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A held draft written against text that has changed since, met in a batch.
 *
 * Approving one on its own is allowed: the card shows what changed and the
 * owner decides with it in front of them. A batch shows nothing, so a stale
 * item is left waiting and named in the report (operator, 2026-10-13).
 */
final class StaleProposalException extends \RuntimeException
{
}
