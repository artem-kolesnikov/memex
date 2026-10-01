<?php

declare(strict_types=1);

namespace App\Service;

/** How the vault's owner appears beside a write: their name, address and face. */
final class OwnerMark
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $iconKey,
    ) {
    }
}
