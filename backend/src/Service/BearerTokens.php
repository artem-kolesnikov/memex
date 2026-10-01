<?php

declare(strict_types=1);

namespace App\Service;

use App\Directory\Account;
use App\Entity\ApiToken;
use App\Storage\VaultContext;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A connection's bearer token lives in two places: the connection in the
 * vault, and the token's hash in the directory, which is all that routes a
 * request to that vault. Issuing takes the vault's write lock, then the
 * directory's, the one order every issue follows, and commits the connection
 * only once its route is written; revoking removes the route first, so a
 * revoked token stops reaching the vault at once.
 */
final class BearerTokens
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'doctrine.dbal.directory_connection')]
        private readonly Connection $directory,
        private readonly VaultContext $context,
        private readonly GrowthLimits $growth,
    ) {
    }

    /**
     * A route already on the new connection's number was left by an issue
     * whose vault commit failed after its route was written: SQLite hands the
     * rolled-back number out again, and that token was never shown to anyone.
     * It is replaced.
     *
     * @param (callable(Connection): void)|null $alongside directory writes that stand or fall with the route
     *
     * @return array{0: ApiToken, 1: string} the connection, and the plaintext token shown once
     */
    public function issue(Account $account, string $name, ?callable $alongside = null): array
    {
        $this->assertBound($account);
        $this->growth->assertConnectionRoom();

        return $this->em->wrapInTransaction(function () use ($account, $name, $alongside): array {
            $plaintext = 'mxt_'.bin2hex(random_bytes(20));
            $token = new ApiToken($name);
            $this->em->persist($token);
            $this->em->flush();
            $this->directory->transactional(function (Connection $directory) use ($account, $token, $plaintext, $alongside): void {
                if ($alongside !== null) {
                    $alongside($directory);
                }
                $directory->executeStatement(
                    'INSERT INTO bearer_tokens (token_hash, account_id, connection_id, created_at) VALUES (:hash, :account, :connection, :now)
                     ON CONFLICT (account_id, connection_id) DO UPDATE SET token_hash = excluded.token_hash, created_at = excluded.created_at',
                    [
                        'hash' => self::hash($plaintext),
                        'account' => $account->getId(),
                        'connection' => $token->getId(),
                        'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    ],
                );
            });

            return [$token, $plaintext];
        });
    }

    public function revoke(Account $account, ApiToken $token): void
    {
        $this->assertBound($account);
        $this->directory->delete('bearer_tokens', ['account_id' => $account->getId(), 'connection_id' => $token->getId()]);
        $token->revoke();
        $this->em->flush();
    }

    /**
     * @return array{account_id: int, connection_id: int}|null
     */
    public function route(string $plaintext): ?array
    {
        $row = $this->directory->fetchAssociative(
            'SELECT account_id, connection_id FROM bearer_tokens WHERE token_hash = :hash',
            ['hash' => self::hash($plaintext)],
        );

        return $row === false ? null : ['account_id' => (int) $row['account_id'], 'connection_id' => (int) $row['connection_id']];
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    private function assertBound(Account $account): void
    {
        if (!$this->context->current()->is($account->vault())) {
            throw new \LogicException('A token is issued or revoked from inside its own vault.');
        }
    }
}
