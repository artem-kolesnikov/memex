<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The half of the OAuth dance that happens on our side of the browser: trade
 * the code the provider sent back for an access token, then ask who it belongs
 * to.
 *
 * Kept apart from the controller because the providers disagree about almost
 * everything except the shape of the flow — GitHub wants an Accept header to
 * answer in JSON at all and a User-Agent or it refuses, Google answers OIDC,
 * and Microsoft is answered without a second call at all (see below). The
 * controller should not have to know that, and the test suite needs one seam to
 * put a mock behind.
 *
 * ## Apple: no userinfo endpoint exists, and the name arrives once or never
 *
 * Apple has no userinfo endpoint to choose against — everything it will say
 * about a person is in the id_token from the exchange. The audience check below
 * is the same one Microsoft gets and for the same reason.
 *
 * **The name is not in the token.** Apple posts it as a JSON `user` field on
 * the FIRST authorization for a Services ID and never again, not even if the
 * account is deleted here and made afresh — the only way to see it a second
 * time is for the person to remove the app under their Apple ID settings. So it
 * is captured on the way past or lost, which is why it is threaded in here as
 * an argument rather than fetched.
 *
 * **Hide My Email is Apple's alone.** `is_private_email` marks a
 * `@privaterelay.appleid.com` alias, which is a real forwarding address the
 * person controls and a perfectly good contact address — but memex can only
 * send TO it after registering with Apple's Private Email Relay service, which
 * it has not. Recorded rather than refused: an account created this way still
 * works, and the backup address is the thing that actually matters for getting
 * back in.
 *
 * ## Microsoft: the claims come out of the id_token, and are not all trustworthy
 *
 * Microsoft's userinfo endpoint reports neither of the two things that matter
 * here, so this reads the id_token that the token exchange already returned.
 *
 * **The signature is not checked, and that is correct.** The token arrived over
 * TLS on a back-channel call we made to the provider's own token endpoint,
 * authenticated with our client secret — OIDC Core §3.1.3.7 says in as many
 * words that server validation MAY stand in for signature validation on exactly
 * this path. What IS checked is the audience, which costs nothing and catches a
 * misconfiguration where two registrations have been crossed.
 *
 * **`email` on a work account is whatever an administrator typed.** Entra puts
 * no ownership check on it, so a tenant can report `someone@gmail.com` for a
 * user who has never touched that mailbox — the shape of the nOAuth problem.
 * Microsoft's answer is the optional `xms_edov` claim ("email domain owner
 * verified"), and the operator's ruling (2026-08-21) is that we honour it the
 * way Google's `email_verified` is honoured, falling back to the sign-in name
 * when it is absent or false. A UPN's suffix must be a domain the tenant has
 * verified with Microsoft, so the fallback is the address we can actually
 * stand behind.
 *
 * **The access token is used once and thrown away.** memex asks a provider for
 * a name and an id and never speaks to it again, so there is nothing to store,
 * nothing to refresh, and no scope creep into somebody's mailbox or repos. That
 * is also the honest answer to the Privacy page's promise that signing in does
 * not hand over access to anything else in that account.
 *
 * Every failure is a {@see SocialAuthException} carrying a sentence a person
 * can act on, because the alternative on this path is a blank redirect.
 */
