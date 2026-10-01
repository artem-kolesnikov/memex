<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * The vault a request or a job works in: the key names its file, the handle
 * is its public address segment.
 */
final class BoundVault
{
    public function __construct(
        public readonly string $key,
        public readonly string $handle,
    ) {
        VaultKey::assertValid($key);
    }

    public function is(self $other): bool
    {
        return $this->key === $other->key;
    }
}
