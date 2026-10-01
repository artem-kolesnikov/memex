<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Apple's client secret, which is not a secret anybody pastes: it is a JWT this
 * server signs, per request, with a key Apple hands over exactly once.
 *
 * ## Why it works this way
 *
 * Google's and GitHub's client secrets never expire and Microsoft's lasts at
 * most 24 months. **Apple refuses any client secret whose `exp` is more than
 * six months out**, so the pasted-value approach ends in a diary entry twice a
 * year and a sign-in that breaks on a date nobody wrote down — discovered from
 * a person who cannot get in. The operator's decision (2026-08-21) is to mint
 * one per request instead, which removes the expiry rather than scheduling it.
 * Signing is one ECDSA operation; it costs nothing next to the HTTPS round trip
 * it is an argument to.
 *
 * The lifetime below is therefore minutes rather than months. It is long enough
 * to survive a slow token exchange and clock drift in either direction, and
 * short enough that a captured one is worth nothing by the time it is read.
 *
 * ## The key is a file, deliberately
 *
 * `.p8` is a multi-line PEM, and `.env` files hold single-line values, so
 * putting the key material itself in `APPLE_OAUTH_PRIVATE_KEY` means mangling
 * it. The value is read as a path first and as PEM only if it looks like PEM,
 * so a container that can only pass a string is not locked out. On the box the
 * file lives outside the deploy tree with the rest of the secrets, because
 * everything under `backend/` is replaced wholesale on every merge.
 *
 * **Apple generates it once and stores nothing.** A lost `.p8` is not
 * recoverable; it is replaced by revoking the key and making another, which is
 * a change of `APPLE_OAUTH_KEY_ID` too.
 *
 * Every failure here is a {@see SocialAuthException}, because this runs inside
 * the callback where the alternative is a 500 on a page somebody is trying to
 * sign in on.
 */
final class AppleClientSecret
{
    /**
     * Apple's own ceiling is six months. This is ten minutes: the secret is
     * made for one token exchange and is dead long before anyone could reuse
     * it, with enough slack either side for a server clock that is a few
     * minutes out from Apple's.
     */
    private const LIFETIME = 600;

    /** Apple requires this exact audience, and rejects the token without it. */
    private const AUDIENCE = 'https://appleid.apple.com';

    public function __construct(
        private readonly string $appleTeamId,
        private readonly string $appleKeyId,
        private readonly string $applePrivateKey,
    ) {
    }

    /**
     * Whether this installation can sign anything at all. Read by
     * {@see SocialProviders::isConfigured()}, so an installation with three of
     * the four values offers no Apple button rather than a button that fails at
     * the token exchange.
     *
     * The key is checked for readability rather than validity: opening it here
     * on every sign-in screen would parse a private key for a page that only
     * wants to know whether to draw a button.
     */
    public function isConfigured(): bool
    {
        return trim($this->appleTeamId) !== ''
            && trim($this->appleKeyId) !== ''
            && $this->keyMaterial() !== null;
    }

    /**
     * A freshly signed client secret for one token exchange.
     *
     * @param string $clientId the Services ID, which is Apple's `sub` for this claim set
     * @throws SocialAuthException
     */
    public function mint(string $clientId, ?int $now = null): string
    {
        $pem = $this->keyMaterial();
        if ($pem === null || trim($this->appleTeamId) === '' || trim($this->appleKeyId) === '') {
            throw new SocialAuthException('Sign in with Apple is not fully configured on this server.', 'unconfigured');
        }

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new SocialAuthException('The Apple sign-in key could not be read as a private key.', 'unconfigured');
        }

        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
            // Worth its own sentence: the commonest way to get here is pointing
            // this at an APNs certificate or an RSA key, and "could not sign"
            // would send somebody looking at Apple rather than at the path.
            throw new SocialAuthException('The Apple sign-in key is not an elliptic-curve key — check that APPLE_OAUTH_PRIVATE_KEY points at the .p8 Apple issued.', 'unconfigured');
        }

        $now ??= time();
        $header = ['alg' => 'ES256', 'kid' => trim($this->appleKeyId), 'typ' => 'JWT'];
        $claims = [
            'iss' => trim($this->appleTeamId),
            'iat' => $now,
            'exp' => $now + self::LIFETIME,
            'aud' => self::AUDIENCE,
            'sub' => $clientId,
        ];

        $signingInput = self::b64($header).'.'.self::b64($claims);

        $der = '';
        if (!openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new SocialAuthException('Could not sign the Apple client secret.', 'unconfigured');
        }

        return $signingInput.'.'.self::b64url(self::derToJose($der));
    }

    /**
     * The PEM, from wherever the configuration points, or null if there is
     * nothing to read.
     */
    private function keyMaterial(): ?string
    {
        $value = trim($this->applePrivateKey);
        if ($value === '') {
            return null;
        }
        if (str_starts_with($value, '-----BEGIN')) {
            return $value;
        }
        if (!is_file($value) || !is_readable($value)) {
            return null;
        }
        $pem = file_get_contents($value);

        return is_string($pem) && trim($pem) !== '' ? $pem : null;
    }

    /**
     * OpenSSL signs ECDSA into DER — a SEQUENCE of two INTEGERs — and JWS wants
     * the opposite: the two values raw, fixed width, concatenated. Apple
     * rejects the DER form with `invalid_client`, which reads exactly like a
     * wrong key id and is why this conversion is the part worth testing.
     *
     * The INTEGERs are signed, so a value whose top bit is set carries a
     * leading zero byte that must come off, and a short one must be left-padded
     * back to 32. Getting either wrong produces a signature that verifies
     * roughly 255 times out of 256 — which is to say it fails rarely enough to
     * look like Apple having a bad day.
     *
     * @throws SocialAuthException
     */
    private static function derToJose(string $der): string
    {
        $offset = 0;
        $length = \strlen($der);

        $byte = static function () use ($der, &$offset, $length): int {
            if ($offset >= $length) {
                throw new SocialAuthException('The Apple client secret signature was truncated.', 'unconfigured');
            }

            return \ord($der[$offset++]);
        };

        if ($byte() !== 0x30) {
            throw new SocialAuthException('The Apple client secret signature was not a DER sequence.', 'unconfigured');
        }
        $seqLength = $byte();
        if ($seqLength > 0x80) {
            // Long form. A P-256 signature never needs it, but a length byte
            // read as a payload byte would corrupt everything after it.
            $offset += $seqLength - 0x80;
        }

        $integer = static function () use ($byte, $der, &$offset, $length): string {
            if ($byte() !== 0x02) {
                throw new SocialAuthException('The Apple client secret signature was malformed.', 'unconfigured');
            }
            $size = $byte();
            if ($offset + $size > $length) {
                throw new SocialAuthException('The Apple client secret signature was truncated.', 'unconfigured');
            }
            $value = substr($der, $offset, $size);
            $offset += $size;

            return ltrim($value, "\x00");
        };

        $r = $integer();
        $s = $integer();

        if (\strlen($r) > 32 || \strlen($s) > 32) {
            throw new SocialAuthException('The Apple client secret was signed with the wrong curve.', 'unconfigured');
        }

        return str_pad($r, 32, "\x00", STR_PAD_LEFT).str_pad($s, 32, "\x00", STR_PAD_LEFT);
    }

    /** @param array<string, mixed> $data */
    private static function b64(array $data): string
    {
        return self::b64url(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