class SocialIdentityGateway
{
    private const TIMEOUT = 15;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SocialProviders $providers,
    ) {
    }

    /**
     * @throws SocialAuthException
     */
    public function identify(string $provider, string $code, string $redirectUri, ?string $appleUser = null): SocialIdentity
    {
        $token = $this->exchange($provider, $code, $redirectUri);

        return match ($provider) {
            SocialProviders::GOOGLE => $this->googleIdentity($this->accessToken($provider, $token)),
            SocialProviders::GITHUB => $this->githubIdentity($this->accessToken($provider, $token)),
            SocialProviders::MICROSOFT => $this->microsoftIdentity($token),
            SocialProviders::APPLE => $this->appleIdentity($token, $appleUser),
            default => throw new SocialAuthException('Unknown sign-in provider.'),
        };
    }

    /**
     * The whole token reply, because the providers do not want the same part of
     * it: two go on to spend the access token at a userinfo endpoint, and
     * Microsoft's answer is already here in the id_token.
     *
     * @return array<mixed>
     * @throws SocialAuthException
     */
    private function exchange(string $provider, string $code, string $redirectUri): array
    {
        $data = $this->call('POST', $this->providers->url($provider, 'token_url'), [
            // Form-encoded rather than JSON: GitHub accepts nothing else, and
            // Google accepts both.
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $this->providers->clientId($provider),
                'client_secret' => $this->providers->clientSecret($provider),
                'redirect_uri' => $redirectUri,
            ],
            // GitHub replies form-encoded unless asked otherwise, and a
            // form-encoded body through toArray() is an exception, not a parse.
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (($data['error'] ?? null) !== null
            || (($data['access_token'] ?? null) === null && ($data['id_token'] ?? null) === null)
        ) {
            // `error_description` is Google's and Microsoft's, `error` is
            // GitHub's, and a mismatched redirect_uri is what says any of them
            // nine times out of ten. Microsoft adds a third: `invalid_client`
            // with AADSTS7000222, which is a client secret that has expired.
            $why = (string) ($data['error_description'] ?? $data['error'] ?? 'no token in the reply');
            throw new SocialAuthException(SocialProviders::label($provider).' would not complete the sign-in: '.$why);
        }

        return $data;
    }

    /**
     * @param array<mixed> $token
     * @throws SocialAuthException
     */
    private function accessToken(string $provider, array $token): string
    {
        $value = $token['access_token'] ?? null;
        if (!is_string($value) || $value === '') {
            throw new SocialAuthException(SocialProviders::label($provider).' would not complete the sign-in: no access token in the reply.');
        }

        return $value;
    }

    /** @throws SocialAuthException */
    private function googleIdentity(string $accessToken): SocialIdentity
    {
        $data = $this->call('GET', $this->providers->url(SocialProviders::GOOGLE, 'userinfo_url'), [
            'headers' => ['Authorization' => 'Bearer '.$accessToken],
        ]);

        $subject = (string) ($data['sub'] ?? '');
        if ($subject === '') {
            throw new SocialAuthException('Google did not say which account signed in.', 'provider');
        }

        // Google reports `email_verified` and we honour it: an unverified
        // address is one somebody typed, and it becomes this account's contact
        // address, so it has to have been proven to somebody.
        $verified = ($data['email_verified'] ?? false) === true || ($data['email_verified'] ?? '') === 'true';
        $email = $verified ? $this->email($data['email'] ?? null) : null;

        return new SocialIdentity(SocialProviders::GOOGLE, $subject, $email, $this->name($data['name'] ?? null, $email));
    }

    /** @throws SocialAuthException */
    private function githubIdentity(string $accessToken): SocialIdentity
    {
        $headers = [
            'Authorization' => 'Bearer '.$accessToken,
            'Accept' => 'application/vnd.github+json',
            // GitHub rejects an API request with no User-Agent outright.
            'User-Agent' => 'memex.tools',
        ];

        $data = $this->call('GET', $this->providers->url(SocialProviders::GITHUB, 'userinfo_url'), ['headers' => $headers]);

        $subject = isset($data['id']) && (is_int($data['id']) || is_string($data['id'])) ? (string) $data['id'] : '';
        if ($subject === '') {
            throw new SocialAuthException('GitHub did not say which account signed in.', 'provider');
        }

        $email = $this->email($data['email'] ?? null);
        if ($email === null) {
            // A private address is missing from /user entirely rather than
            // marked private, which reads as "this account has no email" until
            // you ask the other endpoint.
            $email = $this->githubPrimaryEmail($headers);
        }

        $name = $this->name($data['name'] ?? null, $email);
        if ($name === null && isset($data['login']) && is_string($data['login'])) {
            $name = $data['login'];
        }

        return new SocialIdentity(SocialProviders::GITHUB, $subject, $email, $name);
    }

    /**
     * @param array<mixed> $token
     * @throws SocialAuthException
     */
    private function microsoftIdentity(array $token): SocialIdentity
    {
        $claims = $this->idTokenClaims($token['id_token'] ?? null, SocialProviders::MICROSOFT);

        // Cheap, and it catches the one misconfiguration TLS cannot: an
        // id_token minted for a DIFFERENT registration, which happens on a box
        // where two apps' credentials have been crossed in .env.local.
        $audience = $claims['aud'] ?? null;
        if (!is_string($audience) || !hash_equals($this->providers->clientId(SocialProviders::MICROSOFT), $audience)) {
            throw new SocialAuthException('Microsoft answered for a different application than this one.', 'provider');
        }

        // `oid` + `tid`, which is Microsoft's own guidance for a durable key,
        // rather than `sub`. `sub` is stable for one application, so it would
        // work today; the pair keeps working if the account is ever moved
        // between tenants, and it is the identifier every piece of Microsoft's
        // documentation about this hazard is written in terms of. A personal
        // account has both too — its `tid` is the fixed consumer tenant.
        $oid = trim((string) ($claims['oid'] ?? ''));
        $tid = trim((string) ($claims['tid'] ?? ''));
        $subject = $oid !== '' && $tid !== '' ? $tid.'.'.$oid : trim((string) ($claims['sub'] ?? ''));
        if ($subject === '') {
            throw new SocialAuthException('Microsoft did not say which account signed in.', 'provider');
        }

        // The claim can arrive as a real boolean or as a string, depending on
        // the token version, and ABSENT is the common case: it is an optional
        // claim somebody has to switch on in the app registration. Absent has
        // to read as "not proven" rather than as "fine", or configuring the
        // registration wrongly silently disables the check.
        $edov = $claims['xms_edov'] ?? null;
        $verified = $edov === true || $edov === 1 || $edov === '1' || $edov === 'true';

        $email = $verified ? $this->email($claims['email'] ?? null) : null;
        // The sign-in name. Always inside a domain the tenant has verified with
        // Microsoft, which is the property the address claim lacks.
        $email ??= $this->email($this->microsoftUpn($claims['preferred_username'] ?? null));

        return new SocialIdentity(SocialProviders::MICROSOFT, $subject, $email, $this->name($claims['name'] ?? null, $email));
    }

    /**
     * @param array<mixed> $token
     * @throws SocialAuthException
     */
    private function appleIdentity(array $token, ?string $appleUser): SocialIdentity
    {
        $claims = $this->idTokenClaims($token['id_token'] ?? null, SocialProviders::APPLE);

        $audience = $claims['aud'] ?? null;
        if (!is_string($audience) || !hash_equals($this->providers->clientId(SocialProviders::APPLE), $audience)) {
            throw new SocialAuthException('Apple answered for a different application than this one.', 'provider');
        }

        $subject = trim((string) ($claims['sub'] ?? ''));
        if ($subject === '') {
            throw new SocialAuthException('Apple did not say which account signed in.', 'provider');
        }

        // Apple sends booleans as strings in some token versions and as real
        // booleans in others, and has changed which over time. Absent reads as
        // unproven, the same rule Microsoft's xms_edov gets.
        $verified = self::appleFlag($claims['email_verified'] ?? null);
        $email = $verified ? $this->email($claims['email'] ?? null) : null;

        return new SocialIdentity(SocialProviders::APPLE, $subject, $email, self::appleName($appleUser) ?? $this->name(null, $email));
    }

    /**
     * The name out of Apple's one-time `user` payload, which is JSON in a form
     * field rather than a claim.
     *
     * Anything unreadable is nothing, deliberately: this is decoration on a
     * path where the alternative is refusing a sign-in over a field Apple only
     * ever sends once, and the account is perfectly usable named after the
     * address instead.
     */
    private static function appleName(?string $payload): ?string
    {
        if ($payload === null || trim($payload) === '') {
            return null;
        }
        $data = json_decode($payload, true);
        $name = is_array($data) ? ($data['name'] ?? null) : null;
        if (!is_array($name)) {
            return null;
        }
        $parts = array_filter([
            is_string($name['firstName'] ?? null) ? trim($name['firstName']) : '',
            is_string($name['lastName'] ?? null) ? trim($name['lastName']) : '',
        ], static fn (string $part): bool => $part !== '');

        $full = trim(implode(' ', $parts));

        return $full === '' ? null : mb_substr($full, 0, 120);
    }

    /** Apple's booleans, which arrive as `true`, `"true"` or not at all. */
    private static function appleFlag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * The sign-in name, unless it is a B2B guest's.
     *
     * A guest invited into a tenant has a UPN of the form
     * `person_gmail.com#EXT#@tenant.onmicrosoft.com`. **That passes email
     * validation** — `#` is legal in a local part — so it would quietly become
     * an account's contact address, and it is not a mailbox: every message
     * memex ever sent to it would vanish. `#EXT#` is Microsoft's own marker for
     * exactly this, so a guest without a proven address is refused for want of
     * one rather than created around an identifier wearing an address's
     * clothes. Found by the test that assumed the validator would catch it.
     */
    private function microsoftUpn(mixed $upn): ?string
    {
        return is_string($upn) && !str_contains(mb_strtoupper($upn), '#EXT#') ? $upn : null;
    }

    /**
     * The middle segment of a JWT, decoded. Not verified — see this class's
     * docblock for why that is right on this path and would be wrong on any
     * other.
     *
     * @return array<string, mixed>
     * @throws SocialAuthException
     */
    private function idTokenClaims(mixed $idToken, string $provider = SocialProviders::MICROSOFT): array
    {
        $label = SocialProviders::label($provider);
        $parts = is_string($idToken) ? explode('.', $idToken) : [];
        if (count($parts) !== 3) {
            throw new SocialAuthException($label.' did not return an identity token.', 'provider');
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = $payload === false ? null : json_decode($payload, true);
        if (!is_array($claims)) {
            throw new SocialAuthException($label.' returned an identity token we could not read.', 'provider');
        }

        return $claims;
    }

    /**
     * The verified primary address, or nothing. An unverified one is not worth
     * having: it is a string somebody typed into a form.
     *
     * @param array<string, string> $headers
     */
    private function githubPrimaryEmail(array $headers): ?string
    {
        try {
            $rows = $this->call('GET', 'https://api.github.com/user/emails', ['headers' => $headers]);
        } catch (SocialAuthException) {
            // The scope can be declined. Not fatal here: the caller decides
            // what a missing address means, and for an existing identity it
            // means nothing at all.
            return null;
        }

        $fallback = null;
        foreach ($rows as $row) {
            if (!is_array($row) || ($row['verified'] ?? false) !== true) {
                continue;
            }
            $email = $this->email($row['email'] ?? null);
            if ($email === null) {
                continue;
            }
            if (($row['primary'] ?? false) === true) {
                return $email;
            }
            $fallback ??= $email;
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<mixed>
     * @throws SocialAuthException
     */
    private function call(string $method, string $url, array $options): array
    {
        if ($url === '') {
            throw new SocialAuthException('That sign-in provider is not configured on this server.', 'unconfigured');
        }
        try {
            $response = $this->httpClient->request($method, $url, $options + ['timeout' => self::TIMEOUT]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new SocialAuthException('Could not reach the sign-in provider: '.$e->getMessage(), 'provider', $e);
        }

        // The token endpoint answers 200 with an `error` key on some failures,
        // so status alone is not the test — but a 4xx with no body still has to
        // become a sentence rather than an empty array.
        if ($status >= 400 && ($data['error'] ?? $data['message'] ?? null) === null) {
            throw new SocialAuthException('The sign-in provider answered with an error ('.$status.').');
        }

        return $data;
    }

    private function email(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $email = trim($value);

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($email) : null;
    }

    private function name(mixed $value, ?string $email): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return mb_substr(trim($value), 0, 120);
        }
        if ($email !== null) {
            return mb_substr(explode('@', $email)[0], 0, 120);
        }

        return null;
    }
}
