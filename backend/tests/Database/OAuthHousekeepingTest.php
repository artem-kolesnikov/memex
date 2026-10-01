<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Command\PurgeOAuthCommand;
use App\Entity\ApiToken;
use App\Service\BearerTokens;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The two tables behind open client registration, and what keeps them bounded.
 *
 * `/oauth/register` is unauthenticated by design — RFC 7591, and claude.ai
 * registers itself without being invited. That is correct and stays. What was
 * missing is everything on the other side of it: nothing limited the rate, and
 * nothing ever deleted a row. Three consecutive audits filed it (2026-08-21
 * twice, 2026-08-24) and each time it was left because the damage ceiling is
 * "only" table growth.
 *
 * The tests that matter here are the ones about what is NOT swept. A sweeper
 * that deletes the registration behind somebody's working connection is worse
 * than the unbounded growth it fixes.
 */
final class OAuthHousekeepingTest extends ApiTestCase
{
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    /** @return array{client_id: string, redirect_uri: string} */
    private function registerClient(string $name = 'Test Client'): array
    {
        $redirectUri = 'https://oauth-redirect.googleusercontent.com/r/test';
        $this->client->request(
            'POST',
            '/oauth/register',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['client_name' => $name, 'redirect_uris' => [$redirectUri]], JSON_THROW_ON_ERROR)
        );

