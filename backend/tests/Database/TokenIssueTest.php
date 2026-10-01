<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\BearerTokens;
use App\Tests\Support\DrivesOAuthFlow;
use App\Tests\Support\SqlObservation;
use App\Tests\Support\Tenant;
use Doctrine\DBAL\Connection;

/**
 * Issuing a connection writes the vault and the directory. Every path takes
 * the vault's write lock first, so two issues for one account queue rather
 * than hold one lock each; and a route left behind by an issue whose vault
 * commit failed does not block the next.
 */
final class TokenIssueTest extends ApiTestCase
{
    use DrivesOAuthFlow;

    public function testAnOAuthExchangeLocksTheVaultBeforeTheDirectory(): void
    {
        $client = $this->registerClient();
        $this->loginAs($this->kb->a);
        $code = $this->consent($client);

        $locked = $this->vaultLockedAtEachDirectoryWrite($this->kb->a, fn () => $this->requestToken($code, $client));

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertNotEmpty($locked);
        self::assertNotContains(false, $locked, 'the directory was locked while the vault was free');
    }

    public function testCreatingAConnectionLocksTheVaultBeforeTheDirectory(): void
    {
        $this->loginAs($this->kb->a);

        $locked = $this->vaultLockedAtEachDirectoryWrite($this->kb->a, fn () => $this->sessionRequest('POST', '/api/tokens', ['name' => 'Laptop']));

        self::assertSame(201, $this->httpStatus(), $this->body());
        self::assertNotEmpty($locked);
        self::assertNotContains(false, $locked, 'the directory was locked while the vault was free');
    }

    public function testARouteLeftByAFailedIssueDoesNotBlockTheNext(): void
    {
        $vault = new \SQLite3($this->kb->a->vaultPath());
        $next = (int) $vault->querySingle("SELECT seq FROM sqlite_sequence WHERE name = 'api_tokens'") + 1;
        $vault->close();
        $this->directory()->insert('bearer_tokens', [
            'token_hash' => BearerTokens::hash('mxt_never-shown'),
            'account_id' => $this->kb->a->accountId,
            'connection_id' => $next,
            'created_at' => '2026-09-27 12:00:00',
        ]);
        $this->loginAs($this->kb->a);

        $this->sessionRequest('POST', '/api/tokens', ['name' => 'Laptop']);

        self::assertSame(201, $this->httpStatus(), $this->body());
        self::assertSame($next, (int) $this->jsonResponse()['id']);
        $tokens = static::getContainer()->get(BearerTokens::class);
        self::assertSame(['account_id' => $this->kb->a->accountId, 'connection_id' => $next], $tokens->route((string) $this->jsonResponse()['token']));
        self::assertNull($tokens->route('mxt_never-shown'));
    }

    public function testACodeMintsOneConnection(): void
    {
        $client = $this->registerClient();
        $this->loginAs($this->kb->a);
        $code = $this->consent($client);
        $this->exchange($code, $client);
        $this->leave();
        $routes = $this->routes();

        $this->requestToken($code, $client);

        self::assertSame(400, $this->httpStatus());
        self::assertSame('invalid_grant', $this->jsonResponse()['error'] ?? null);
        self::assertSame($routes, $this->routes());
    }

    /**
     * For each statement on the token tables that holds the directory's write
     * lock (one inside a transaction, or a write), whether the vault was
     * already write-locked.
     *
     * @return list<bool>
     */
    private function vaultLockedAtEachDirectoryWrite(Tenant $tenant, \Closure $work): array
    {
        $locked = [];
        SqlObservation::during(
            $this->directory(),
            static function (string $sql, array $params, ?int $transaction) use ($tenant, &$locked): void {
                $holdsLock = $transaction !== null || preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i', $sql) === 1;
                if ($holdsLock && preg_match('/\b(bearer_tokens|oauth_codes|oauth_clients)\b/', $sql) === 1) {
                    $locked[] = SqlObservation::isWriteLocked($tenant->vaultPath());
                }
            },
            $work,
        );

        return $locked;
    }

    private function routes(): int
    {
        return (int) $this->directory()->fetchOne('SELECT count(*) FROM bearer_tokens');
    }

    private function directory(): Connection
    {
        return static::getContainer()->get('doctrine.dbal.directory_connection');
    }
}
