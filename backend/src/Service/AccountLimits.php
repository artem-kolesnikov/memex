<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What the account a request is in may spend on this server's keys and hold.
 * {@see SpendLimiter} and {@see GrowthLimits} enforce it; the edition sets it.
 * {@see NoLimits} caps nothing.
 */
interface AccountLimits
{
    public function spend(): SpendAllowance;

    /**
     * How many notes and live connections the account may hold, null for no limit.
     *
     * @return array{notes: ?int, connections: ?int}
     */
    public function room(): array;

    /**
     * Whether this server's own key writes descriptions for the account, and with which model.
     *
     * @return array{on: bool, model: ?string}
     */
    public function includedText(): array;

    /** A spend ceiling turned a request away. */
    public function spendLimitReached(string $surface, string $window, int $limit): void;

    /** The account is full: `notes` or `connections`. */
    public function roomReached(string $dimension, int $limit): void;
}
