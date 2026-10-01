<?php

declare(strict_types=1);

namespace App\Standalone;

use App\Directory\Account;
use App\Service\AccountDoor;
use App\Service\SocialAuthException;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * One account, its owner's. It is created at first run ({@see FirstRun}) and
 * never by a sign-in provider, which can only be linked to it from Settings.
 */
final class OwnerDoor implements AccountDoor
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.directory_connection')]
        private readonly Connection $directory,
        private readonly OwnerPassword $password,
    ) {
    }

    public function newcomerRefusal(?string $ticket): ?SocialAuthException
    {
        return new SocialAuthException('This memex has one account, created at first run; a provider is linked to it from Settings.', 'one_owner');
    }

    public function admit(Account $account, ?string $ticket): void
    {
        if ($this->directory->fetchOne('SELECT 1 FROM accounts LIMIT 1') !== false) {
            throw new SocialAuthException('This memex already has its account.', 'owner_exists');
        }
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
        return $this->password->has($account);
    }

    public function closing(Connection $directory, int $accountId): void
    {
    }
}
