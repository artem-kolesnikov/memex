<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\SessionRegistry;
use Doctrine\DBAL\Connection;

/**
 * Where you are signed in, and ending it — driven through HTTP, because the
 * property under test is not "the service deletes a row" but "the browser
 * whose session was ended stops being able to do anything".
 *
 * Two browsers are simulated by swapping the session cookie in and out of the
 * client's jar. It is the same trick a person performs by opening a second
 * laptop, and it is the only way to test the thing this feature is for: a
 * session that somebody else's machine is holding.
 */
class SessionManagementTest extends ApiTestCase
{
    public function testSigningInRecordsThisBrowserAndNothingElse(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/me/sessions');
        self::assertSame(200, $this->httpStatus());

        $sessions = $this->jsonResponse()['sessions'];
        self::assertCount(1, $sessions, 'One sign-in is one session');
        self::assertTrue($sessions[0]['current'], 'The browser asking is the one marked');
        self::assertNotNull($sessions[0]['last_seen_at']);
        self::assertNotNull($sessions[0]['ip'], 'The address is recorded — see the Privacy page');
    }

    public function testASecondBrowserIsItsOwnSessionAndOnlyItsOwnRowIsCurrent(): void
    {
        $this->loginAs($this->kb->a);
        $first = $this->browserCookie();

        $this->newBrowser();
        $this->loginAs($this->kb->a);
        $second = $this->browserCookie();
        self::assertNotSame($first, $second, 'A second sign-in is a second session');

        $this->sessionRequest('GET', '/api/me/sessions');
        $sessions = $this->jsonResponse()['sessions'];
        self::assertCount(2, $sessions);
        self::assertSame(1, count(array_filter($sessions, static fn ($s) => $s['current'] === true)));

        // And the first browser sees the same two, marked the other way round.
        $this->useBrowser($first);
        $this->sessionRequest('GET', '/api/me/sessions');
        $fromFirst = $this->jsonResponse()['sessions'];
        self::assertCount(2, $fromFirst);
        $currentIds = array_column(array_filter($fromFirst, static fn ($s) => $s['current'] === true), 'id');
        $otherIds = array_column(array_filter($sessions, static fn ($s) => $s['current'] === true), 'id');
        self::assertNotSame($currentIds, $otherIds, 'Each browser marks itself, not the same row');
    }

    public function testEndingAnotherBrowserRefusesItOnItsNextRequest(): void
    {
        $this->loginAs($this->kb->a);
        $stranded = $this->browserCookie();

        $this->newBrowser();
        $this->loginAs($this->kb->a);
        $keptId = $this->currentSessionId();

        $target = $this->otherSessionId($keptId);
        $this->sessionRequest('DELETE', '/api/me/sessions/'.$target);
        self::assertSame(200, $this->httpStatus());

        // The whole point: not eventually, on the very next request.
        $this->useBrowser($stranded);
        $this->sessionRequest('GET', '/api/notes');
        self::assertSame(401, $this->httpStatus(), 'An ended session is refused at once');

        // And it is gone from the list rather than lingering as a dead row.
        $this->newBrowser();
        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/me/sessions');
        self::assertNotContains($target, array_column($this->jsonResponse()['sessions'], 'id'));
    }

    public function testYouCannotEndTheBrowserYouAreUsing(): void
    {
        $this->loginAs($this->kb->a);
        $mine = $this->currentSessionId();

        $this->sessionRequest('DELETE', '/api/me/sessions/'.$mine);
        self::assertSame(409, $this->httpStatus(), 'Signing out is the control for this one');

        $this->sessionRequest('GET', '/api/me/sessions');
        self::assertSame(200, $this->httpStatus(), 'And it still works');
    }

