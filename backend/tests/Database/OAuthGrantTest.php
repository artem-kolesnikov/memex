<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\ApiToken;
use App\Tests\Support\DrivesOAuthFlow;
use Doctrine\DBAL\Connection;

/**
 * The whole authorization flow, driven end to end, for one property:
 *
 * **No authorization ever mints a curator token.**
 *
 * This is a regression suite before it is anything else. The server used to
 * grant `scope=curator` on the argument that signing in on a page which states
 * the grant is the consent. Measured against real clients that argument failed:
 * ChatGPT and Gemini both request `scope=curator` unprompted, so connecting
 * Gemini on 2026-08-20 silently produced a token that could write to the
 * operator's knowledge base without review. He had asked for none of it.
 *
 * The old unit tests could not have caught it. They asserted that the helper
 * parsed a scope string correctly, which it did, and noted that the two-request
 * property "is verified by the scripted flow against prod, not here". It was
 * not. So this drives register → authorize → token against a real kernel and a
 * real database, and asks what actually came out.
 */
final class OAuthGrantTest extends ApiTestCase
{
    use DrivesOAuthFlow;

    public function testAClientAskingForCuratorGetsAnAgentToken(): void
    {
        $token = $this->connect('curator');

        self::assertSame(ApiToken::ROLE_AGENT, $token->getRole(), 'scope=curator must not mint a curator token');
    }

    public function testNoScopeGetsAnAgentTokenToo(): void
    {
        self::assertSame(ApiToken::ROLE_AGENT, $this->connect('')->getRole());
    }

    /**
     * Every shape a client might ask in. None of them is a way in, which is the
     * point: the answer no longer depends on parsing the request at all.
     *
     * @dataProvider askingShapes
     */
    public function testNoRequestedScopeIsAWayIn(string $scope): void
    {
        self::assertSame(ApiToken::ROLE_AGENT, $this->connect($scope)->getRole(), 'scope '.var_export($scope, true).' granted a role');
    }

    /** @return iterable<string, array{string}> */
    public static function askingShapes(): iterable
    {
        yield 'exact' => ['curator'];
        yield 'among others' => ['profile curator openid'];
        yield 'repeated' => ['curator curator'];
        yield 'admin as well' => ['curator admin owner'];
        yield 'tab separated' => ["profile\tcurator"];
    }

    public function testTheTokenResponseClaimsNoScope(): void
    {
        // A client that is told it holds `curator` will behave as though it
        // does. Saying nothing is the only honest answer now.
        $this->connect('curator');

        self::assertArrayNotHasKey('scope', $this->jsonResponse());
    }

    public function testTheAuthorizePageNeverPromisesCuratorAccess(): void
    {
        $client = $this->registerClient();
        $this->loginAs($this->kb->a);
        $this->client->request('GET', $this->authorizeUri($client, 'curator'));

        // The property is what the page PROMISES, not whether the word appears:
        // the notice legitimately names curator to say where it is granted
        // instead. What must never appear is the old claim that this
        // connection's writes skip review.
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('review inbox as pending', $page, 'the page must promise the review gate');
        self::assertStringNotContainsString('saved directly', $page, 'the page must not promise ungated writes');
        self::assertStringNotContainsString('asking for <strong>curator', $page, 'the page must not state a grant that cannot happen');
    }

    public function testTheServerAdvertisesNoScopes(): void
    {
        // Advertising one it will not honour is how a client comes to believe
        // it holds access it does not have.
        $this->client->request('GET', '/.well-known/oauth-authorization-server');

        self::assertArrayNotHasKey('scopes_supported', $this->jsonResponse());
    }

    // --- who may consent, and how (2026-08-21) ----------------------------

    public function testSomebodyNotSignedInIsSentToTheSignInScreenAndBack(): void
    {
        // The fix for the wall a social account used to hit here: this page had
        // its own password form, and an account opened with Google has no
        // password, so the first invited person following the Connections
        // instructions was told their own credentials were invalid.
        $client = $this->registerClient();
        $uri = $this->authorizeUri($client);

        $this->client->followRedirects(false);
        $this->client->request('GET', $uri);

        self::assertSame(302, $this->httpStatus());
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('/login?next=', $location, 'it must send them to the ONE sign-in screen, where the provider buttons are');
        // And back again, or they sign in and land nowhere useful.
        self::assertStringContainsString(rawurlencode($uri), $location);
    }

