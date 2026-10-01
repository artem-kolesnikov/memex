<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A sign-in that could not be completed.
 *
 * Two audiences, so two fields. The `message` is a full sentence naming the
 * specific account or provider, and it goes to the log, where the operator
 * debugs an unregistered redirect URI at eleven at night. The `reason` is a
 * short code that survives a redirect into the sign-in screen, which holds its
 * own wording.
 *
 * The split is deliberate rather than tidy. Putting the server's sentence in
 * the URL would render text an attacker can choose on our own sign-in page,
 * which is a phishing line with our styling around it. Codes cannot be forged
 * into anything the screen does not already say.
 */
class SocialAuthException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'provider',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
