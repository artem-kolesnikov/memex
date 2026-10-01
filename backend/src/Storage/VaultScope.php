<?php

declare(strict_types=1);

namespace App\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * How work that is not a request enters a vault: a command, a job that
 * walks vaults, a test. Inside run() the vault connection opens that
 * vault's file; on the way out the entity managers forget what they loaded
 * and the file is closed, so the next vault starts clean.
 */
final class VaultScope
{
    public function __construct(
        private readonly VaultContext $context,
        #[Autowire(service: 'doctrine.dbal.vault_connection')]
        private readonly Connection $connection,
        private readonly ManagerRegistry $doctrine,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(BoundVault $vault, callable $work): mixed
    {
        $current = $this->context->resolve();
        if ($current !== null) {
            if (!$current->is($vault)) {
                throw new \LogicException('Already working in another vault.');
            }

            return $work();
        }

        $this->context->bind($vault);
        try {
            return $work();
        } finally {
            $this->leave();
        }
    }

    /**
     * A request's vault is its principal's: signing out, which signing in as
     * somebody else begins with, lets go of it.
     */
    #[AsEventListener(event: LogoutEvent::class)]
    public function release(): void
    {
        if ($this->context->bound() !== null) {
            $this->leave();
        }
    }

    private function leave(): void
    {
        foreach ($this->doctrine->getManagers() as $name => $manager) {
            if ($manager instanceof EntityManagerInterface && $manager->getConnection() === $this->connection) {
                $manager->isOpen() ? $manager->clear() : $this->doctrine->resetManager($name);
            }
        }
        $this->connection->close();
        $this->context->release();
    }
}
