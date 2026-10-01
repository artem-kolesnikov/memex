<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * The name of a vault's file. Random, so it says nothing about the vault
 * or about how many others exist, and never shown to a client.
 */
final class VaultKey
{
    private const PATTERN = '/^[0-9a-f]{32}$/D';

    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function assertValid(string $key): void
    {
        if (preg_match(self::PATTERN, $key) !== 1) {
            throw new \InvalidArgumentException('Not a vault key.');
        }
    }
}
