<?php

declare(strict_types=1);

namespace App\Controller;

use App\Directory\Account;
use App\Directory\Identity;
use App\Security\SignInAuthenticator;
use App\Service\AccountDoor;
use App\Service\SocialAccounts;
use App\Service\SocialAuthException;
use App\Service\SocialIdentity;
use App\Service\SocialIdentityGateway;
use App\Service\SocialProviders;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Signing in with an account you already have, and attaching one to the account
 * you are already signed in to.
 *
 * ## Why these routes live under /api
 *
 * They are browser redirects, not JSON, which usually argues for a path of
 * their own. They sit under `/api` anyway because nginx routes `^/(api|mcp|
 * oauth|\.well-known)` to PHP and everything else to the SPA — a `/auth/…`
 * prefix would need the vhost edited on the box by hand, and the failure mode
 * of forgetting is a 404 that looks exactly like a bug in this file.
 *
 * ## The state parameter
 *
 * A nonce is generated at `start`, kept in the session, and compared at
 * `callback`. Without it, anyone can hand you a link that completes a sign-in
 * as an account they control, and the session cookie is Lax, so an OAuth
 * redirect back is precisely the top-level GET that carries it. The session
 * entry also holds what the round trip is FOR — signing in, or linking to an
 * open session — because the callback URL is registered with the provider and
 * cannot vary, so intent has to survive somewhere else.
 *
 * ## Errors go back as codes
 *
 * Every failure lands on the sign-in screen with `?error=<code>`, and the
 * screen holds the wording. The server's own sentence, which names the account
 * and the provider, goes to the log. Rendering a server-supplied sentence from
 * a URL would put attacker-chosen text on our sign-in page in our own styling.
 */
class SocialAuthController extends ApiController
{
    /** How long a started sign-in stays valid. Long enough to create a Google account mid-flow. */
    private const STATE_TTL = 900;

    private const SESSION_KEY = 'social_auth';

    public function __construct(
        private readonly SocialProviders $providers,
        private readonly SocialIdentityGateway $gateway,
        private readonly SocialAccounts $accounts,
        private readonly AccountDoor $door,
        private readonly Security $security,
        private readonly EntityManagerInterface $directoryEntityManager,
        private readonly LoggerInterface $logger,
        private readonly string $appBaseUrl,
    ) {
    }

    /**
     * What the sign-in screen may offer. Public, because it is read before
     * anyone is signed in, and it names no account.
     */
    #[Route('/api/auth/providers', methods: ['GET'])]
    public function providers(): JsonResponse
    {
        return $this->json(['providers' => $this->providers->available()]);
    }

