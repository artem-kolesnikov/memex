<?php

declare(strict_types=1);

namespace App\Directory;

use App\Storage\BoundVault;
use App\Storage\VaultOwner;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The person who signs in, and the one vault they own. Everything else about
 * them, their preferences included, is in the vault.
 */
#[ORM\Entity]
#[ORM\Table(name: 'accounts')]
class Account implements UserInterface, VaultOwner
{
    public const TIER_DEFAULT = 'default';

    private const HANDLE_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';
    private const HANDLE_LENGTH = 12;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'vault_key', length: 32, unique: true)]
    private string $vaultKey;

    /** The vault's public address segment: memex.tools/<handle>/notes/<id>. Permanent. */
    #[ORM\Column(length: 16, unique: true)]
    private string $handle;

    /** The provider's verified address at sign-up. Read-only. */
    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(length: 120)]
    private string $name;

    /** What the owner calls their memex. Shown to them and to control. */
    #[ORM\Column(name: 'memex_name', length: 120)]
    private string $memexName;

    /** Which limits apply to this account; what a tier means is the edition's ({@see \App\Service\AccountLimits}). */
    #[ORM\Column(length: 16, options: ['default' => self::TIER_DEFAULT])]
    private string $tier = self::TIER_DEFAULT;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Last authenticated request, written at most once every few minutes. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    /**
     * While it is set the edition's door refuses this account
     * ({@see \App\Service\AccountDoor::accountRefusal()}) and nothing else changes.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $suspendedAt = null;

    public function __construct(string $vaultKey, string $email, string $name, ?string $memexName = null)
    {
        $this->vaultKey = $vaultKey;
        $this->handle = self::newHandle();
        $this->email = $email;
        $this->name = $name;
        $this->memexName = $memexName ?? $name;
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function newHandle(): string
    {
        $handle = '';
        for ($i = 0; $i < self::HANDLE_LENGTH; ++$i) {
            $handle .= self::HANDLE_ALPHABET[random_int(0, \strlen(self::HANDLE_ALPHABET) - 1)];
        }

        return $handle;
    }

    public function vault(): BoundVault
    {
        return new BoundVault($this->vaultKey, $this->handle);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVaultKey(): string
    {
        return $this->vaultKey;
    }

    public function getHandle(): string
    {
        return $this->handle;
    }

    /** A page of this vault, as an absolute path. */
    public function path(string $page = 'notes'): string
    {
        return '/'.$this->handle.'/'.ltrim($page, '/');
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The display name, and the only part of the account a person can rewrite.
     * Linking a provider never touches it.
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getMemexName(): string
    {
        return $this->memexName;
    }

    public function setMemexName(string $memexName): void
    {
        $this->memexName = $memexName;
    }

    public function getTier(): string
    {
        return $this->tier;
    }

    public function setTier(string $tier): void
    {
        $this->tier = $tier;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function isSuspended(): bool
    {
        return $this->suspendedAt !== null;
    }

    public function getSuspendedAt(): ?\DateTimeImmutable
    {
        return $this->suspendedAt;
    }

    public function suspend(): void
    {
        $this->suspendedAt ??= new \DateTimeImmutable();
    }

    public function resume(): void
    {
        $this->suspendedAt = null;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }
}
