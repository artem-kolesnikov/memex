<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\SessionListener;
use App\Service\SessionRegistry;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The half of "thirty days idle" that the browser holds.
 *
 * It has its own test because it is the part a reader would assume works and
 * it does not, unaided: PHP sends the session cookie when the session id
 * changes and at no other time, so a thirty-day cookie stamped at sign-in
 * expires thirty days after SIGNING IN however much memex is used in between.
 * The server-side lifetime slides on use; without this listener the two would
 * quietly mean different things, and the one that reaches the person is the
 * cookie.
 *
 * Driven directly rather than through HTTP because the throttle is measured in
 * days, and a test that waits a day is not a test.
 */
class SessionCookieRenewalTest extends TestCase
{
    private const LIFETIME = 2592000;

    public function testAnOngoingSessionHasItsCookieRenewed(): void
    {
        // A session last renewed two days ago, still being used today.
        $event = $this->respondTo($this->signedInSession(time() - 172800));
        $this->listener()->onResponse($event);

        $cookie = $this->cookieOn($event->getResponse());
        self::assertNotNull($cookie, 'A session in use should have its cookie pushed forward');
        self::assertEqualsWithDelta(time() + self::LIFETIME, $cookie->getExpiresTime(), 5);
    }

    public function testItIsNotSentOnEveryResponse(): void
    {
        $event = $this->respondTo($this->signedInSession(time() - 60));
        $this->listener()->onResponse($event);

        self::assertNull($this->cookieOn($event->getResponse()), 'A minute is not a day');
    }

    public function testASignedOutBrowserIsNotHandedANewCookie(): void
    {
        // What a session looks like after signing out: started, and empty. It
        // must not be revived by the very listener that keeps sessions alive.
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $event = $this->respondTo($session);
        $this->listener()->onResponse($event);

        self::assertNull($this->cookieOn($event->getResponse()));
    }

    private function listener(): SessionListener
    {
        // A real registry over a connection that is never reached: renewing a
        // cookie touches no database, and asserting that by construction is
        // better than asserting it in a comment.
        return new SessionListener(
            $this->createMock(Security::class),
            new SessionRegistry($this->createMock(Connection::class), self::LIFETIME),
            self::LIFETIME,
        );
    }

    private function signedInSession(int $renewedAt): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $session->set(SessionListener::KEY, str_repeat('a', 64));
        $session->set('_memex_session_cookie_at', $renewedAt);

        return $session;
    }

    private function respondTo(Session $session): ResponseEvent
    {
        $request = Request::create('https://memex.tools/api/notes');
        $request->setSession($session);

        return new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new Response()
        );
    }

    private function cookieOn(Response $response): ?\Symfony\Component\HttpFoundation\Cookie
    {
        $cookies = $response->headers->getCookies();

        return $cookies === [] ? null : $cookies[0];
    }
}
