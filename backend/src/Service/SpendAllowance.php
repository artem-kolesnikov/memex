<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What one knowledge base may make memex buy, and which of those purchases it
 * is paying for itself.
 *
 * Resolved by {@see AccountLimits} for the bound vault and read by
 * {@see SpendLimiter}. The two booleans are not limits: a section running on
 * the account's own key is not bounded here at all, because the bill is theirs
 * and a ceiling on somebody else's money is not memex's to set.
 */
final readonly class SpendAllowance
{
    public const UNLIMITED = 'unlimited';

    public function __construct(
        public string $tier,
        public int $embedHourly,
        public int $embedDaily,
        public int $searchHourly,
        public int $searchDaily,
        public int $analyzeHourly,
        public int $analyzeDaily,
        public int $textHourly = 1,
        public int $textDaily = 1,
        public bool $ownEmbedKey = false,
        public bool $ownTextKey = false,
    ) {
    }

    public static function unlimited(): self
    {
        return new self(self::UNLIMITED, 0, 0, 0, 0, 0, 0, 0, 0);
    }

    /** No ceiling, on any surface. */
    public function isUnlimited(): bool
    {
        return $this->tier === self::UNLIMITED;
    }
}
