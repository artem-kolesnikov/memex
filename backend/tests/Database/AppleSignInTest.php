<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Directory\Account;
use App\Directory\Identity;
use App\Service\SocialProviders;
use App\Storage\DataDir;
use App\Tests\Support\DoorHeldOpen;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sign in with Apple, which is the same policy as the other three providers
 * reached down a different road.
 *
 * Apple is the only provider that answers with a form POST, and the only one
 * whose client secret this server signs. Everything unusual here follows from
 * one of those two facts.
 *
 * **What this file cannot prove.** The reason for the POST-to-GET bridge is
 * that a cross-site POST does not carry a `SameSite=lax` session cookie, and a
 * test client has no cross-site: it sends the cookie either way. So these tests
 * establish that the bridge works and that the round trip completes; only a
 * real browser at appleid.apple.com establishes that it was needed. It was — a
 * callback reading state from a session it cannot see refuses every sign-in
 * with `?error=state`.
 */
class AppleSignInTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Who may have an account is the edition's, and none of this is about that.
        DoorHeldOpen::hold();
    }

    protected function tearDown(): void
    {
        DoorHeldOpen::release();
        parent::tearDown();
    }

    /**
     * Where a completed sign-in lands, matching SocialAuthController::HOME.
     *
     * Written as a constant after these assertions went stale without failing:
     * they asserted `/`, which was the app's home until the landing page took
     * that path on 2026-08-30, and they stayed green because they compare the
     * redirect STRING and nothing in this suite routes it through nginx.
     * A backend that sent people to the marketing page after a successful
     * sign-in would have passed every one of them.
     */
    /**
     * Where a completed sign-in should land: the notes list of the knowledge
     * base the identity that just signed in belongs to.
     *
     * Resolved from the provider SUBJECT rather than written out, because
     * every page moved inside the vault's handle and a handle is minted per
     * account — so there is no one string to assert any more. Asserting the
     * shape instead would accept a redirect into somebody else's knowledge
     * base, which is the one failure this destination could have.
     */
    /**
     * A new account landed in a knowledge base of its OWN.
     *
     * The destination helpers read the vault off the account under test, so
     * they would assert a cross-tenant assignment against itself: give the new
     * account fixture vault B and `homeOfEmail()` cheerfully expects B's
     * address. This says the vault is neither fixture vault, exists, and
     * belongs to nobody else.
     */
    private function assertOwnKnowledgeBase(Account $account): void
    {
        $key = $account->getVaultKey();
        self::assertNotSame($this->kb->a->accountId, $account->getId(), 'A new account is fixture account A');
        self::assertNotSame($this->kb->b->accountId, $account->getId(), 'A new account is fixture account B');
        self::assertNotSame($this->kb->a->account()->getVaultKey(), $key, 'A new account joined fixture vault A');
        self::assertNotSame($this->kb->b->account()->getVaultKey(), $key, 'A new account joined fixture vault B');
        self::assertNotSame($this->kb->a->handle(), $account->getHandle());
        self::assertNotSame($this->kb->b->handle(), $account->getHandle());
        self::assertFileExists(static::getContainer()->get(DataDir::class)->vaultPath($key), 'A new account has no vault');
        self::assertSame(
            [1, $account->getId()],
            array_map('intval', array_values((array) static::getContainer()->get('doctrine.dbal.directory_connection')->fetchAssociative(
                'SELECT count(*) AS n, min(id) AS owner FROM accounts WHERE vault_key = :key',
                ['key' => $key]
            ))),
            'A new knowledge base holds exactly its own owner and nobody else'
        );
    }

    private function homeOfSubject(string $codeOrSubject): string
    {
        $subject = explode('~', $codeOrSubject)[0];
        $identity = $this->directory()->getRepository(Identity::class)->findOneBy(['subject' => $subject]);
        self::assertNotNull($identity, 'No identity for subject '.$subject);

        return '/'.$identity->getAccount()->getHandle().'/notes';
    }

    private function directory(): EntityManagerInterface
    {
        $directory = static::getContainer()->get('doctrine.orm.directory_entity_manager');
        $directory->clear();

        return $directory;
    }

    /**
     * The round trip as Apple drives it: start, read the state out of the
     * redirect, then POST back the way a form on Apple's page does.
     *
     * @return string the SPA path the callback finally redirected to
     */
    private function signInWithApple(string $code, ?string $user = null, bool $link = false): string
    {
        $query = array_filter(['link' => $link ? '1' : null]);
        $this->client->request('GET', '/api/auth/apple/start'.($query === [] ? '' : '?'.http_build_query($query)));

        $authorize = (string) $this->client->getResponse()->headers->get('Location');
        if (!str_contains($authorize, 'state=')) {
            return $authorize;
        }
        parse_str((string) parse_url($authorize, PHP_URL_QUERY), $params);

        $this->client->request('POST', '/api/auth/apple/callback', array_filter([
            'code' => $code,
            'state' => $params['state'],
            'user' => $user,
        ]));

        self::assertSame(303, $this->httpStatus(), 'Apple POSTs; the bridge must hand it back to ourselves as a GET.');
        $bridged = (string) $this->client->getResponse()->headers->get('Location');
        $this->client->request('GET', $bridged);

        return (string) $this->client->getResponse()->headers->get('Location');
    }

    private function userByEmail(string $email): ?Account
    {
        return $this->directory()->getRepository(Account::class)->findOneBy(['email' => $email]);
    }

    // ------------------------------------------------------------ the round trip

    /**
     * `response_mode=form_post` is not decoration: Apple refuses to return
     * `name` or `email` any other way, and those are the two things an account
     * cannot be created without.
     */
    public function testAppleIsAskedForTheAnswerAsAFormPost(): void
    {
        $this->client->request('GET', '/api/auth/apple/start');
        parse_str(
            (string) parse_url((string) $this->client->getResponse()->headers->get('Location'), PHP_URL_QUERY),
            $params
        );

        self::assertSame('form_post', $params['response_mode'] ?? null);
        self::assertSame('name email', $params['scope'] ?? null);
        self::assertSame('test-apple-client', $params['client_id'] ?? null, 'The Services ID, not the App ID.');
        self::assertStringEndsWith('/api/auth/apple/callback', $params['redirect_uri'] ?? '');
    }

    public function testAnAppleAccountOpensAKnowledgeBase(): void
    {
        $landing = $this->signInWithApple('apple-sub-1~bea@example.test');

        self::assertSame($this->homeOfSubject('apple-sub-1'), $landing);
        $user = $this->userByEmail('bea@example.test');
        self::assertNotNull($user);
        $this->assertOwnKnowledgeBase($user);

        $this->sessionRequest('GET', '/api/me');
        self::assertSame(200, $this->httpStatus());
        self::assertSame('bea@example.test', $this->jsonResponse()['email']);
    }

    /**
     * Apple sends the name in a form field on the FIRST authorization and never
     * again — not in the id_token, and not on any later sign-in. Losing it here
     * means the account is named after its address forever, which for a Hide My
     * Email account is a string of random characters.
     */
    public function testTheNameApplePostsOnceBecomesTheAccountName(): void
    {
        $this->signInWithApple(
            'apple-sub-2~cal@example.test',
            json_encode(['name' => ['firstName' => 'Cal', 'lastName' => 'Example'], 'email' => 'cal@example.test'], JSON_THROW_ON_ERROR)
        );

        $cal = $this->userByEmail('cal@example.test');
        self::assertSame('Cal Example', $cal?->getName());
        $this->assertOwnKnowledgeBase($cal);
    }

    public function testASecondSignInWithoutTheNameStillWorks(): void
    {
        $this->signInWithApple('apple-sub-3~dee@example.test', json_encode(['name' => ['firstName' => 'Dee', 'lastName' => 'Example']], JSON_THROW_ON_ERROR));
        $this->client->request('POST', '/api/logout');

        // No `user` payload, which is every sign-in after the first.
        self::assertSame($this->homeOfSubject('apple-sub-3'), $this->signInWithApple('apple-sub-3~dee@example.test'));
        self::assertSame('Dee Example', $this->userByEmail('dee@example.test')?->getName(), 'The name from the first time is not overwritten by its absence.');
    }

    /**
     * Rubbish in the one-time payload is not a reason to refuse somebody a
     * knowledge base. It is a display name.
     */
    public function testAnUnreadableNamePayloadCostsTheNameAndNothingElse(): void
    {
        // The landing FIRST: this sign-in is what creates the account, and an
        // expectation computed from it has nothing to look up yet.
        $landing = $this->signInWithApple('apple-sub-4~eli@example.test', '{not json at all');
        self::assertSame($this->homeOfSubject('apple-sub-4'), $landing);
        $eli = $this->userByEmail('eli@example.test');
        self::assertSame('eli', $eli?->getName());
        $this->assertOwnKnowledgeBase($eli);
    }

    // ------------------------------------------------------------------ Apple's own

    /**
     * Hide My Email is Apple's alone: a real, verified, forwarding address that
     * happens to be an alias. It opens an account like any other — the reason
     * it is worth a test is that memex cannot currently SEND to one, and the
     * temptation is to refuse it rather than record it.
     */
    public function testAHideMyEmailAliasOpensAnAccount(): void
    {
        $landing = $this->signInWithApple('apple-sub-5~fin_abc123@privaterelay.appleid.com');

        self::assertSame($this->homeOfSubject('apple-sub-5'), $landing);
        $fin = $this->userByEmail('fin_abc123@privaterelay.appleid.com');
        self::assertNotNull($fin);
        $this->assertOwnKnowledgeBase($fin);
    }

    public function testAnUnverifiedAppleAddressOpensNothing(): void
    {
        // `!` is the stub asking for email_verified: "false".
        self::assertSame('/login?error=no_email', $this->signInWithApple('apple-sub-6~!gus@example.test'));
        self::assertNull($this->userByEmail('gus@example.test'));
    }

    /**
     * An id_token minted for a different Services ID. Cheap to check, and it
     * catches two registrations crossed in `.env.local` — the failure that
     * otherwise presents as somebody signing into the wrong knowledge base.
     */
    public function testATokenMintedForAnotherServicesIdIsRefused(): void
    {
        self::assertSame('/login?error=provider', $this->signInWithApple('wrong-audience~hal@example.test'));
        self::assertNull($this->userByEmail('hal@example.test'));
    }

    /**
     * The stub refuses any client secret that is not a JWT naming the Services
     * ID, so a successful sign-in anywhere in this file is also proof that the
     * secret was really signed. This asserts it directly, because it is the one
     * property no other provider has.
     */
    public function testTheClientSecretIsSignedRatherThanConfigured(): void
    {
        $providers = static::getContainer()->get(SocialProviders::class);

        $first = $providers->clientSecret(SocialProviders::APPLE);
        $second = $providers->clientSecret(SocialProviders::APPLE);

        self::assertCount(3, explode('.', $first));
        self::assertNotSame($first, $second, 'Minted per request: two calls cannot be the same token.');
    }

    // ---------------------------------------------------------------- the bridge

    /**
     * The bridge exists to be POSTed to. `{provider}/callback` matches the same
     * path and accepts GET only, and a path that matches with the wrong method
     * has answered 405 in this application before — which reads exactly like a
     * missing route.
     */
    public function testTheCallbackAcceptsAPostAtAll(): void
    {
        $this->client->request('POST', '/api/auth/apple/callback', ['code' => 'x', 'state' => 'y']);

        self::assertSame(303, $this->httpStatus());
    }

    /**
     * The bridge runs before anything is validated, so it must carry nothing it
     * was not designed to carry and go nowhere but back to itself.
     */
    public function testTheBridgeGoesNowhereButBackToItself(): void
    {
        $this->client->request('POST', '/api/auth/apple/callback', [
            'code' => 'abc',
            'state' => 'def',
            'user' => '{"name":{"firstName":"Ida"}}',
            'next' => 'https://evil.test/',
            'anything' => 'else',
        ]);

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('/api/auth/apple/callback?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $carried);
        self::assertSame(['code', 'state', 'user'], array_keys($carried));
    }

    public function testAForgedStateIsRefused(): void
    {
        $this->client->request('GET', '/api/auth/apple/start');

        $this->client->request('POST', '/api/auth/apple/callback', ['code' => 'apple-sub-7~jo@example.test', 'state' => 'made-up']);
        $this->client->request('GET', (string) $this->client->getResponse()->headers->get('Location'));

        self::assertSame('/login?error=state', $this->client->getResponse()->headers->get('Location'));
        self::assertNull($this->userByEmail('jo@example.test'));
    }

    public function testCancellingAtAppleSaysSoAndCreatesNothing(): void
    {
        $this->client->request('GET', '/api/auth/apple/start');

        $this->client->request('POST', '/api/auth/apple/callback', ['error' => 'user_cancelled_authorize']);
        $this->client->request('GET', (string) $this->client->getResponse()->headers->get('Location'));

        self::assertSame('/login?error=provider', $this->client->getResponse()->headers->get('Location'));
    }
}
