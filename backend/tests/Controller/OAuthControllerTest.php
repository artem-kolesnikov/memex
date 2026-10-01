<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\OAuthController;
use PHPUnit\Framework\TestCase;

/**
 * The OAuth server's pure decision helpers.
 *
 * These are unit tests in the strict sense — no kernel, no database, no HTTP.
 * What they cannot reach is the part that makes the curator grant safe: that
 * the token endpoint reads the scope off the *code row* and never off the token
 * request. That is a two-request property spanning the database, so it is
 * verified by the scripted flow against prod, not here. What is here is the
 * decision each of those requests makes once it has a string in hand.
 */
final class OAuthControllerTest extends TestCase
{
    /**
     * The authorize page's grant statement (stage 8b).
     *
     * There is no consent checkbox — signing in on a page that states the grant
     * is the consent — so the whole weight of it rests on this sentence being
     * true of the token that will actually be issued. There is exactly one
     * grant to state now, so the sentence takes no argument and no client can
     * change it by naming a scope in a query string. What the flow actually
     * mints is asserted in OAuthGrantTest, against a real kernel and database,
     * because that is where the bug this replaces was able to hide.
     */
    public function testTheNoticePromisesTheReviewGateAndNothingElse(): void
    {
        $notice = OAuthController::grantNotice();

        self::assertStringContainsString('review inbox as pending', $notice);
        self::assertStringNotContainsString('saved directly', $notice, 'no page may promise ungated writes');
    }

    /**
     * Loopback redirect URIs, exercised through `redirectUriRegistered`.
     *
     * Reached by reflection on purpose. Both helpers are private because
     * nothing outside the controller should be making this decision, and the
     * alternative — widening the API so a test can call it — would trade a real
     * constraint for a testing convenience. The reflection is confined here.
     *
     * @dataProvider redirectUriCases
     */
    public function testRedirectUriRegistered(string $uri, array $registered, bool $expected, string $why): void
    {
        $method = new \ReflectionMethod(OAuthController::class, 'redirectUriRegistered');

        self::assertSame($expected, $method->invoke(null, $uri, $registered), $why);
    }

    /**
     * @return iterable<string, array{string, list<string>, bool, string}>
     */
    public static function redirectUriCases(): iterable
    {
        $https = ['https://claude.ai/api/mcp/auth_callback'];
        $loopback = ['http://localhost:41234/callback'];

        // Exact matching is the rule; everything below is the one exception.
        yield 'https exact' => ['https://claude.ai/api/mcp/auth_callback', $https, true, 'the registered URI must match itself'];
        yield 'https different path' => ['https://claude.ai/evil', $https, false, 'a different path is a different URI'];
        yield 'https different host' => ['https://claude.ai.evil.com/api/mcp/auth_callback', $https, false, 'a suffixed host must not match'];
        yield 'nothing registered' => ['https://claude.ai/api/mcp/auth_callback', [], false, 'an empty registration matches nothing'];

        // The exception: a native client binds an ephemeral port at flow time
        // that differs from the one it registered (RFC 8252 §7.3). This is the
        // case that unblocked Hermes.
        yield 'loopback, different port' => ['http://localhost:57001/callback', $loopback, true, 'loopback matches regardless of port'];
        yield 'loopback, same port' => ['http://localhost:41234/callback', $loopback, true, 'the exact URI still matches'];
        yield 'loopback, no port' => ['http://localhost/callback', $loopback, true, 'an absent port is still loopback'];
        yield 'loopback 127.0.0.1' => ['http://127.0.0.1:9999/callback', ['http://127.0.0.1:1/callback'], true, '127.0.0.1 is loopback'];
        yield 'loopback ipv6' => ['http://[::1]:9999/callback', ['http://[::1]:1/callback'], true, '[::1] is loopback'];

        // Port-insensitivity applies to the port and nothing else.
        yield 'loopback, different path' => ['http://localhost:41234/evil', $loopback, false, 'only the port is ignored, never the path'];
        yield 'loopback host is not interchangeable' => ['http://127.0.0.1:41234/callback', $loopback, false, 'localhost and 127.0.0.1 are distinct registrations'];

        yield 'plain http elsewhere' => ['http://claude.ai/callback', ['http://claude.ai/callback'], true, 'an exactly registered URI matches even over http'];
        yield 'unregistered http is refused' => ['http://example.com/callback', $loopback, false, 'http off the loopback interface has no exception'];
    }

    /**
     * The RFC 8252 §7.3 predicate itself.
     *
     * Tested separately from `redirectUriRegistered` because that is not where
     * it earns its keep. Weakening the guard there and re-running this suite
     * changed nothing: a lookalike host is already refused by the normalized
     * comparison, which no attacker-controlled URI can satisfy. The predicate
     * is load-bearing at **registration** (`register()`), where it alone
     * decides whether an `http://` redirect URI may be recorded at all — and a
     * registration is what a later flow matches against. So the anchoring cases
     * belong here, against the predicate, not against the matcher.
     *
     * @dataProvider loopbackCases
     */
    public function testIsLoopbackUri(string $uri, bool $expected, string $why): void
    {
        $method = new \ReflectionMethod(OAuthController::class, 'isLoopbackUri');

        self::assertSame($expected, $method->invoke(null, $uri), $why);
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function loopbackCases(): iterable
    {
        // The three loopback hosts, with and without a port.
        yield 'localhost with port' => ['http://localhost:41234/callback', true, 'the ordinary native-client case'];
        yield 'localhost without port' => ['http://localhost/callback', true, 'the port is optional'];
        yield 'localhost bare' => ['http://localhost', true, 'a bare host with no path is still loopback'];
        yield 'localhost bare with port' => ['http://localhost:8080', true, 'no path, with a port'];
        yield 'ipv4' => ['http://127.0.0.1:9999/cb', true, '127.0.0.1 is loopback'];
        yield 'ipv6' => ['http://[::1]:9999/cb', true, '[::1] is loopback'];

        // The anchoring. Each of these is an attacker-controlled host that
        // merely begins with — or contains — a loopback name; accepting any of
        // them at registration would let a redirect be recorded that sends the
        // authorization code off the machine.
        yield 'subdomain lookalike' => ['http://localhost.evil.com/cb', false, 'the host must not merely start with localhost'];
        yield 'suffixed lookalike' => ['http://localhost-evil.com/cb', false, 'a hyphenated lookalike is a different host'];
        yield 'userinfo trick' => ['http://localhost@evil.com/cb', false, 'userinfo before an @ does not make evil.com loopback'];
        yield 'ipv4 lookalike' => ['http://127.0.0.1.evil.com/cb', false, 'a suffixed IP is a hostname, not the loopback address'];
        yield 'loopback in path only' => ['http://evil.com/localhost', false, 'the host is what matters, not the path'];
        yield 'loopback as a query value' => ['http://evil.com/cb?x=http://localhost', false, 'a loopback string in the query is not the host'];

        // Scheme. The exception is for http on loopback and nothing else; https
        // is already accepted by its own branch and must not depend on this one.
        yield 'https localhost is not this predicate' => ['https://localhost/cb', false, 'https is handled by the other branch'];
        yield 'no scheme' => ['localhost:41234/cb', false, 'a scheme-less string is not an http loopback URI'];
        yield 'other scheme' => ['ftp://localhost/cb', false, 'only http:// gets the loopback exception'];
        yield 'empty' => ['', false, 'the empty string is not a URI'];
    }
}
