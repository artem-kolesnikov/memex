<?php

declare(strict_types=1);

namespace App\Standalone;

/** The owner's account or password refused, with a code the sign-in screen words. */
final class OwnerException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
