<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The accounts a person can sign in with, and everything the sign-in screen and
 * the OAuth dance need to know about each one.
 *
 * Four of them, because providers are the only way in and that is why there
 * are four rather than two: with only Google and GitHub, "social only" means "you
 * need a Google account". GitHub is developers, a work account is usually a
 * Microsoft one, and Apple is the provider for people who avoid Google on
 * principle — disproportionately the audience a knowledge base you own appeals
 * to. The ORDER they are offered in is not stated here; it is
 * {@see self::CATALOGUE}, which is what renders.
 *
 * **Microsoft's audience is `/common`** (operator, 2026-08-21): work, school AND
 * personal Microsoft accounts. `/organizations` would serve the corporate
 * argument alone and shut out somebody whose only account is an Outlook
 * address; `/consumers` would do the reverse.
 *
 * A provider with no client id or secret configured is not offered. That is
 * what makes this safe to merge before the operator has registered anything:
 * the buttons render from {@see available()}, so an unregistered provider is
 * invisible rather than broken. An edition that does not sign in with providers
 * at all (`memex.social_sign_in`: memex-local, where the owner has a
 * password) offers none, whatever is configured.
 *
 * The URLs are checked against each provider's current documentation
 * (2026-08-21). If one moves, this is the single place to fix it.
 */
final class SocialProviders
{
    public const GOOGLE = 'google';
    public const GITHUB = 'github';
    public const MICROSOFT = 'microsoft';
    public const APPLE = 'apple';

    /**
     * The providers, in the order the sign-in screen offers them
     * (operator, 2026-08-23). Ordering lives HERE rather than in the SPA on
     * purpose: a hard-coded order in the client is a list a fifth provider
     * falls off the end of, silently, on the one screen where a missing
     * button is a person who cannot get in.
     *
     * @var array<string, array<string, string>>
     */
    private const CATALOGUE = [
        self::GOOGLE => [
            'label' => 'Google',
            'icon' => 'fa-brands fa-google',
            'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'userinfo_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
            // openid gets the stable `sub`, which is the only thing we key on.
            'scope' => 'openid email profile',
        ],
        self::APPLE => [
            'label' => 'Apple',
            'icon' => 'fa-brands fa-apple',
            'authorize_url' => 'https://appleid.apple.com/auth/authorize',
            'token_url' => 'https://appleid.apple.com/auth/token',
            // Apple has no userinfo endpoint at all — not one we choose against,
            // as with Microsoft, but one that does not exist. Everything it will
            // ever tell us about a person is in the id_token from the exchange.
            'userinfo_url' => '',
            // Space-separated like the others, and NOT `openid`: Apple returns
            // an id_token regardless, and asking for `name` or `email` is what
            // forces `response_mode=form_post` — the decision that makes Apple's
            // callback a cross-site POST. See SocialAuthController::appleCallback().
            'scope' => 'name email',
        ],
        self::MICROSOFT => [
            'label' => 'Microsoft',
            'icon' => 'fa-brands fa-microsoft',
            'authorize_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            // Deliberately empty. Microsoft HAS an OIDC userinfo endpoint, and
            // it is the wrong thing to call: it returns neither `oid`/`tid`
            // (what an account is keyed on) nor `xms_edov` (whether the address
            // it reports was ever proven). Both are id_token claims, so
            // SocialIdentityGateway reads the id_token the token endpoint
            // already handed us and makes no second call at all.
            'userinfo_url' => '',
            'scope' => 'openid email profile',
        ],
        self::GITHUB => [
            'label' => 'GitHub',
            'icon' => 'fa-brands fa-github',
            'authorize_url' => 'https://github.com/login/oauth/authorize',
            'token_url' => 'https://github.com/login/oauth/access_token',
            'userinfo_url' => 'https://api.github.com/user',
            // user:email is needed because GitHub omits a private address from
            // /user entirely, and an account we cannot name is an account we
            // cannot create.
            'scope' => 'read:user user:email',
        ],
    ];

