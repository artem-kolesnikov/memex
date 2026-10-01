<?php

declare(strict_types=1);

namespace App\Service;

use App\Directory\Account;
use App\Entity\VaultSettings;
use App\Storage\VaultContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The person who owns the bound vault. A vault has one, so a write no
 * connection made is theirs.
 */
final class Owner implements ResetInterface
{
    private ?Account $account = null;
    private ?OwnerMark $mark = null;

    public function __construct(
        private readonly VaultContext $context,
        private readonly EntityManagerInterface $em,
        private readonly EntityManagerInterface $directoryEntityManager,
    ) {
    }

    public function account(): Account
    {
        $vault = $this->context->current();
        if ($this->account === null || !$this->account->vault()->is($vault)) {
            $this->account = $this->directoryEntityManager->getRepository(Account::class)->findOneBy(['vaultKey' => $vault->key])
                ?? throw new \RuntimeException('The bound vault has no account.');
            $this->mark = null;
        }

        return $this->account;
    }

    public function mark(): OwnerMark
    {
        $account = $this->account();

        return $this->mark ??= new OwnerMark(
            $account->getName(),
            $account->getEmail(),
            $this->em->getRepository(VaultSettings::class)->current()->getIconKey(),
        );
    }

    public function reset(): void
    {
        $this->account = null;
        $this->mark = null;
    }
}
