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
 * revoked token stops reaching the vault at once. A token the owner made is
 * also kept encrypted on the connection ({@see ConnectionSecrets}).
 */
final class BearerTokens
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'doctrine.dbal.directory_connection')]
        private readonly Connection $directory,
        private readonly VaultContext $context,
        private readonly GrowthLimits $growth,
        private readonly ConnectionSecrets $secrets,
    ) {
    }

    /**
     * A route already on the new connection's number was left by an issue
     * whose vault commit failed after its route was written: SQLite hands the
     * rolled-back number out again, and that token was never shown to anyone.
     * It is replaced.
     *
     * @param (callable(Connection): void)|null $alongside directory writes that stand or fall with the route
     * @param bool $keep whether the token is kept for the owner to copy again; an OAuth client's is not
     *
     * @return array{0: ApiToken, 1: string} the connection, and the plaintext token
     */
    public function issue(Account $account, string $name, ?callable $alongside = null, bool $keep = false): array
    {
        $this->assertBound($account);
        $this->growth->assertConnectionRoom();

        return $this->em->wrapInTransaction(function () use ($account, $name, $alongside, $keep): array {
            $plaintext = self::mint();
            $token = new ApiToken($name);
            if ($keep) {
                $this->secrets->keep($token, $plaintext);
            }
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

    /**
     * A new token for a live connection, kept like one just made; the old one
     * stops reaching the vault when the route moves to the new hash.
     */
    public function reissue(Account $account, ApiToken $token): string
    {
        $this->assertBound($account);

        return $this->em->wrapInTransaction(function () use ($account, $token): string {
            $plaintext = self::mint();
            $this->secrets->keep($token, $plaintext);
            $this->em->flush();
            $moved = $this->directory->update(
                'bearer_tokens',
                ['token_hash' => self::hash($plaintext), 'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
                ['account_id' => $account->getId(), 'connection_id' => $token->getId()],
            );
            if ($moved !== 1) {
                throw new \LogicException('A connection without a route has no token to replace.');
            }

            return $plaintext;
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

    private static function mint(): string
    {
        return 'mxt_'.bin2hex(random_bytes(20));
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
