<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A verdict arrived for a draft whose author has since revised it.
 *
 * The inbox card is a snapshot: the operator opens a proposal, and its author
 * — one connection, one held item per note — may fold a further edit into that
 * same row while they read. Approving then applied the text that was loaded
 * and deleted the row, so the revision was lost with nothing said. The claim
 * checks `revised_at` as well as `applied_at`, and this is the loser being
 * told what happened rather than the author's work going quietly.
 */
final class ProposalRevisedException extends \RuntimeException
{
}
