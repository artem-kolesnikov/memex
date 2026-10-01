<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A patch that did not fit the note.
 *
 * Its message is written for whoever sent the patch — usually an assistant,
 * which is why it says what to do next rather than what went wrong — and it is
 * safe to return verbatim: it quotes the caller's own anchor back, never the
 * note's contents beyond it.
 *
 * It is thrown at two different moments and means two different things. At
 * proposal time it is a mistake, and the proposal is refused. At approval time
 * it means the note has moved underneath a held proposal, and the proposal
 * stays held rather than applying to text nobody reviewed.
 */
class NotePatchException extends \RuntimeException
{
}
