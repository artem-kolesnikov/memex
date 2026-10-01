<?php

declare(strict_types=1);

namespace App\Standalone;

use App\Directory\Account;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;

/**
 * The owner's password, kept as a hash beside the account in the directory.
 * Its length is the owner's choice: anything but empty.
 */
final class OwnerPassword
{
    private readonly NativePasswordHasher $hasher;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.directory_connection')]
        private readonly Connection $directory,
    ) {
        $this->hasher = new NativePasswordHasher();
    }

    /** @throws OwnerException */
    public static function check(string $password): void
    {
        if ($password === '') {
            throw new OwnerException('password_empty', 'Type a password.');
        }
        if (\strlen($password) > NativePasswordHasher::MAX_PASSWORD_LENGTH) {
            throw new OwnerException('password_long', 'That password is longer than '.NativePasswordHasher::MAX_PASSWORD_LENGTH.' bytes.');
        }
    }

    public function has(Account $account): bool
    {
        return $this->hash($account) !== null;
    }

    /** @throws OwnerException */
    public function set(Account $account, string $password): void
    {
        self::check($password);
        $this->directory->executeStatement(
            'INSERT INTO owner_password (account_id, hash, changed_at) VALUES (:account, :hash, :at)
             ON CONFLICT (account_id) DO UPDATE SET hash = excluded.hash, changed_at = excluded.changed_at',
            ['account' => $account->getId(), 'hash' => $this->hasher->hash($password), 'at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
        );
    }

    public function verify(Account $account, string $password): bool
    {
        $hash = $this->hash($account);
        if ($hash === null || \strlen($password) > NativePasswordHasher::MAX_PASSWORD_LENGTH || !$this->hasher->verify($hash, $password)) {
            return false;
        }
        if ($this->hasher->needsRehash($hash)) {
            $this->set($account, $password);
        }

        return true;
    }

    private function hash(Account $account): ?string
    {
        $hash = $this->directory->fetchOne('SELECT hash FROM owner_password WHERE account_id = :account', ['account' => $account->getId()]);

        return \is_string($hash) ? $hash : null;
    }
}
