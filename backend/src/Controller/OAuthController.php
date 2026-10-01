<?php

declare(strict_types=1);

namespace App\Controller;

use App\Directory\Account;
use App\Entity\ApiToken;
use App\Security\ApiTokenAuthenticator;
use App\Service\AccountDoor;
use App\Service\BearerTokens;
use App\Service\GrowthLimits;
use App\Service\StorageLimitExceeded;
use App\Storage\VaultScope;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Minimal OAuth 2.1 authorization server for MCP clients that cannot send a
 * bearer token directly (claude.ai / the Claude mobile apps). Implements what
 * those clients need and nothing more: RFC 8414/9728 discovery, RFC 7591
 * dynamic client registration, authorization-code + PKCE (S256, public
 * clients). The access token minted at /oauth/token IS a regular ApiToken —
 * agent writes through OAuth-connected clients hit the same review gate.
 *
 * The authorize page approves on the strength of the browser session; somebody
 * not signed in is sent to the ordinary sign-in screen first.
 */
class OAuthController extends AbstractController
{
    /** The consent form's CSRF token id. One page, one id. */
    private const CONSENT_CSRF_TOKEN = 'oauth_consent';

    private const CODE_TTL_SECONDS = 600;

    /**
     * **This server grants no scopes, and no authorization ever mints a curator
     * token** (operator, 2026-08-20). Anything a client asks for is dropped
     * rather than refused: RFC 6749 §3.3 lets the server issue a narrower grant
     * than was requested, and refusing the whole authorization over an unknown
     * scope would break clients that ask politely for more than they need.
     *
     * The rule this replaces let `scope=curator` mint a curator token, on the
     * argument that signing in on a page which states the grant IS the consent.
     * Measured against real clients, that argument failed: ChatGPT and Gemini
     * both request `scope=curator` unprompted, so the "decision" was a page the
     * user clicked through on the way to connecting, and the easy path handed
     * out ungated write access. **Curator is now granted in one place only, by
     * a person, to a connection that already exists**, on the Connections
     * screen. That is a deliberate second act rather than a sentence nobody
     * read on the way somewhere else.
     */

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.directory_connection')]
        private readonly Connection $directory,
        private readonly EntityManagerInterface $directoryEntityManager,
        private readonly VaultScope $scope,
        private readonly BearerTokens $tokens,
        private readonly GrowthLimits $growth,
        private readonly AccountDoor $door,
        private readonly CsrfTokenManagerInterface $csrf,
        // Named for the `oauth_register` limiter in rate_limiter.yaml — the
        // camel-cased service alias Symfony mints from it.
        private readonly RateLimiterFactory $oauthRegisterLimiter,
    ) {
    }

    /** RFC 9728 — pointed to by the MCP 401's WWW-Authenticate header. */
    #[Route('/.well-known/oauth-protected-resource/{suffix}', requirements: ['suffix' => '.*'], defaults: ['suffix' => ''], methods: ['GET'])]
    public function protectedResourceMetadata(Request $request): JsonResponse
    {
        $base = $request->getSchemeAndHttpHost();

        return $this->corsJson([
            'resource' => $base.'/mcp',
            'authorization_servers' => [$base],
            'bearer_methods_supported' => ['header'],
        ]);
    }

    /** RFC 8414. */
    #[Route('/.well-known/oauth-authorization-server/{suffix}', requirements: ['suffix' => '.*'], defaults: ['suffix' => ''], methods: ['GET'])]
    public function authorizationServerMetadata(Request $request): JsonResponse
    {
        $base = $request->getSchemeAndHttpHost();

        return $this->corsJson([
            'issuer' => $base,
            'authorization_endpoint' => $base.'/oauth/authorize',
            'token_endpoint' => $base.'/oauth/token',
            'registration_endpoint' => $base.'/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            // No `scopes_supported`: RFC 8414 makes it optional, and this
            // server grants none. Advertising one it will not honour is how a
            // client comes to believe it holds access it does not have.
        ]);
    }

    /** RFC 7591 dynamic client registration (open, as claude.ai expects). */
    #[Route('/oauth/register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        // Open, but not unbounded: this is an unauthenticated INSERT, and
        // until 2026-08-24 nothing stopped one caller filling `oauth_clients`.
        // Per client address, before any parsing — the cheapest possible
        // refusal is the one that happens before the work. Every request
        // consumes one token whatever its outcome, which is right for a limit
        // whose job is bounding attempts, malformed ones included.
        //
        // `getClientIp()` is the caller only when TRUSTED_PROXIES names whatever
        // sits in front of PHP. With it empty, X-Forwarded-For is ignored and
        // the address is REMOTE_ADDR, right where nothing is in front (nginx on
        // memex.tools's box is the edge, docs/MCP-WAF.md). **A proxy in front
        // and TRUSTED_PROXIES empty make this one limit of 20/hour for
        // everybody**, because every request arrives from the proxy.
        $limit = $this->oauthRegisterLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            // NOT an RFC 7591 error code. `invalid_client_metadata` means "your
            // metadata is wrong", which invites a well-behaved client to fix
            // its request and retry AT ONCE — the opposite of what a rate limit
            // wants. `temporarily_unavailable` reads as "come back later",
            // which is the truth, and Retry-After says when.
            return $this->corsJson([
                'error' => 'temporarily_unavailable',
                'error_description' => 'Too many registration attempts from this address. Try again later.',
            ], Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
            ]);
        }

        try {
            $data = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->corsJson(['error' => 'invalid_client_metadata'], Response::HTTP_BAD_REQUEST);
        }

        // https for real clients; plain-http loopback for native/headless MCP
        // clients (RFC 8252 §7.3 — the Python MCP SDK's OAuth client registers
        // http://localhost:<port>/... and headless agents like Hermes died here).
        $redirectUris = array_values(array_filter(
            is_array($data['redirect_uris'] ?? null) ? $data['redirect_uris'] : [],
            static fn ($uri) => is_string($uri)
                && (str_starts_with($uri, 'https://') || self::isLoopbackUri($uri))
        ));
        if ($redirectUris === []) {
            return $this->corsJson(['error' => 'invalid_redirect_uri', 'error_description' => 'At least one https or loopback (http://localhost) redirect_uri is required'], Response::HTTP_BAD_REQUEST);
        }

        $clientId = 'mxc_'.bin2hex(random_bytes(16));
        $clientName = mb_substr(trim((string) ($data['client_name'] ?? 'MCP client')), 0, 255) ?: 'MCP client';
        $this->directory->executeStatement(
            'INSERT INTO oauth_clients (client_id, client_name, redirect_uris, created_at) VALUES (:id, :name, :uris, :now)',
            ['id' => $clientId, 'name' => $clientName, 'uris' => json_encode($redirectUris), 'now' => self::stamp(new \DateTimeImmutable())]
        );

        return $this->corsJson([
            'client_id' => $clientId,
            'client_name' => $clientName,
            'redirect_uris' => $redirectUris,
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code'],
            'response_types' => ['code'],
        ], Response::HTTP_CREATED);
    }

    #[Route('/oauth/authorize', methods: ['GET', 'POST'])]
    public function authorize(Request $request): Response
    {
        $clientId = (string) $request->get('client_id', '');
        $redirectUri = (string) $request->get('redirect_uri', '');
        $state = (string) $request->get('state', '');
        $codeChallenge = (string) $request->get('code_challenge', '');
        $challengeMethod = (string) $request->get('code_challenge_method', '');
        $responseType = (string) $request->get('response_type', '');

        $client = $this->directory->fetchAssociative(
            'SELECT client_id, client_name, redirect_uris FROM oauth_clients WHERE client_id = :id',
            ['id' => $clientId]
        );
        // Errors before redirect_uri validation must NOT redirect (spec).
        if ($client === false) {
            return new Response('Unknown client_id', Response::HTTP_BAD_REQUEST);
        }
        if (!self::redirectUriRegistered($redirectUri, json_decode($client['redirect_uris'], true) ?: [])) {
            return new Response('redirect_uri not registered for this client', Response::HTTP_BAD_REQUEST);
        }
        if ($responseType !== 'code' || $challengeMethod !== 'S256' || $codeChallenge === '') {
            return $this->redirectError($redirectUri, $state, 'invalid_request', 'response_type=code with S256 PKCE required');
        }

        // WHO IS ASKING is the browser session. Somebody without one goes to
        // the ordinary sign-in screen, so the provider buttons are the ones
        // they already used and there is one sign-in surface in the product.
        $account = $this->getUser();
        if (!$account instanceof Account || $request->attributes->get(ApiTokenAuthenticator::REQUEST_ATTRIBUTE) instanceof ApiToken) {
            // A bearer token is not a person at a browser, and an assistant
            // must never be able to authorize its own successor.
            return new RedirectResponse('/login?next='.rawurlencode($request->getRequestUri()));
        }
        $refusal = $this->door->accountRefusal($account);
        if ($refusal !== null) {
            return new RedirectResponse('/login?error='.rawurlencode($refusal->reason));
        }

        // Said before the button rather than after it: the person is here
        // now, and the client only learns at the token exchange, where its
        // user reads nothing.
        $full = $this->scope->run($account->vault(), fn (): ?StorageLimitExceeded => $this->growth->connectionRefusal(alert: true));
        if ($full !== null) {
            return new Response($this->authorizePage($request->getHost(), $client['client_name'], $request->query->all(), $account, $full->getMessage(), connectable: false));
        }

        $error = null;
        if ($request->isMethod('POST')) {
            // The session is now the credential, so this POST is forgeable and
            // the token is what refuses the forgery. See the note on
            // `csrf_protection` in config/packages/framework.yaml: /oauth/register
            // is public, so the client and its redirect_uri can be the
            // attacker's, and PKCE protects them rather than us.
            if (!$this->isCsrfTokenValid(self::CONSENT_CSRF_TOKEN, (string) $request->request->get('_csrf', ''))) {
                $error = 'That form went stale, or did not come from this page. Please try again.';
            } else {
                $code = 'mxa_'.bin2hex(random_bytes(24));
                $now = new \DateTimeImmutable();
                $this->directory->executeStatement(
                    'INSERT INTO oauth_codes (code, client_id, account_id, redirect_uri, code_challenge, expires_at, created_at)
                     VALUES (:code, :client, :account, :uri, :challenge, :expires, :now)',
                    [
                        'code' => $code,
                        'client' => $clientId,
                        'account' => $account->getId(),
                        'uri' => $redirectUri,
                        'challenge' => $codeChallenge,
                        'expires' => self::stamp($now->modify('+'.self::CODE_TTL_SECONDS.' seconds')),
                        'now' => self::stamp($now),
                    ]
                );
                $sep = str_contains($redirectUri, '?') ? '&' : '?';

                return new RedirectResponse($redirectUri.$sep.http_build_query(['code' => $code, 'state' => $state]));
            }
        }

        return new Response($this->authorizePage($request->getHost(), $client['client_name'], $request->query->all(), $account, $error));
    }

    #[Route('/oauth/token', methods: ['POST'])]
    public function token(Request $request): JsonResponse
    {
        if ($request->request->get('grant_type') !== 'authorization_code') {
            return $this->corsJson(['error' => 'unsupported_grant_type'], Response::HTTP_BAD_REQUEST);
        }
        $code = (string) $request->request->get('code', '');
        $verifier = (string) $request->request->get('code_verifier', '');

        $row = $this->directory->fetchAssociative('SELECT * FROM oauth_codes WHERE code = :code', ['code' => $code]);

        if (
            $row === false
            || new \DateTimeImmutable($row['expires_at']) < new \DateTimeImmutable()
            || ($request->request->get('client_id') !== null && $request->request->get('client_id') !== $row['client_id'])
            || ($request->request->get('redirect_uri') !== null && $request->request->get('redirect_uri') !== $row['redirect_uri'])
        ) {
            return $this->corsJson(['error' => 'invalid_grant'], Response::HTTP_BAD_REQUEST);
        }
        $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if ($verifier === '' || !hash_equals($row['code_challenge'], $expectedChallenge)) {
            return $this->corsJson(['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed'], Response::HTTP_BAD_REQUEST);
        }

        $account = $this->directoryEntityManager->find(Account::class, (int) $row['account_id']);
        $client = $this->directory->fetchAssociative('SELECT client_name FROM oauth_clients WHERE client_id = :id', ['id' => $row['client_id']]);
        if ($account === null || $client === false) {
            return $this->corsJson(['error' => 'invalid_grant'], Response::HTTP_BAD_REQUEST);
        }
        $refusal = $this->door->accountRefusal($account);
        if ($refusal !== null) {
            return $this->corsJson(['error' => 'invalid_grant', 'error_description' => $refusal->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        // The access token is a normal connection: visible and revocable in
        // Settings, attributed on every write, review-gated like any agent.
        // Deliberately no role assignment of any kind. An OAuth token is an
        // agent token, always, whatever was asked for. Promotion is a human
        // act on the Connections screen.
        //
        // The code is spent in the same directory transaction that writes the
        // route, so of two exchanges racing on one code only one mints.
        $spent = false;
        try {
            [, $plaintext] = $this->scope->run(
                $account->vault(),
                function () use ($account, $client, $code, $row, &$spent): array {
                    return $this->tokens->issue($account, 'oauth: '.$client['client_name'], static function (Connection $directory) use ($code, $row, &$spent): void {
                        if ($directory->executeStatement('DELETE FROM oauth_codes WHERE code = :code', ['code' => $code]) !== 1) {
                            $spent = true;

                            throw new \RuntimeException('The authorization code was spent by another exchange.');
                        }

                        // Stamped here and nowhere else: the token exchange is the moment a
                        // registration stops being a row somebody POSTed and becomes a
                        // connection somebody uses. `app:purge-oauth` reads this to decide what
                        // it may delete, so stamping it anywhere looser — at /authorize, say,
                        // which any caller can reach — would keep abandoned rows alive forever.
                        //
                        // BELOW the guard, not above it: an exchange that is about to be
                        // refused has not used anything, and marking its client as used would
                        // be a small lie told to the one query that decides what gets deleted.
                        $directory->executeStatement(
                            'UPDATE oauth_clients SET last_used_at = :now WHERE client_id = :id',
                            ['now' => self::stamp(new \DateTimeImmutable()), 'id' => $row['client_id']]
                        );
                    });
                },
            );
        } catch (StorageLimitExceeded $e) {
            return $this->corsJson(['error' => 'invalid_grant', 'error_description' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            if (!$spent) {
                throw $e;
            }

            return $this->corsJson(['error' => 'invalid_grant'], Response::HTTP_BAD_REQUEST);
        }

        // RFC 6749 §5.1 asks for `scope` only when the grant differs from the
        // request. It always does now, and the honest statement of what was
        // granted is nothing, so the field is absent — byte-identical to the
        // response every client handled before scopes were ever introduced.
        return $this->corsJson(['access_token' => $plaintext, 'token_type' => 'Bearer']);
    }

    /** CORS preflight for token/register (browser-based clients). */
    #[Route('/oauth/{endpoint}', requirements: ['endpoint' => 'token|register'], methods: ['OPTIONS'])]
    #[Route('/.well-known/{suffix}', requirements: ['suffix' => '.*'], methods: ['OPTIONS'])]
    public function preflight(): Response
    {
        return $this->withCors(new Response('', Response::HTTP_NO_CONTENT));
    }

    private function authorizePage(string $site, string $clientName, array $query, Account $account, ?string $error, bool $connectable = true): string
    {
        $clientNameEsc = htmlspecialchars($clientName, ENT_QUOTES);
        // The name is whatever the registrant typed, and registration is
        // public, so it is the host the code is sent to that says who is asking.
        $hostEsc = htmlspecialchars((string) parse_url((string) ($query['redirect_uri'] ?? ''), PHP_URL_HOST), ENT_QUOTES);
        $notice = self::grantNotice();
        $errorHtml = $error !== null ? '<p class="error">'.htmlspecialchars($error, ENT_QUOTES).'</p>' : '';
        $button = $connectable ? '<button type="submit">Connect it</button>' : '';
        $whoEsc = htmlspecialchars($account->getName().' · '.$account->getEmail(), ENT_QUOTES);
        $siteEsc = htmlspecialchars($site, ENT_QUOTES);
        $csrf = htmlspecialchars($this->csrf->getToken(self::CONSENT_CSRF_TOKEN)->getValue(), ENT_QUOTES);
        // Not a router path: this page is server-rendered and the SPA is not
        // mounted, so leaving has to be a real navigation.
        $switch = htmlspecialchars('/login?next='.rawurlencode($this->currentUri($query)), ENT_QUOTES);
        $hidden = '';
        foreach ($query as $key => $value) {
            if (is_string($value)) {
                $hidden .= '<input type="hidden" name="'.htmlspecialchars($key, ENT_QUOTES).'" value="'.htmlspecialchars($value, ENT_QUOTES).'">';
            }
        }

        return <<<HTML
        <!doctype html>
        <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title>{$siteEsc} — authorize</title>
        <style>
        body{font-family:-apple-system,system-ui,sans-serif;background:#f5f5f5;display:flex;justify-content:center;padding:2rem 1rem}
        .card{background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);padding:2rem;max-width:24rem;width:100%}
        h1{font-size:1.2rem;margin:0 0 .3rem}p{color:#555;font-size:.95rem}
        button{width:100%;padding:.75rem;margin-top:1rem;background:#1867c0;color:#fff;border:0;border-radius:8px;font-size:1rem;cursor:pointer}
        .error{color:#b00020}.gate{background:#fff8e1;border-radius:8px;padding:.6rem .8rem;font-size:.85rem;color:#6d5c00}
        .who{background:#f0f4f9;border-radius:8px;padding:.6rem .8rem;font-size:.9rem;color:#33415c}
        .alt{text-align:center;font-size:.85rem;margin:.9rem 0 0}.alt a{color:#1867c0}
        </style></head><body>
        <form class="card" method="post">
          <h1>{$siteEsc}</h1>
          <p><strong>{$clientNameEsc}</strong> is requesting access to your knowledge base from <strong>{$hostEsc}</strong>.</p>
          <p class="who">Signed in as <strong>{$whoEsc}</strong></p>
          <p class="gate">{$notice}</p>
          {$errorHtml}
          {$hidden}
          <input type="hidden" name="_csrf" value="{$csrf}">
          {$button}
          <p class="alt"><a href="{$switch}">Use a different account</a></p>
        </form>
        </body></html>
        HTML;
    }

    /**
     * The sentence on the authorize page that says what signing in grants.
     *
     * Signing in on a page that states the grant IS the consent (operator
     * 2026-08-08), and there is exactly one grant to state now, so this takes
     * no argument: whatever a client asks for, what it receives is a connection
     * whose writes are held. A function with a parameter that changed the
     * answer is what let a client put "curator access" on this page by naming
     * it in a query string.
     */
    public static function grantNotice(): string
    {
        return 'Writes made through this connection land in your review inbox as pending — nothing becomes verified without your approval. You can give it curator access later, in Settings, if you decide to trust it unattended.';
    }

    /** http:// is acceptable only on the loopback interface (RFC 8252 §7.3). */
    /**
     * This page's own address, for the "use a different account" link to come
     * back to. Built from the query the request arrived with rather than from
     * getRequestUri(), so the two paths through the page cannot disagree.
     */
    private function currentUri(array $query): string
    {
        $pairs = array_filter($query, 'is_string');

        return '/oauth/authorize'.($pairs === [] ? '' : '?'.http_build_query($pairs));
    }

    private static function stamp(\DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s');
    }

    private static function isLoopbackUri(string $uri): bool
    {
        return preg_match('#^http://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?(/|$)#', $uri) === 1;
    }

    /**
     * Exact match, except loopback URIs match regardless of port — native
     * clients bind an ephemeral port at flow time that may differ from the
     * one they registered (RFC 8252 §7.3).
     */
    private static function redirectUriRegistered(string $uri, array $registered): bool
    {
        if (in_array($uri, $registered, true)) {
            return true;
        }
        if (!self::isLoopbackUri($uri)) {
            return false;
        }
        $stripPort = static fn (string $u): string => preg_replace('#^(http://(?:localhost|127\.0\.0\.1|\[::1\])):\d+#', '$1', $u) ?? $u;
        $normalized = $stripPort($uri);
        foreach ($registered as $candidate) {
            if (is_string($candidate) && self::isLoopbackUri($candidate) && $stripPort($candidate) === $normalized) {
                return true;
            }
        }

        return false;
    }

    private function redirectError(string $redirectUri, string $state, string $error, string $description): RedirectResponse
    {
        $sep = str_contains($redirectUri, '?') ? '&' : '?';

        return new RedirectResponse($redirectUri.$sep.http_build_query([
            'error' => $error,
            'error_description' => $description,
            'state' => $state,
        ]));
    }

    /** @param array<string, string> $headers */
    private function corsJson(array $data, int $status = Response::HTTP_OK, array $headers = []): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->add($headers);
        $this->withCors($response);

        return $response;
    }

    private function withCors(Response $response): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, MCP-Protocol-Version');

        return $response;
    }
}
