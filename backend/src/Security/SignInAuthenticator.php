<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/**
 * A named authenticator that authenticates nothing.
 *
 * `Security::login()` needs an authenticator to establish a session, and it
 * refuses to guess when a firewall has more than one; the other one here is
 * the bearer-token authenticator, which a browser session must never be
 * recorded as. This class exists so the session says a person signed in.
 *
 * It is registered on the firewall only to be findable by name.
 * {@see supports()} always returns false, so it never takes part in an ordinary
 * request; a sign-in reaches it through `Security::login($user,
 * SignInAuthenticator::class)`, which calls `authenticateUser()` directly and
 * skips `supports()` by design.
 */
class SignInAuthenticator extends AbstractAuthenticator
{
    /**
     * Never. Signing in is a controller's, because it decides more than who
     * this is: the OAuth callback also creates accounts and links providers.
     */
    public function supports(Request $request): ?bool
    {
        return false;
    }

    public function authenticate(Request $request): Passport
    {
        throw new \LogicException(self::class.' is a login-only authenticator: it is used through Security::login(), never to authenticate a request.');
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // The controller owns the redirect: where a person lands depends on
        // whether they just created an account or just signed in.
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }

    /**
     * The only destination a sign-in returns to from a query string: the OAuth
     * consent page on this host, as a relative path. Anything else is an open
     * redirect on an authentication path. `//evil.test` and `/\evil.test`, the
     * two spellings browsers read as another origin, fail the prefix test.
     */
    public static function safeNext(?string $next): ?string
    {
        if ($next === null || $next === '') {
            return null;
        }
        if ($next !== '/oauth/authorize' && !str_starts_with($next, '/oauth/authorize?')) {
            return null;
        }

        return $next;
    }
}
