<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Directory\Account;
use App\Security\ApiTokenAuthenticator;
use App\Service\SessionRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Where a browser session begins, is checked, is kept alive, and ends.
 *
 * Four small jobs that belong together because they are one rule seen from
 * four sides: **a session is only a session while {@see SessionRegistry} has a
 * row for it.**
 *
 *  - **Sign in** — a row is opened and its key put in the session.
 *  - **Every request** — the key is checked. No row, or an expired one, and the
 *    browser is signed out on the spot. This is what makes ending a session
 *    from the Settings screen immediate rather than eventual.
 *  - **Every response** — the cookie is renewed, occasionally, so that "thirty
 *    days" means thirty days of not using memex rather than thirty days from
 *    signing in. See {@see slideCookie()}, which exists for a specific reason.
 *  - **Sign out** — the row goes with the session it described.
 *
 * ## Bearer tokens are not sessions and are left alone
 *
 * Every connected assistant authenticates on every request, which fires the
 * same login event a person's sign-in does. A row per MCP call would fill this
 * table with sessions nobody is sitting at, and switching an assistant off is
 * already a control on the Connections screen. So the token authenticator is
 * skipped by name, in both places it could reach.
 */
class SessionListener
{
    /** The session attribute holding this browser's registry key. */
    public const KEY = '_memex_session_key';

    /** When the cookie was last renewed, so it is not re-sent on every response. */
    private const REFRESHED = '_memex_session_cookie_at';

    /** Seconds between cookie renewals. A day is far inside a thirty-day life. */
    private const REFRESH_EVERY = 86400;

    public function __construct(
        private readonly Security $security,
        private readonly SessionRegistry $registry,
        private readonly int $lifetime,
    ) {
    }

    /**
     * Somebody signed in with a provider, which arrives here through
     * `Security::login()`.
     */
    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLogin(LoginSuccessEvent $event): void
    {
        if ($event->getAuthenticator() instanceof ApiTokenAuthenticator) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }
        $user = $event->getUser();
        if (!$user instanceof Account || $user->getId() === null) {
            return;
        }

        $session = $request->getSession();

        // Signing in while already signed in — linking a provider does exactly
        // this. The old row described the same browser, and leaving it would
        // put a session in the list that nothing can reach.
        $previous = $session->get(self::KEY);
        if (is_string($previous)) {
            $this->registry->endByKey($previous);
        }

        $session->set(self::KEY, $this->registry->open($user, $request));
        // Force a renewal on the first response, so the cookie a person leaves
        // with already carries the long life rather than the browser's default.
        $session->remove(self::REFRESHED);
    }

    /**
     * The check, on every authenticated request. Runs after the firewall
     * (priority 8) and before anything that does work.
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        // A bearer token authenticates the request by itself and carries no
        // session, so there is nothing here to check.
        if ($request->attributes->get(ApiTokenAuthenticator::REQUEST_ATTRIBUTE) !== null) {
            return;
        }
        if (!$request->hasSession() || !$request->hasPreviousSession()) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof Account || $user->getId() === null) {
            return;
        }

        $session = $request->getSession();
        $key = $session->get(self::KEY);
        if (is_string($key) && $this->registry->verify($key, $user->getId(), $request)) {
            return;
        }

        // Ended from the Settings screen, or idled out: both mean the same
        // thing to the person holding it.
        $session->invalidate();
        $event->setResponse(new JsonResponse(
            ['error' => 'This session has ended. Please sign in again.'],
            Response::HTTP_UNAUTHORIZED
        ));
    }

    /**
     * Renew the cookie, at most once a day.
     *
     * Without this, "thirty days" would silently mean something else. PHP sends
     * the session cookie when the session id changes and not otherwise, so a
     * cookie stamped with a thirty-day expiry at sign-in expires thirty days
     * after sign-in however much memex is used in between — an absolute limit
     * wearing an idle limit's clothes. The server-side lifetime already slides
     * on use; this makes the browser's copy agree with it.
     *
     * Below Symfony's own response listener (-1000) so it replaces rather than
     * races the cookie that listener may have just set.
     */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -1024)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }
        $session = $request->getSession();
        // Not started means nothing to keep alive; no key means this is not a
        // signed-in browser, which is also what a just-signed-out one looks like.
        if (!$session->isStarted() || !is_string($session->get(self::KEY))) {
            return;
        }

        $now = time();
        $last = $session->get(self::REFRESHED);
        if (is_int($last) && $now - $last < self::REFRESH_EVERY) {
            return;
        }

        $session->set(self::REFRESHED, $now);
        $event->getResponse()->headers->setCookie($this->cookie($request, $session->getId(), $now));
    }

    /**
     * Signing out deliberately. The row is about a session that is ending.
     *
     * **The priority is load-bearing.** Symfony's own `SessionLogoutListener`
     * calls `$session->invalidate()` at priority 0, which clears every
     * attribute — including the key that says which row to delete. It also
     * registers on the FIREWALL's dispatcher rather than the global one, so it
     * runs first among equals and `debug:event-dispatcher` does not list it
     * next to this method. Both together cost an afternoon: the session
     * arrived here already empty, and a row survived every sign-out.
     */
    #[AsEventListener(event: LogoutEvent::class, priority: 64)]
    public function onLogout(LogoutEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->hasSession() || !$request->hasPreviousSession()) {
            return;
        }
        $key = $request->getSession()->get(self::KEY);
        if (is_string($key)) {
            $this->registry->endByKey($key);
        }
    }

    /**
     * The SPA signs out with fetch, which follows Symfony's default redirect
     * to `/` and gets the landing page's HTML where it expects JSON. Above
     * `DefaultLogoutListener`'s 64, which redirects only when no response is
     * set yet, so the order does not rest on registration among equals.
     */
    #[AsEventListener(event: LogoutEvent::class, priority: 65)]
    public function answerLogout(LogoutEvent $event): void
    {
        $event->setResponse(new JsonResponse(['signed_out' => true]));
    }

    /**
     * The session cookie as PHP would send it, with a fresh expiry.
     *
     * The attributes come from the running session configuration so this cannot
     * drift from framework.yaml — except `secure`, which is configured as
     * `auto` and means "whatever this request is". Sending a secure cookie over
     * plain HTTP would lock a developer out of their own machine.
     */
    private function cookie(Request $request, string $id, int $now): Cookie
    {
        $params = session_get_cookie_params();

        return Cookie::create(
            // The session's own name, not session_name(): under the test
            // storage the two differ, and a cookie under the wrong name is a
            // second, silent session.
            $request->getSession()->getName(),
            $id,
            $now + $this->lifetime,
            $params['path'] ?: '/',
            $params['domain'] ?: null,
            $request->isSecure(),
            $params['httponly'] ?? true,
            false,
            ($params['samesite'] ?? '') ?: null,
        );
    }
}