        return ['client_id' => $this->jsonResponse()['client_id'], 'redirect_uri' => $redirectUri];
    }

    /** @param array<string, mixed> $input */
    private function runCommand(string $name, array $input = []): string
    {
        $tester = new CommandTester((new Application(self::$kernel))->find($name));
        $tester->execute($input);

        return $tester->getDisplay();
    }

    private function purge(): string
    {
        return $this->runCommand('app:purge-oauth');
    }

    private function clientCount(string $clientId): int
    {
        return (int) $this->directory()->fetchOne(
            'SELECT COUNT(*) FROM oauth_clients WHERE client_id = ?',
            [$clientId]
        );
    }

    private function age(string $clientId, int $days): void
    {
        $this->directory()->executeStatement(
            'UPDATE oauth_clients SET created_at = ? WHERE client_id = ?',
            [self::at("-$days days"), $clientId]
        );
    }

    private static function at(string $offset): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify($offset)->format('Y-m-d H:i:s');
    }

    private function directory(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.directory_connection');
    }

    // --- the rate limit ---------------------------------------------------

    public function testRegistrationStopsAcceptingAfterTheHourlyLimit(): void
    {
        // Twenty is above any honest sequence — a developer fixing a
        // redirect_uri mistake, an operator connecting several assistants in
        // one sitting. The twenty-first from the same address is a script.
        for ($i = 0; $i < 20; ++$i) {
            $this->registerClient('Client '.$i);
            self::assertSame(201, $this->httpStatus(), "registration $i was refused early");
        }

        $this->client->request(
            'POST',
            '/oauth/register',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['client_name' => 'One too many', 'redirect_uris' => ['https://example.test/cb']], JSON_THROW_ON_ERROR)
        );

        self::assertSame(429, $this->httpStatus(), 'open registration is still unbounded');
        // NOT `invalid_client_metadata`. That code means "your metadata is
        // wrong", which invites a well-behaved client to fix its request and
        // retry immediately — the opposite of what a rate limit wants.
        self::assertSame('temporarily_unavailable', $this->jsonResponse()['error']);
        // And say WHEN, or "later" is the client's guess to make.
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    // --- what the sweeper deletes ----------------------------------------

    public function testAnExpiredAuthorizationCodeIsSwept(): void
    {
        $client = $this->registerClient();
        $this->directory()->executeStatement(
            'INSERT INTO oauth_codes (code, client_id, account_id, redirect_uri, code_challenge, expires_at, created_at)
             VALUES (:code, :client, :account, :uri, :challenge, :expires, :created)',
            [
                'code' => 'mxa_expired', 'client' => $client['client_id'], 'account' => $this->kb->a->accountId,
                'uri' => $client['redirect_uri'], 'challenge' => self::CHALLENGE,
                'expires' => self::at('-1 hour'), 'created' => self::at('-2 hours'),
            ]
        );

        $this->purge();

        self::assertSame(
            0,
            (int) $this->directory()->fetchOne('SELECT COUNT(*) FROM oauth_codes WHERE code = ?', ['mxa_expired']),
            'an expired code cannot be redeemed by anyone and is pure residue'
        );
    }

    public function testALiveAuthorizationCodeIsLeftAlone(): void
    {
        $client = $this->registerClient();
        $this->directory()->executeStatement(
            'INSERT INTO oauth_codes (code, client_id, account_id, redirect_uri, code_challenge, expires_at, created_at)
             VALUES (:code, :client, :account, :uri, :challenge, :expires, :created)',
            [
                'code' => 'mxa_live', 'client' => $client['client_id'], 'account' => $this->kb->a->accountId,
                'uri' => $client['redirect_uri'], 'challenge' => self::CHALLENGE,
                'expires' => self::at('+9 minutes'), 'created' => self::at('now'),
            ]
        );

        $this->purge();

        // Somebody is mid-connection. Sweeping this is a connect that fails
        // for no reason the person can see.
        self::assertSame(
            1,
            (int) $this->directory()->fetchOne('SELECT COUNT(*) FROM oauth_codes WHERE code = ?', ['mxa_live'])
        );
    }

    public function testARegistrationNobodyEverUsedIsSweptOnceItIsOldEnough(): void
    {
        $client = $this->registerClient();
        $this->age($client['client_id'], PurgeOAuthCommand::UNUSED_CLIENT_DAYS + 1);

        $this->purge();

        self::assertSame(0, $this->clientCount($client['client_id']));
    }

    public function testARecentUnusedRegistrationIsGivenTimeToBeUsed(): void
    {
        // Registering and finishing consent can honestly span days: somebody
        // sets a client up, gets interrupted, comes back at the weekend.
        $client = $this->registerClient();
        $this->age($client['client_id'], PurgeOAuthCommand::UNUSED_CLIENT_DAYS - 1);

        $this->purge();

        self::assertSame(1, $this->clientCount($client['client_id']));
    }

    // --- what the sweeper must NEVER delete ------------------------------

    public function testAConnectionSomebodyActuallyUsesSurvivesForever(): void
    {
        // The test this whole feature is for. A client registered long ago and
        // connected once is somebody's working assistant; deleting it to save
        // a few hundred bytes would break it to fix nothing.
        $client = $this->registerClient();
        $token = $this->connect($client);
        self::assertSame(ApiToken::ROLE_AGENT, $token->getRole(), 'the flow did not actually complete');

        $this->age($client['client_id'], PurgeOAuthCommand::UNUSED_CLIENT_DAYS * 100);
        $this->purge();

        self::assertSame(1, $this->clientCount($client['client_id']), 'a used registration was swept');
    }

    public function testTheTokenExchangeIsWhatMarksAClientUsed(): void
    {
        $client = $this->registerClient();
        self::assertNull(
            $this->directory()->fetchOne('SELECT last_used_at FROM oauth_clients WHERE client_id = ?', [$client['client_id']]),
            'a bare registration has not been used by anyone yet'
        );

        $this->connect($client);

        self::assertNotNull(
            $this->directory()->fetchOne('SELECT last_used_at FROM oauth_clients WHERE client_id = ?', [$client['client_id']])
        );
    }

    public function testDryRunChangesNothing(): void
    {
        $client = $this->registerClient();
        $this->age($client['client_id'], PurgeOAuthCommand::UNUSED_CLIENT_DAYS + 1);

        $output = $this->runCommand('app:purge-oauth', ['--dry-run' => true]);

        self::assertStringContainsString('would be deleted', $output);
        self::assertSame(1, $this->clientCount($client['client_id']), 'a dry run deleted something');
    }

    /** Sign in, consent, exchange — the real path, as OAuthGrantTest drives it. */
    private function connect(array $client): ApiToken
    {
        $this->loginAs($this->kb->a);
        $this->client->followRedirects(false);
        $query = [
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => $client['redirect_uri'],
            'code_challenge' => self::CHALLENGE,
            'code_challenge_method' => 'S256',
        ];
        $uri = '/oauth/authorize?'.http_build_query($query);

        $this->client->request('GET', $uri);
        $page = (string) $this->client->getResponse()->getContent();
        preg_match('/name="_csrf" value="([^"]+)"/', $page, $m);

        $this->client->request('POST', $uri, ['_csrf' => $m[1] ?? '']);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);

        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => (string) $params['code'],
            'redirect_uri' => $client['redirect_uri'],
            'client_id' => $client['client_id'],
            'code_verifier' => 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk',
        ]);

        $plaintext = $this->jsonResponse()['access_token'];
        $connectionId = (int) $this->directory()->fetchOne(
            'SELECT connection_id FROM bearer_tokens WHERE token_hash = ? AND account_id = ?',
            [BearerTokens::hash($plaintext), $this->kb->a->accountId]
        );
        $this->in($this->kb->a);

        return $this->em->find(ApiToken::class, $connectionId);
    }
}
