<?php

declare(strict_types=1);

namespace App\Storage;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Which vault's file the vault connection opens. A request is bound once,
 * from the principal it authenticated as, and stays bound to that vault
 * until the request ends; a job binds through {@see VaultScope}.
 */
final class VaultContext implements ResetInterface
{
    private ?BoundVault $bound = null;

    /**
     * @param \Closure(): Connection $vaultConnection
     */
    public function __construct(
        private readonly TokenStorageInterface $tokens,
        #[AutowireServiceClosure('doctrine.dbal.vault_connection')]
        private readonly \Closure $vaultConnection,
    ) {
    }

    public function current(): BoundVault
    {
        return $this->resolve() ?? throw new NoVaultBound();
    }

    /** The vault already fixed for this request, without asking who signed in. */
    public function bound(): ?BoundVault
    {
        return $this->bound;
    }

    public function resolve(): ?BoundVault
    {
        if ($this->bound !== null) {
            return $this->bound;
        }
        $user = $this->tokens->getToken()?->getUser();
        if ($user instanceof VaultOwner) {
            $this->bind($user->vault());
        }

        return $this->bound;
    }

    /**
     * Fix the vault for the rest of the request. Called by an authenticator
     * once it knows the account, and by {@see VaultScope}.
     */
    public function bind(BoundVault $vault): void
    {
        if ($this->bound !== null && !$this->bound->is($vault)) {
            throw new \LogicException('This request is bound to another vault.');
        }
        $this->bound = $vault;
    }

    /**
     * @internal for VaultScope, which closes the connection first
     */
    public function release(): void
    {
        $this->bound = null;
    }

    public function reset(): void
    {
        if ($this->bound !== null) {
            ($this->vaultConnection)()->close();
        }
        $this->bound = null;
    }
}
