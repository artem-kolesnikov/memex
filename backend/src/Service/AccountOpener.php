<?php

declare(strict_types=1);

namespace App\Service;

use App\Directory\Account;
use App\Entity\CuratorLogEntry;
use App\Storage\VaultFiles;
use App\Storage\VaultScope;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * A new account, however its owner arrived. The vault is created first, then
 * the account in one flush with the way in and the {@see AccountDoor}'s own
 * record of admitting them. The door has its say inside that transaction, which
 * holds the directory's write lock, so two people arriving together cannot both
 * take the last place. A vault whose account never came to be is deleted again.
 */
final class AccountOpener
{
    public function __construct(
        private readonly EntityManagerInterface $directoryEntityManager,
        private readonly VaultFiles $vaults,
        private readonly VaultScope $scope,
        private readonly WelcomeNotes $welcome,
        private readonly StarterSkills $starters,
        private readonly LoggerInterface $logger,
        private readonly AccountDoor $door,
        private readonly Journal $journal,
    ) {
    }

    /**
     * @param \Closure(Account): void $wayIn persists how the owner signs in, inside the transaction
     *
     * @throws SocialAuthException when $wayIn or the door turns them away
     */
    public function open(string $email, string $name, string $memexName, ?string $ticket, \Closure $wayIn): Account
    {
        $vaultKey = $this->vaults->create();
        try {
            $account = $this->directoryEntityManager->wrapInTransaction(function () use ($vaultKey, $email, $name, $memexName, $ticket, $wayIn): Account {
                $account = new Account($vaultKey, $email, $name, $memexName);
                $this->directoryEntityManager->persist($account);
                $wayIn($account);
                $this->door->admit($account, $ticket);
                $this->directoryEntityManager->flush();

                return $account;
            });
        } catch (\Throwable $e) {
            $this->vaults->delete($vaultKey);
            throw $e;
        }

        // The account exists; notes that fail to arrive are logged, never a
        // failed sign-up.
        try {
            $this->scope->run($account->vault(), function () use ($account): void {
                $this->journal->batch(
                    function () use ($account): void {
                        $this->starters->seed();
                        $this->welcome->seed($account->getHandle());
                    },
                    static fn (array $notes): CuratorLogEntry => (new CuratorLogEntry('memex', CuratorLogEntry::ACTION_IMPORT, 'Wrote the first '.count($notes).' notes of this memex'))->byMemex(),
                );
            });
        } catch (\Throwable $e) {
            $this->logger->error('The welcome notes could not be seeded at sign-up', ['exception' => $e]);
        }

        return $account;
    }
}