    public function testSignOutEverywhereElseLeavesThisBrowserSignedIn(): void
    {
        $this->loginAs($this->kb->a);
        $stranded = $this->browserCookie();
        $this->newBrowser();
        $this->loginAs($this->kb->a);
        $alsoStranded = $this->browserCookie();

        $this->newBrowser();
        $this->loginAs($this->kb->a);
        $keeping = $this->browserCookie();

        $this->sessionRequest('POST', '/api/me/sessions/end-others');
        self::assertSame(200, $this->httpStatus());
        self::assertSame(2, $this->jsonResponse()['ended']);

        $this->sessionRequest('GET', '/api/me/sessions');
        $left = $this->jsonResponse()['sessions'];
        self::assertCount(1, $left);
        self::assertTrue($left[0]['current'], 'The one that asked is the one that is left');

        foreach ([$stranded, $alsoStranded] as $gone) {
            $this->useBrowser($gone);
            $this->sessionRequest('GET', '/api/notes');
            self::assertSame(401, $this->httpStatus());
        }

        $this->useBrowser($keeping);
        $this->sessionRequest('GET', '/api/notes');
        self::assertSame(200, $this->httpStatus(), 'And this one never stopped working');
    }

    public function testOneAccountCanNeitherSeeNorEndAnothersSessions(): void
    {
        $this->loginAs($this->kb->b);
        $bId = $this->currentSessionId();

        $this->newBrowser();
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/me/sessions');
        self::assertNotContains($bId, array_column($this->jsonResponse()['sessions'], 'id'), "B's session is not A's business");

        // An id from another account is indistinguishable from one that is not there.
        $this->sessionRequest('DELETE', '/api/me/sessions/'.$bId);
        self::assertSame(404, $this->httpStatus());

        $row = $this->directory()->fetchOne('SELECT count(*) FROM user_sessions WHERE ref = ?', [$bId]);
        self::assertSame(1, (int) $row, "and B is still signed in");
    }

    public function testAConnectedAssistantOpensNoSessionAndCannotReachTheScreen(): void
    {
        // Two token requests, so a per-request row would be unmistakable.
        $this->request('GET', '/api/notes', $this->kb->a->agentBearer);
        self::assertSame(200, $this->httpStatus());
        $this->request('GET', '/api/notes', $this->kb->a->agentBearer);

        $count = $this->directory()->fetchOne(
            'SELECT count(*) FROM user_sessions WHERE account_id = ?',
            [$this->kb->a->accountId]
        );
        self::assertSame(0, (int) $count, 'A bearer token is not a browser');

        // And a token that authenticates AS the owner still may not sign him out.
        $this->request('GET', '/api/me/sessions', $this->kb->a->curatorBearer);
        self::assertSame(403, $this->httpStatus());
        $this->request('POST', '/api/me/sessions/end-others', $this->kb->a->curatorBearer);
        self::assertSame(403, $this->httpStatus());
    }

    public function testASessionThatIdlesPastItsLifetimeIsRefusedAndCleanedUp(): void
    {
        $this->loginAs($this->kb->a);
        $id = $this->currentSessionId();

        $this->directory()->executeStatement(
            'UPDATE user_sessions SET expires_at = :past WHERE ref = :id',
            ['past' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), 'id' => $id]
        );

        $this->sessionRequest('GET', '/api/notes');
        self::assertSame(401, $this->httpStatus());