    public function testTheConsentPageAsksForNoPassword(): void
    {
        $client = $this->registerClient();
        $this->loginAs($this->kb->a);

        $this->client->request('GET', $this->authorizeUri($client));

        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('type="password"', $page, 'connecting an assistant must not demand a password');
        self::assertStringContainsString($this->kb->a->email, $page, 'it must say whose knowledge base is about to be connected');
    }

    public function testAForgedConsentCannotMintACode(): void
    {
        // The attack this token exists for, and it is not hypothetical:
        // /oauth/register is PUBLIC, so an attacker registers a client pointing
        // at their own server, then serves a page that auto-submits this form.
        // A signed-in victim's browser posts it, and the code goes to the
        // attacker — who holds the PKCE verifier, because they generated the
        // challenge. Session-as-credential is what made this reachable, so the
        // token had to arrive in the same change.
        $client = $this->registerClient();
        $this->loginAs($this->kb->a);

        $this->client->followRedirects(false);
        $this->client->request('POST', $this->authorizeUri($client), ['_csrf' => 'not-the-token']);

        self::assertSame(200, $this->httpStatus(), 'a forged consent must not redirect anywhere');
        self::assertSame(
            0,
            (int) $this->directory()->fetchOne(
                'SELECT COUNT(*) FROM oauth_codes WHERE client_id = :id',
                ['id' => $client['client_id']]
            ),
            'a forged consent minted an authorization code'
        );
    }

    public function testConsentIsNeverGrantedOnAGet(): void
    {
        // A Lax session cookie rides along on a top-level GET navigation, so a
        // GET that granted consent would be forgeable by a plain link.
        $client = $this->registerClient();
        $this->loginAs($this->kb->a);

        $this->client->followRedirects(false);
        $this->client->request('GET', $this->authorizeUri($client));

        self::assertSame(200, $this->httpStatus());
        self::assertSame(0, (int) $this->directory()->fetchOne(
            'SELECT COUNT(*) FROM oauth_codes WHERE client_id = :id',
            ['id' => $client['client_id']]
        ));
    }

    public function testAnAssistantCannotAuthorizeItsOwnSuccessor(): void
    {
        // A bearer token authenticates AS its owner and carries their roles, so
        // without this an assistant already connected could mint a second
        // connection for itself, with no person in the loop at any point.
        $client = $this->registerClient();

        $this->client->followRedirects(false);
        $this->client->request(
            'GET',
            $this->authorizeUri($client),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->kb->a->curatorBearer]
        );

        self::assertSame(302, $this->httpStatus());
        self::assertStringStartsWith('/login?next=', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testTheCodeBelongsToWhoeverIsSignedIn(): void
    {
        // The session decides the account now, so the wrong session must mean
        // the wrong knowledge base is never reachable rather than merely
        // unlikely.
        $client = $this->registerClient();
        $this->loginAs($this->kb->b);

        $this->client->followRedirects(false);
        $this->client->request('POST', $this->authorizeUri($client), [
            '_csrf' => $this->consentToken($client),
        ]);

        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $params);
        $accountId = (int) $this->directory()->fetchOne(
            'SELECT account_id FROM oauth_codes WHERE code = :code',
            ['code' => (string) $params['code']]
        );

        self::assertSame($this->kb->b->accountId, $accountId);
        self::assertNotSame($this->kb->a->accountId, $accountId);
    }

    // --- the flow ---------------------------------------------------------

    /**
     * Connect as a real client does: sign in to the site, consent, exchange.
     *
     * The sign-in is a separate step from 2026-08-21. The consent page used to
     * carry its own email-and-password form, which made it the one screen a
     * social account could not pass — an account opened with Google has no
     * password to type. It now authorizes on the browser session.
     */
    private function connect(string $scope): ApiToken
    {
        $client = $this->registerClient();
        $this->loginAs($this->kb->a);

        return $this->exchange($this->consent($client, $scope), $client);
    }

    private function directory(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.directory_connection');
    }
}