    /** @var array<string, array{id: string, secret: string}> */
    private readonly array $credentials;

    public function __construct(
        string $googleClientId,
        string $googleClientSecret,
        string $githubClientId,
        string $githubClientSecret,
        string $microsoftClientId = '',
        string $microsoftClientSecret = '',
        string $appleClientId = '',
        // Optional so this class stays constructible from scalars alone, which
        // is what its unit tests do. An installation with no signer configured
        // simply never offers Apple.
        private readonly ?AppleClientSecret $appleSecret = null,
        #[Autowire('%memex.social_sign_in%')]
        private readonly bool $offered = true,
    ) {
        $this->credentials = [
            self::GOOGLE => ['id' => trim($googleClientId), 'secret' => trim($googleClientSecret)],
            self::GITHUB => ['id' => trim($githubClientId), 'secret' => trim($githubClientSecret)],
            self::MICROSOFT => ['id' => trim($microsoftClientId), 'secret' => trim($microsoftClientSecret)],
            // Apple's "secret" is never a stored value, so there is none to put
            // here: {@see clientSecret()} makes one on the way past.
            self::APPLE => ['id' => trim($appleClientId), 'secret' => ''],
        ];
    }

    public static function exists(string $provider): bool
    {
        return isset(self::CATALOGUE[$provider]);
    }

    public static function label(string $provider): string
    {
        return self::CATALOGUE[$provider]['label'] ?? ucfirst($provider);
    }

    public static function icon(string $provider): string
    {
        return self::CATALOGUE[$provider]['icon'] ?? 'fa-solid fa-right-to-bracket';
    }

    public function isConfigured(string $provider): bool
    {
        $creds = $this->credentials[$provider] ?? null;
        if (!$this->offered || $creds === null || $creds['id'] === '') {
            return false;
        }

        // Apple needs four values rather than two — the Services ID here, plus
        // a team, a key id and the .p8 the signer holds — and three of four is
        // no better than none: it draws a button that fails at the token
        // exchange, which is the failure this whole "not configured, not
        // offered" rule exists to avoid.
        if ($provider === self::APPLE) {
            return $this->appleSecret?->isConfigured() === true;
        }

        return $creds['secret'] !== '';
    }

    public function clientId(string $provider): string
    {
        return $this->credentials[$provider]['id'] ?? '';
    }

    /**
     * The value that goes in `client_secret` at the token endpoint.
     *
     * For three providers this is a string somebody pasted into `.env.local`.
     * **For Apple it is signed here and now**, because Apple refuses any secret
     * that lives longer than six months and a per-request one has no expiry to
     * forget. That is why this getter can throw: the alternative is a config
     * class that hands back an empty string and a token exchange that fails
     * saying `invalid_client`.
     *
     * @throws SocialAuthException when Apple's key cannot be read or signed with
     */
    public function clientSecret(string $provider): string
    {
        if ($provider === self::APPLE) {
            if ($this->appleSecret === null) {
                throw new SocialAuthException('Sign in with Apple is not configured on this server.', 'unconfigured');
            }

            return $this->appleSecret->mint($this->clientId(self::APPLE));
        }

        return $this->credentials[$provider]['secret'] ?? '';
    }

    public function url(string $provider, string $which): string
    {
        return self::CATALOGUE[$provider][$which] ?? '';
    }

    public function scope(string $provider): string
    {
        return self::CATALOGUE[$provider]['scope'] ?? '';
    }

    /**
     * What the sign-in screen may offer. Configured providers only, in
     * catalogue order.
     *
     * @return array<int, array{id: string, label: string, icon: string}>
     */
    public function available(): array
    {
        $out = [];
        foreach (array_keys(self::CATALOGUE) as $provider) {
            if ($this->isConfigured($provider)) {
                $out[] = ['id' => $provider, 'label' => self::label($provider), 'icon' => self::icon($provider)];
            }
        }

        return $out;
    }
}
