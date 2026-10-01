<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Who a provider says just signed in.
 *
 * `subject` is the provider's own immutable id and is the only field anything
 * is keyed on. `email` and `name` are what it happened to report this time:
 * useful for creating an account and for telling two Google accounts apart on
 * the Settings screen, and load-bearing for nothing else.
 */
final readonly class SocialIdentity
{
    public function __construct(
        public string $provider,
        public string $subject,
        public ?string $email,
        public ?string $name,
    ) {
    }
}
