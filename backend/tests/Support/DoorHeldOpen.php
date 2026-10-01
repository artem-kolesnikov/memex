<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Directory\Account;
use App\Service\AccountDoor;
use App\Service\SocialAuthException;
use Doctrine\DBAL\Connection;

/**
 * The edition's door, which a core test can hold open. Creating an account is
 * the core's and who may have one is the edition's, so a core test that needs a
 * new account opens it through {@see self::during()} and passes under any door.
 */
final class DoorHeldOpen implements AccountDoor
{
    private static bool $open = false;

    public function __construct(private readonly AccountDoor $inner)
    {
    }

    /** Held open until {@see self::release()}, for a test class that is never about the door. */
    public static function hold(): void
    {
        self::$open = true;
    }

    public static function release(): void
    {
        self::$open = false;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public static function during(\Closure $work): mixed
    {
        self::$open = true;
        try {
            return $work();
        } finally {
            self::$open = false;
        }
    }

    public function newcomerRefusal(?string $ticket): ?SocialAuthException
    {
        return self::$open ? null : $this->inner->newcomerRefusal($ticket);
    }

    public function admit(Account $account, ?string $ticket): void
    {
        if (!self::$open) {
            $this->inner->admit($account, $ticket);
        }
    }

    public function opened(Account $account, string $provider, ?string $ticket, ?string $clientIp): void
    {
        if (!self::$open) {
            $this->inner->opened($account, $provider, $ticket, $clientIp);
        }
    }

    public function accountRefusal(Account $account): ?SocialAuthException
    {
        return $this->inner->accountRefusal($account);
    }

    public function ownWayIn(Account $account): bool
    {
        return $this->inner->ownWayIn($account);
    }

    public function closing(Connection $directory, int $accountId): void
    {
        $this->inner->closing($directory, $accountId);
    }
}
