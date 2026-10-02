<?php

declare(strict_types=1);

namespace App\Tests\Standalone;

use App\Directory\Account;
use App\Directory\Identity;
use App\Service\AccountDoor;
use App\Service\BearerTokens;
use App\Standalone\FirstRun;
use App\Storage\VaultScope;
use App\Tests\Support\TestData;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Memex Standalone's one account: made at first run, without a code for ten
 * minutes and with the setup code after, opened with its password, and the
 * only one there is.
 */
final class OwnerSignInTest extends WebTestCase
{
    private const EMAIL = 'owner@example.org';
    private const PASSWORD = 'correct horse battery staple';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        static::ensureKernelShutdown();
        TestData::fresh();
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->get('test.cache.rate_limiter')->clear();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TestData::discard();
    }

    public function testForTenMinutesAfterMemexStartsFirstRunTakesNoCode(): void
    {
        $this->firstRun()->code();
        self::assertSame(['owner' => false, 'code' => false], $this->json('GET', '/api/auth/owner'));

        $answer = $this->json('POST', '/api/auth/owner', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
        self::assertSame($this->firstRun()->owner()?->path(), $answer['redirect']);
        $me = $this->json('GET', '/api/me');
        self::assertSame(self::EMAIL, $me['email']);
        self::assertFalse($me['web_assistants'], 'local-first: ChatGPT, Claude and Gemini connect to memex.tools');
        self::assertFalse(static::getContainer()->getParameter('memex.social_sign_in'), 'the owner signs in with a password, never a provider');
        self::assertFalse($this->directory()->fetchOne('SELECT 1 FROM setup_code'), 'the code is spent');
    }

    public function testAfterTenMinutesFirstRunTakesTheSetupCodeAndSignsIn(): void
    {
        $code = $this->firstRun()->code();
        $this->directory()->executeStatement('UPDATE setup_code SET created_at = :at', [
            'at' => (new \DateTimeImmutable('-'.(FirstRun::OPEN_FOR_MINUTES + 1).' minutes'))->format('Y-m-d H:i:s'),
        ]);
        self::assertSame(['owner' => false, 'code' => true], $this->json('GET', '/api/auth/owner'));

        self::assertSame('code_needed', $this->json('POST', '/api/auth/owner', ['email' => self::EMAIL, 'password' => self::PASSWORD])['error']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('code', $this->json('POST', '/api/auth/owner', ['code' => 'AAAA-BBBB-CCCC', 'email' => self::EMAIL, 'password' => self::PASSWORD])['error']);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->firstRun()->owner());

        $answer = $this->json('POST', '/api/auth/owner', ['code' => strtolower($code), 'email' => self::EMAIL, 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
        $owner = $this->firstRun()->owner();
        self::assertNotNull($owner);
        self::assertSame($owner->path(), $answer['redirect']);
        self::assertSame(self::EMAIL, $this->json('GET', '/api/me')['email']);
        self::assertTrue($this->json('GET', '/api/auth/owner')['owner']);
        self::assertFalse($this->directory()->fetchOne('SELECT 1 FROM setup_code'), 'the code is spent');
    }

    public function testAnEmptyPasswordCreatesNothingAndKeepsTheCode(): void
    {
        $code = $this->firstRun()->code();

        self::assertSame('password_empty', $this->json('POST', '/api/auth/owner', ['code' => $code, 'email' => self::EMAIL, 'password' => ''])['error']);
        self::assertResponseStatusCodeSame(400);
        self::assertNull($this->firstRun()->owner());
        self::assertTrue($this->firstRun()->matches($code));
    }

    public function testThereIsOnlyEverOneAccount(): void
    {
        $this->owner();

        self::assertSame('owner_exists', $this->json('POST', '/api/auth/owner', ['code' => 'ANYTHING', 'email' => 'second@example.org', 'password' => self::PASSWORD])['error']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('one_owner', static::getContainer()->get(AccountDoor::class)->newcomerRefusal(null)?->reason, 'a sign-in provider opens no second account');
        self::assertSame(1, (int) $this->directory()->fetchOne('SELECT COUNT(*) FROM accounts'));
    }

    public function testThePasswordSignsInAndReturnsOnlyToTheConsentPage(): void
    {
        $owner = $this->owner();

        self::assertSame('credentials', $this->json('POST', '/api/auth/password', ['email' => self::EMAIL, 'password' => 'not the password at all'])['error']);
        self::assertResponseStatusCodeSame(401);
        $this->client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(401);

        self::assertSame($owner->path(), $this->json('POST', '/api/auth/password', ['email' => 'OWNER@example.org', 'password' => self::PASSWORD])['redirect']);
        self::assertSame(self::EMAIL, $this->json('GET', '/api/me')['email']);

        $consent = '/oauth/authorize?client_id=abc&state=x';
        self::assertSame($consent, $this->json('POST', '/api/auth/password', ['email' => self::EMAIL, 'password' => self::PASSWORD, 'next' => $consent])['redirect']);
        self::assertSame($owner->path(), $this->json('POST', '/api/auth/password', ['email' => self::EMAIL, 'password' => self::PASSWORD, 'next' => '//evil.test/oauth/authorize'])['redirect']);
    }

    public function testGuessesFromOneAddressAreThrottled(): void
    {
        $this->owner();
        for ($i = 0; $i < 10; ++$i) {
            $this->json('POST', '/api/auth/password', ['email' => self::EMAIL, 'password' => 'guess number '.$i]);
            self::assertResponseStatusCodeSame(401);
        }

        $this->json('POST', '/api/auth/password', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(429);
    }

    public function testAFormOnAnotherSiteCannotPostIt(): void
    {
        $this->owner();
        $this->client->request('POST', '/api/auth/password', server: ['CONTENT_TYPE' => 'text/plain'], content: json_encode(['email' => self::EMAIL, 'password' => self::PASSWORD]));

        self::assertResponseStatusCodeSame(415);
    }

    public function testWithAPasswordTheLastProviderCanBeRemoved(): void
    {
        $owner = $this->owner();
        $identity = new Identity($owner, 'github', '4242', self::EMAIL);
        $em = static::getContainer()->get('doctrine.orm.directory_entity_manager');
        $em->persist($identity);
        $em->flush();
        $this->signIn();

        $this->directory()->executeStatement('DELETE FROM owner_password');
        self::assertFalse($this->json('GET', '/api/me/identities')['own_way_in']);
        $this->json('DELETE', '/api/me/identities/'.$identity->getRef());
        self::assertResponseStatusCodeSame(400);

        $this->directory()->executeStatement("INSERT INTO owner_password (account_id, hash, changed_at) VALUES (:id, 'x', '2026-09-30 00:00:00')", ['id' => $owner->getId()]);
        self::assertTrue($this->json('GET', '/api/me/identities')['own_way_in']);
        $this->json('DELETE', '/api/me/identities/'.$identity->getRef());
        self::assertResponseIsSuccessful();
    }

    public function testChangingThePasswordTakesTheCurrentOneAndSignsOutOtherBrowsers(): void
    {
        $this->owner();
        $this->signIn();
        $elsewhere = $this->client->getCookieJar()->all();
        $this->client->restart();
        $this->signIn();

        $this->json('PUT', '/api/me/password', ['current' => 'not the password at all', 'password' => 'an entirely new passphrase']);
        self::assertResponseStatusCodeSame(403);
        $this->json('PUT', '/api/me/password', ['current' => self::PASSWORD, 'password' => 'an entirely new passphrase']);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();

        $this->client->restart();
        foreach ($elsewhere as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }
        $this->client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(401);

        $this->client->restart();
        $this->json('POST', '/api/auth/password', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(401);
        $this->json('POST', '/api/auth/password', ['email' => self::EMAIL, 'password' => 'an entirely new passphrase']);
        self::assertResponseIsSuccessful();
    }

    public function testAnAssistantsTokenCannotChangeThePassword(): void
    {
        $owner = $this->owner();
        [, $token] = static::getContainer()->get(VaultScope::class)->run(
            $owner->vault(),
            static fn (): array => static::getContainer()->get(BearerTokens::class)->issue($owner, 'An assistant'),
        );

        $this->client->request('PUT', '/api/me/password', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'], content: json_encode(['current' => self::PASSWORD, 'password' => 'an entirely new passphrase']));

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeletingTheAccountReturnsTheServerToFirstRun(): void
    {
        $this->owner();
        $this->signIn();

        $this->json('DELETE', '/api/me', ['confirm_email' => self::EMAIL]);
        self::assertResponseIsSuccessful();

        self::assertFalse($this->json('GET', '/api/auth/owner')['owner']);
        self::assertFalse($this->directory()->fetchOne('SELECT 1 FROM owner_password'));
    }

    private function owner(): Account
    {
        return $this->firstRun()->createOwner(self::EMAIL, self::PASSWORD);
    }

    private function signIn(): void
    {
        $this->json('POST', '/api/auth/password', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    private function firstRun(): FirstRun
    {
        static::getContainer()->get('doctrine.orm.directory_entity_manager')->clear();

        return static::getContainer()->get(FirstRun::class);
    }

    private function directory(): Connection
    {
        return static::getContainer()->get('doctrine.dbal.directory_connection');
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $uri, ?array $body = null): array
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
