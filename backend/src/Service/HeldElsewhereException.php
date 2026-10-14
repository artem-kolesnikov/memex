<?php

declare(strict_types=1);

namespace App\Service;

/**
 * An edit to a sentence, title or description another connection's waiting
 * edit already changes (operator, 2026-10-14). Refused so that every waiting
 * edit on a note stays approvable on its own; the message names whose edit
 * holds it and how to be heard anyway.
 */
final class HeldElsewhereException extends \RuntimeException
{
}
