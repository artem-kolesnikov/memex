<?php

declare(strict_types=1);

namespace App\Storage;

use App\Directory\Account;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one way work spans vaults: each account's vault in turn, entered and
 * left through {@see VaultScope}, so nothing loaded in one is still held when
 * the next opens. A suspended account's vault is skipped unless asked for:
 * its content is kept whole until it is resumed.
 */
final class EveryVault
{
    public function __construct(
        private readonly EntityManagerInterface $directoryEntityManager,
        private readonly VaultScope $scope,
    ) {
    }

    /**
     * With $unreadable, a vault whose work throws is handed to it and the walk
     * goes on; without, the first failure ends the walk.
     *
     * @param callable(Account): void             $work
     * @param null|callable(Account, \Throwable): void $unreadable
     *
     * @return int how many vaults the walk reached
     */
    public function each(callable $work, bool $includeSuspended = false, ?callable $unreadable = null): int
    {
        $ids = $this->directoryEntityManager->getConnection()->fetchFirstColumn(
            'SELECT id FROM accounts'.($includeSuspended ? '' : ' WHERE suspended_at IS NULL').' ORDER BY id'
        );
        $ran = 0;
        foreach ($ids as $id) {
            $account = $this->directoryEntityManager->find(Account::class, (int) $id);
            if ($account === null) {
                continue;
            }
            try {
                $this->scope->run($account->vault(), static fn () => $work($account));
            } catch (\Throwable $e) {
                if ($unreadable === null) {
                    throw $e;
                }
                $unreadable($account, $e);
            }
            ++$ran;
        }

        return $ran;
    }
}
