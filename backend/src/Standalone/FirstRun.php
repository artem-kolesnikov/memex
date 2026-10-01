<?php

declare(strict_types=1);

namespace App\Standalone;

use App\Directory\Account;
use App\Service\AccountOpener;
use App\Service\SocialAuthException;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Creating the one account. For ten minutes after memex first starts, whoever
 * opens it creates the account; after that the form asks for a setup code only
 * whoever runs the server can read, because on a public server a later visitor
 * could be anybody. The code is printed by `app:setup-code`, which starting
 * memex runs, and the ten minutes count from its making (operator, 2026-10-01).
 */
final class FirstRun
{
    public const OPEN_FOR_MINUTES = 10;
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.directory_connection')]
        private readonly Connection $directory,
        private readonly EntityManagerInterface $directoryEntityManager,
        private readonly AccountOpener $opener,
        private readonly OwnerPassword $password,
    ) {
    }

    public function owner(): ?Account
    {
        return $this->directoryEntityManager->getRepository(Account::class)->findOneBy([], ['id' => 'ASC']);
    }

    /** The setup code, made the first time it is asked for. */
    public function code(): string
    {
        $code = '';
        for ($i = 0; $i < 12; ++$i) {
            $code .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
        }
        $this->directory->executeStatement(
            'INSERT OR IGNORE INTO setup_code (id, code, created_at) VALUES (1, :code, :at)',
            ['code' => $code, 'at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
        );

        return implode('-', str_split((string) $this->directory->fetchOne('SELECT code FROM setup_code WHERE id = 1'), 4));
    }

    /** Until when the account can be created without the code; null once that has passed. */
    public function openUntil(): ?\DateTimeImmutable
    {
        $this->code();
        $made = new \DateTimeImmutable((string) $this->directory->fetchOne('SELECT created_at FROM setup_code WHERE id = 1'));
        $until = $made->modify('+'.self::OPEN_FOR_MINUTES.' minutes');

        return $until > new \DateTimeImmutable() ? $until : null;
    }

    public function matches(string $given): bool
    {
        $code = $this->directory->fetchOne('SELECT code FROM setup_code WHERE id = 1');
        $given = strtoupper((string) preg_replace('/[\s-]+/', '', $given));

        return \is_string($code) && $given !== '' && hash_equals($code, $given);
    }

    /** @throws OwnerException */
    public function createOwner(string $email, string $password): Account
    {
        $email = trim($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || \strlen($email) > 180) {
            throw new OwnerException('email', 'That is not an email address.');
        }
        OwnerPassword::check($password);

        $name = explode('@', $email)[0];
        try {
            $account = $this->opener->open($email, $name, $name, null, static function (): void {
            });
        } catch (SocialAuthException $e) {
            throw new OwnerException($e->reason, $e->getMessage());
        }
        $this->password->set($account, $password);
        $this->directory->executeStatement('DELETE FROM setup_code');

        return $account;
    }
}
