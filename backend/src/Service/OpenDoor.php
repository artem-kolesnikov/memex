<?php

declare(strict_types=1);

namespace App\Service;

use App\Directory\Account;
use Doctrine\DBAL\Connection;

final class OpenDoor implements AccountDoor
{
    public function newcomerRefusal(?string $ticket): ?SocialAuthException
    {
        return null;
    }

    public function admit(Account $account, ?string $ticket): void
    {
    }

    public function opened(Account $account, string $provider, ?string $ticket, ?string $clientIp): void
    {
    }

    public function accountRefusal(Account $account): ?SocialAuthException
    {
        return null;
    }

    public function ownWayIn(Account $account): bool
    {
        return false;
    }

    public function closing(Connection $directory, int $accountId): void
    {
    }
}