        $left = $this->directory()->fetchOne('SELECT count(*) FROM user_sessions WHERE ref = ?', [$id]);
        self::assertSame(0, (int) $left, 'An expired session is not left in the list');
    }

    public function testSigningOutTakesTheRowWithIt(): void
    {
        $this->loginAs($this->kb->a);
        $before = (int) $this->directory()->fetchOne(
            'SELECT count(*) FROM user_sessions WHERE account_id = ?',
            [$this->kb->a->accountId]
        );
        self::assertSame(1, $before);

        $this->sessionRequest('POST', '/api/logout');

        $after = (int) $this->directory()->fetchOne(
            'SELECT count(*) FROM user_sessions WHERE account_id = ?',
            [$this->kb->a->accountId]
        );
        self::assertSame(0, $after, 'Signing out is not a session anybody should still see');
    }

    public function testSigningOutAnswersJsonRatherThanRedirecting(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('POST', '/api/logout');

        self::assertSame(200, $this->httpStatus(), 'A redirect lands the SPA on the HTML landing page');
        self::assertSame(['signed_out' => true], $this->jsonResponse());
    }

    public function testUsingMemexDoesNotWriteOnEveryRequest(): void
    {
        // The check runs on every request; the write is throttled, or a busy
        // knowledge base would pay an UPDATE per page view for a date nobody
        // reads to the minute.
        $this->loginAs($this->kb->a);
        $id = $this->currentSessionId();
        $stamp = $this->directory()->fetchOne('SELECT last_seen_at FROM user_sessions WHERE ref = ?', [$id]);

        $this->sessionRequest('GET', '/api/notes');
        $this->sessionRequest('GET', '/api/notes');

        self::assertSame(
            $stamp,
            $this->directory()->fetchOne('SELECT last_seen_at FROM user_sessions WHERE ref = ?', [$id]),
            'Within '.SessionRegistry::TOUCH_THROTTLE.' seconds nothing is rewritten'
        );
    }

    public function testTheCookieCarriesTheLifetimeAndIsRenewedRatherThanReissued(): void
    {
        $this->loginAs($this->kb->a);

        $cookie = $this->sessionCookie();
        self::assertNotNull($cookie, 'Signing in sets a session cookie');
        // Thirty days, give or take the second the test took. Without this the
        // cookie would be a browser-session cookie and closing the window would
        // sign you out, whatever the server thinks the session is worth.
        self::assertEqualsWithDelta(
            time() + 2592000,
            $cookie->getExpiresTime(),
            60,
            'The cookie should outlive the browser window'
        );

        // And it is not re-sent on every response: the renewal is throttled, or
        // every request would carry a Set-Cookie header for no reason.
        $this->sessionRequest('GET', '/api/notes');
        self::assertNull($this->sessionCookie(), 'Nothing to renew a moment later');
    }

    /** The session cookie on the response just received, if it set one. */
    private function sessionCookie(): ?\Symfony\Component\HttpFoundation\Cookie
    {
        $name = static::getContainer()->get('session.factory')->createSession()->getName();
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    /** The id of the row the calling browser is sitting in. */
    private function currentSessionId(): string
    {
        $this->sessionRequest('GET', '/api/me/sessions');
        foreach ($this->jsonResponse()['sessions'] as $session) {
            if ($session['current'] === true) {
                return (string) $session['id'];
            }
        }
        self::fail('No session is marked as the current one');
    }

    /** The other one, for a test that has exactly two. */
    private function otherSessionId(string $notThis): string
    {
        $this->sessionRequest('GET', '/api/me/sessions');
        foreach ($this->jsonResponse()['sessions'] as $session) {
            if ((string) $session['id'] !== $notThis) {
                return (string) $session['id'];
            }
        }
        self::fail('Expected a second session');
    }

    private function directory(): Connection
    {
        return static::getContainer()->get('doctrine.dbal.directory_connection');
    }

    /** @return array<string, string> the cookies this "browser" is holding */
    private function browserCookie(): array
    {
        $jar = [];
        foreach ($this->client->getCookieJar()->all() as $cookie) {
            $jar[$cookie->getName()] = $cookie->getValue();
        }
        self::assertNotSame([], $jar, 'Signing in should have set a session cookie');

        return $jar;
    }

    /** A machine that has never been here. */
    private function newBrowser(): void
    {
        $this->client->getCookieJar()->clear();
    }

    /** @param array<string, string> $cookies */
    private function useBrowser(array $cookies): void
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();
        foreach ($cookies as $name => $value) {
            $jar->set(new \Symfony\Component\BrowserKit\Cookie($name, $value));
        }
    }
}