    /**
     * Microsoft's proof that this domain and that app registration belong to
     * the same people.
     *
     * Microsoft asks for a file rather than a DNS record, which is a mercy
     * here: its verification TXT would have shared the apex name with the
     * `google-site-verification` value that publishes the Google consent
     * screen, and Route 53 replaces a record set whole.
     *
     * **The id comes from the configured credentials, not from a constant.** A
     * hardcoded one would be a second copy of a fact that already has an owner,
     * and it would go stale silently the day the registration is replaced. It
     * also means an installation with no Microsoft app configured serves a 404
     * rather than asserting a claim about somebody else's registration.
     *
     * Public and unauthenticated by necessity: Microsoft fetches it with no
     * credentials. It discloses only the client id, which is already in every
     * authorize URL the sign-in screen produces.
     */
    // `priority` is load-bearing, not decoration. OAuthController declares a
    // catch-all `/.well-known/{suffix}` for CORS preflight that accepts OPTIONS
    // only, and it is registered first because attribute routes load in file
    // order and `O` sorts before `S`. Without a higher priority this path
    // matches THAT route and answers 405 to Microsoft's GET — a failure that
    // looks like the route is missing and is not.
    #[Route('/.well-known/microsoft-identity-association.json', methods: ['GET'], priority: 10)]
    public function microsoftIdentityAssociation(): JsonResponse
    {
        $clientId = $this->providers->clientId(SocialProviders::MICROSOFT);
        if ($clientId === '' || !$this->providers->isConfigured(SocialProviders::MICROSOFT)) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['associatedApplications' => [['applicationId' => $clientId]]]);
    }

    /**
     * Begin the round trip. `invite` carries a code for somebody who does not
     * have an account yet; `link=1` attaches the provider to the session that
     * is already open.
     */
    #[Route('/api/auth/{provider}/start', name: 'social_auth_start', methods: ['GET'])]
    public function start(string $provider, Request $request): Response
    {
        if (!SocialProviders::exists($provider) || !$this->providers->isConfigured($provider)) {
            return $this->bounce('login', 'unconfigured');
        }

        $link = $request->query->get('link') === '1';
        $account = $this->security->getUser();
        // A BEARER token satisfies `getUser()` exactly as a session does, and
        // this leg used to ask only that somebody was authenticated. So a
        // token could start a link, finish it with an identity of the holder's
        // choosing, and thereafter sign in to the owner's account as a full
        // session — reaching role assignment. `SessionListener` does not catch it: it returns early
        // precisely when a bearer authenticated the request. The rule this
        // restores is the one the unlink route states — a leaked token must
        // never be able to rearrange how the account is opened (Codex,
        // 2026-09-10).
        if ($link && $this->requestToken($request) !== null) {
            return $this->bounce('login', 'link_session');
        }
        if ($link && !$account instanceof Account) {
            return $this->bounce('login', 'link_session');
        }

        $nonce = bin2hex(random_bytes(16));
        $request->getSession()->set(self::SESSION_KEY, [
            'nonce' => $nonce,
            'provider' => $provider,
            'intent' => $link ? 'link' : 'login',
            'invite' => trim((string) $request->query->get('invite', '')) ?: null,
            // Where to land afterwards. Set when the person arrived from the
            // OAuth consent page, which sends them here to sign in and needs
            // them back. Validated on the way in AND on the way out.
            'next' => SignInAuthenticator::safeNext((string) $request->query->get('next', '')),
            // Pinned so a link that finishes in a session belonging to someone
            // else attaches the provider to nobody.
            'account_id' => $link && $account instanceof Account ? $account->getId() : null,
            'expires' => time() + self::STATE_TTL,
        ]);

        $params = [
            'client_id' => $this->providers->clientId($provider),
            'redirect_uri' => $this->redirectUri($request, $provider),
            'response_type' => 'code',
            'scope' => $this->providers->scope($provider),
            'state' => $nonce,
        ];
        if ($provider === SocialProviders::APPLE) {
            // Asking Apple for `name` or `email` obliges us to take the answer
            // as a form POST rather than a query string, and Apple refuses the
            // authorize request outright if we ask for one without the other.
            // Everything awkward about the Apple callback follows from this
            // line — see appleCallback() below.
            $params['response_mode'] = 'form_post';
        }
        if (\in_array($provider, [SocialProviders::GOOGLE, SocialProviders::MICROSOFT], true)) {
            // Without it, a second sign-in silently reuses whichever account
            // the browser is already in, which is the wrong one every time
            // somebody has two. Microsoft needs it more than Google does: a
            // work machine is signed in to the employer's tenant all day, and
            // that is the account somebody linking a personal one is least
            // likely to want.
            $params['prompt'] = 'select_account';
        }

        return new RedirectResponse($this->providers->url($provider, 'authorize_url').'?'.http_build_query($params));
    }

    /**
     * Apple's answer, which arrives as a form POST from appleid.apple.com and
     * is handed straight back to ourselves as a GET.
     *
     * **The redirect is the whole point, and it is not ceremony.** A form POST
     * from Apple's origin to ours is a cross-site request, and the session
     * cookie is `SameSite=lax`, so the browser does not send it: the callback
     * would run with an empty session, find no state to compare against, and
     * refuse every Apple sign-in with `?error=state` — the kind of failure that
     * reads like a bug in the state check rather than a cookie that never
     * arrived. A 303 to our own path makes the next request a same-site
     * top-level GET, which carries the cookie, and the ordinary callback below
     * then runs unchanged for all four providers.
     *
     * Loosening `cookie_samesite` for the whole application would have been the
     * other way, and it would trade a CSRF defence on every route for one
     * provider's response mode.
     *
     * Nothing is validated here. This handler cannot see the session, so it has
     * nothing to validate against; it copies four known fields into a URL on
     * this host and stops. `code` in a query string is what the other three
     * providers do already.
     *
     * **`priority` is belt and braces, and says so.** The `{provider}/callback`
     * route below matches this path too and accepts GET only; the suite passes
     * without the priority, so the matcher does move on to this one. It is kept
     * because a route that matched a path but not a method has answered 405 in
     * this application before — the well-known route above carries the scar —
     * and one word is cheaper than diagnosing that twice. The test that a POST
     * is accepted at all is the thing actually holding it.
     */
    #[Route('/api/auth/apple/callback', name: 'social_auth_apple_post', methods: ['POST'], priority: 10)]
    public function appleCallback(Request $request): Response
    {
        $carry = [];
        foreach (['code', 'state', 'error'] as $field) {
            $value = trim((string) $request->request->get($field, ''));
            if ($value !== '') {
                $carry[$field] = $value;
            }
        }

        // Apple's one-time `user` payload: a JSON object with the person's name
        // in it, sent on the first authorization and never again. Cut to a
        // length no real name approaches, because it is about to become part of
        // a URL and it arrives from outside.
        $user = trim((string) $request->request->get('user', ''));
        if ($user !== '') {
            $carry['user'] = mb_substr($user, 0, 1000);
        }

        return new RedirectResponse('/api/auth/apple/callback?'.http_build_query($carry), Response::HTTP_SEE_OTHER);
    }

    /**
     * Where the provider sends the browser back. Decides between signing in,
     * creating an account and linking, then redirects into the SPA.
     */
    #[Route('/api/auth/{provider}/callback', name: 'social_auth_callback', methods: ['GET'])]
    public function callback(string $provider, Request $request): Response
    {
        $session = $request->getSession();
        /** @var array<string, mixed>|null $state */
        $state = $session->get(self::SESSION_KEY);
        $session->remove(self::SESSION_KEY);

        $intent = is_array($state) && ($state['intent'] ?? '') === 'link' ? 'link' : 'login';

        // The provider's own refusal comes back as ?error, most often because
        // the person pressed Cancel. Not a fault, and not worth a scary word.
        if ($request->query->has('error')) {
            return $this->bounce($intent, $request->query->get('error') === 'access_denied' ? 'denied' : 'provider');
        }

        if (!is_array($state)
            || ($state['provider'] ?? null) !== $provider
            || !is_string($state['nonce'] ?? null)
            || !hash_equals($state['nonce'], (string) $request->query->get('state', ''))
            || (int) ($state['expires'] ?? 0) < time()
        ) {
            return $this->bounce($intent, 'state');
        }

        $code = trim((string) $request->query->get('code', ''));
        if ($code === '') {
            return $this->bounce($intent, 'provider');
        }

        try {
            $identity = $this->gateway->identify(
                $provider,
                $code,
                $this->redirectUri($request, $provider),
                $provider === SocialProviders::APPLE ? ($request->query->get('user') ?: null) : null,
            );
        } catch (SocialAuthException $e) {
            $this->logger->warning('Social sign-in failed at the provider', ['provider' => $provider, 'reason' => $e->reason, 'detail' => $e->getMessage()]);

            return $this->bounce($intent, $e->reason);
        }

        if ($intent === 'link') {
            return $this->completeLink($identity, $state, $request);
        }

        $existing = $this->accounts->identityFor($identity);
        if ($existing !== null) {
            $refusal = $this->door->accountRefusal($existing->getAccount());
            if ($refusal !== null) {
                return $this->bounce('login', $refusal->reason);
            }
            $this->signOutAnyoneBut($existing->getAccount());
            $account = $this->accounts->signIn($existing, $identity);
            $this->login($account);

            return new RedirectResponse(SignInAuthenticator::safeNext($state['next'] ?? null) ?? self::home($account));
        }

        // No identity we know, so this is a new account, if the door lets them in.
        $ticket = is_string($state['invite'] ?? null) && $state['invite'] !== '' ? $state['invite'] : null;
        $refusal = $this->door->newcomerRefusal($ticket);
        if ($refusal !== null) {
            $this->logger->info('Sign-up refused', ['reason' => $refusal->reason, 'detail' => $refusal->getMessage()]);

            return $this->bounce('login', $refusal->reason);
        }

        try {
            $this->signOutAnyoneBut(null);
            $account = $this->accounts->createAccount($identity, $ticket);
        } catch (SocialAuthException $e) {
            $this->logger->warning('Account not created', ['reason' => $e->reason, 'detail' => $e->getMessage()]);

            return $this->bounce('login', $e->reason);
        }

        $this->logger->info('New knowledge base opened', ['account' => $account->getEmail(), 'provider' => $provider, 'invite' => $ticket !== null]);
        $this->login($account);
        $this->door->opened($account, $provider, $ticket, $request->getClientIp());

        return new RedirectResponse(SignInAuthenticator::safeNext($state['next'] ?? null) ?? self::home($account));
    }

    /** The sign-in methods on the current account, for the Settings screen. */
    #[Route('/api/me/identities', methods: ['GET'])]
    public function identities(): JsonResponse
    {
        $account = $this->currentAccount();

        return $this->json([
            'identities' => array_map(
                static fn (Identity $i) => [
                    'id' => $i->getRef(),
                    'provider' => $i->getProvider(),
                    'label' => SocialProviders::label($i->getProvider()),
                    'icon' => SocialProviders::icon($i->getProvider()),
                    'email' => $i->getEmail(),
                    'linked_at' => $i->getCreatedAt()->format(DATE_ATOM),
                    'last_used_at' => $i->getLastUsedAt()?->format(DATE_ATOM),
                ],
                $this->accounts->identitiesOf($account)
            ),
            'available' => $this->providers->available(),
            'own_way_in' => $this->door->ownWayIn($account),
        ]);
    }

    #[Route('/api/me/identities/{id}', methods: ['DELETE'], requirements: ['id' => '[0-9a-f]{16}'])]
    public function unlink(string $id, Request $request): JsonResponse
    {
        // Session only: a leaked API token must never be able to rearrange how
        // the account is opened.
        if ($this->requestToken($request) !== null) {
            return $this->json($this->json400('Sign-in methods can only be changed from a browser session'), Response::HTTP_FORBIDDEN);
        }

        $account = $this->currentAccount();
        $identity = $this->directoryEntityManager->getRepository(Identity::class)->findOneBy(['ref' => $id, 'account' => $account]);
        if ($identity === null) {
            return $this->json($this->json400('No such sign-in method'), Response::HTTP_NOT_FOUND);
        }

        try {
            $this->accounts->unlink($account, $identity);
        } catch (SocialAuthException $e) {
            return $this->json($this->json400($e->getMessage()), Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['removed' => true]);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function completeLink(SocialIdentity $identity, array $state, Request $request): Response
    {
        // Both legs, not just the first: the callback is a separate request and
        // can carry the bearer of its own accord, so guarding only `start()`
        // would leave the same escalation reachable one step later.
        if ($this->requestToken($request) !== null) {
            return $this->bounce('link', 'link_session');
        }
        $account = $this->security->getUser();
        if (!$account instanceof Account || $account->getId() !== ($state['account_id'] ?? null)) {
            // The session ended, or ended up belonging to somebody else, while
            // the browser was at the provider.
            return $this->bounce('link', 'link_session');
        }
        $refusal = $this->door->accountRefusal($account);
        if ($refusal !== null) {
            return $this->bounce('login', $refusal->reason);
        }

        try {
            $this->accounts->link($account, $identity);
        } catch (SocialAuthException $e) {
            $this->logger->warning('Provider not linked', ['reason' => $e->reason, 'detail' => $e->getMessage()]);

            return $this->bounce('link', $e->reason);
        }

        return new RedirectResponse($account->path('settings/account').'?linked='.$identity->provider);
    }

    /**
     * Where a completed sign-in lands: the person's own notes list.
     *
     * NOT `/`, which it was until 2026-08-30: nginx serves the static landing
     * page at that exact path, and this is a full-page redirect the browser
     * follows to the server rather than an in-app route the SPA can catch.
     * Sending somebody there after a successful OAuth round trip would sign
     * them in and then show them the marketing page, with nothing on it saying
     * they are signed in.
     *
     * And no longer a CONSTANT, since every page moved inside the account's
     * handle: `/notes` names no knowledge base and is a 404 on the box.
     */
    private static function home(Account $account): string
    {
        return $account->path();
    }

    /**
     * Establish the session. {@see SignInAuthenticator} authenticates no
     * request; it is named here so the session token records a person's sign-in.
     */
    private function login(Account $account): void
    {
        $this->security->login($account, SignInAuthenticator::class);
    }

    /** A browser holds one account: signing in as another ends the first one's session. */
    private function signOutAnyoneBut(?Account $next): void
    {
        $current = $this->security->getUser();
        if ($current instanceof Account && $current->getId() !== $next?->getId()) {
            $this->security->logout(false);
        }
    }

    /**
     * Back to the SPA with a reason the screen knows how to word.
     *
     * A `link` bounce lands on Settings, which is inside the person's own
     * knowledge base — so it needs their handle, and they are signed in by
     * definition (linking a provider to no account is not a thing). `/login`
     * needs no handle and takes none: it is one of the few pages that exists
     * outside every knowledge base, because whoever is reading it is in none.
     */
    private function bounce(string $intent, string $error): RedirectResponse
    {
        $account = $this->security->getUser();
        $path = $intent === 'link' && $account instanceof Account
            ? $account->path('settings/account')
            : '/login';

        return new RedirectResponse($path.'?error='.urlencode($error));
    }

    /**
     * Must match what is registered with the provider CHARACTER FOR CHARACTER,
     * which is the single commonest way this breaks. `APP_BASE_URL` makes it an
     * explicit setting rather than something derived from whatever Host header
     * arrived; the derivation is the fallback so a dev box needs no config.
     */
    private function redirectUri(Request $request, string $provider): string
    {
        $base = trim($this->appBaseUrl) !== '' ? rtrim(trim($this->appBaseUrl), '/') : $request->getSchemeAndHttpHost();

        return $base.'/api/auth/'.$provider.'/callback';
    }
}
