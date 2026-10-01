<?php

declare(strict_types=1);

namespace App\Service;

use App\Directory\Account;
use App\Storage\VaultContext;
use App\Storage\VaultFiles;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Leaving memex: one act, and nothing left afterwards.
 *
 * Deleting an account does NOT retire it the way {@see NoteLimbo} retires a
 * note. That asymmetry is deliberate (operator, 2026-08-26): retiring a note is
 * an editing act performed dozens of times a week, leaving is one deliberate
 * act confirmed by typing an address, and a grace period on it was apparatus
 * rather than safety. The way back is the export sitting directly above the
 * button, taken before, not a countdown afterwards.
 *
 * Nor does an account leave a tombstone. A retired note keeps one because
 * inbound links silently re-bind and re-import resurrects retirements; neither
 * is true of an account, and the promise here is the opposite one: afterwards
 * nothing of theirs remains, including whatever the edition kept about letting
 * them in, which {@see AccountDoor::closing()} clears in the same transaction.
 */
class AccountDeletion
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntityManagerInterface $directoryEntityManager,
        #[Autowire(service: 'doctrine.dbal.directory_connection')]
        private readonly Connection $directory,
        #[Autowire(service: 'doctrine.dbal.vault_connection')]
        private readonly Connection $vault,
        private readonly VaultContext $context,
        private readonly VaultFiles $vaults,
        private readonly SessionRegistry $sessions,
        private readonly AccountDoor $door,
    ) {
    }

    /**
     * Leave, now. There is no second step and no sweep to wait for.
     *
     * The directory goes first, so nothing can route to the vault once its
     * file is being deleted: the account row takes its identities, sessions,
     * authorization codes and bearer tokens with it.
     */
    public function delete(Account $account): void
    {
        $bound = $this->context->resolve();
        if ($bound !== null && !$bound->is($account->vault())) {
            throw new \LogicException('An account is deleted from its own vault or from none.');
        }
        $accountId = $account->getId() ?? throw new \LogicException('This account is not saved yet.');

        $this->directory->transactional(function (Connection $directory) use ($accountId): void {
            // `sessions` is Symfony's own table, keyed by PHP id with no
            // account column, so the stored session of every browser but the
            // one that pressed the button would outlive the account without this.
            $this->sessions->endAll($accountId);

            $this->door->closing($directory, $accountId);
            $directory->executeStatement('DELETE FROM accounts WHERE id = :account', ['account' => $accountId]);
        });
        $this->directoryEntityManager->clear();

        if ($bound !== null) {
            $this->em->clear();
            $this->vault->close();
        }
        $this->vaults->delete($account->getVaultKey());
    }
}
