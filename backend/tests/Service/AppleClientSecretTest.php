<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AppleClientSecret;
use App\Service\SocialAuthException;
use PHPUnit\Framework\TestCase;

/**
 * The one client secret memex signs for itself.
 *
 * Everything here is unreachable by reading the code. A JWT with the right
 * claims and a subtly wrong signature looks identical to a correct one until
 * Apple answers `invalid_client`, and the DER-to-JOSE conversion fails on a
 * minority of signatures rather than on all of them — which is the worst
 * failure shape there is, because it reads as an outage at Apple.
 */
class AppleClientSecretTest extends TestCase
{
    private static string $pem = '';

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        self::$pem = $pem;
    }

    private function signer(?string $key = null, string $team = 'TEAM123456', string $keyId = 'KEY1234567'): AppleClientSecret
    {
        return new AppleClientSecret($team, $keyId, $key ?? self::$pem);
    }

    public function testTheSecretCarriesTheClaimsAppleRequires(): void
    {
        $jwt = $this->signer()->mint('tools.memex.signin', 1_000_000);
        [$header, $claims] = $this->parse($jwt);

        self::assertSame('ES256', $header['alg']);
        self::assertSame('KEY1234567', $header['kid'], 'Apple finds the public key by kid; without it every sign-in is invalid_client.');
        self::assertSame('JWT', $header['typ']);

        self::assertSame('TEAM123456', $claims['iss'], 'The issuer is the TEAM, not the Services ID.');
        self::assertSame('tools.memex.signin', $claims['sub'], 'The subject is the Services ID, which is also the client_id.');
        self::assertSame('https://appleid.apple.com', $claims['aud']);
        self::assertSame(1_000_000, $claims['iat']);
        self::assertGreaterThan($claims['iat'], $claims['exp']);
    }

    /**
     * Apple's ceiling is six months. This asserts the opposite property — that
     * it is short — because the reason for minting per request is that no date
     * has to be remembered, and a long-lived one here would quietly reintroduce
     * the thing the design removed.
     */
    public function testTheSecretExpiresInMinutesRatherThanMonths(): void
    {
        [, $claims] = $this->parse($this->signer()->mint('tools.memex.signin', 1_000_000));

        self::assertLessThanOrEqual(3600, $claims['exp'] - $claims['iat']);
        self::assertGreaterThanOrEqual(60, $claims['exp'] - $claims['iat'], 'Too short leaves no room for clock drift against Apple.');
    }

    /**
     * The signature verifies against the public half — which is the only thing
     * that proves the DER-to-JOSE conversion, since a wrong one still produces
     * a perfectly well-formed JWT.
     *
     * Repeated, because the conversion's failure modes depend on the values
     * ECDSA happens to produce: roughly one signature in 256 has a leading zero
     * byte to strip and one in 256 is a byte short and needs padding. A single
     * round trip would pass against an implementation that handles neither.
     */
    public function testTheSignatureVerifiesAgainstThePublicKey(): void
    {
        $public = openssl_pkey_get_details(openssl_pkey_get_private(self::$pem))['key'];

        for ($i = 0; $i < 200; ++$i) {
            $jwt = $this->signer()->mint('tools.memex.signin');
            $parts = explode('.', $jwt);
            $signature = base64_decode(strtr($parts[2], '-_', '+/'), true);

            self::assertNotFalse($signature);
            self::assertSame(64, \strlen($signature), 'A JOSE ES256 signature is exactly two 32-byte integers.');
            self::assertSame(
                1,
                openssl_verify($parts[0].'.'.$parts[1], self::joseToDer($signature), $public, OPENSSL_ALGO_SHA256),
                'Signature '.$i.' did not verify — the DER conversion drops or mispads a byte for some values.'
            );
        }
    }

    public function testAnInstallationWithoutTheFourValuesOffersNothing(): void
    {
        self::assertTrue($this->signer()->isConfigured());
        self::assertFalse($this->signer(team: '')->isConfigured(), 'No team id.');
        self::assertFalse($this->signer(keyId: '')->isConfigured(), 'No key id.');
        self::assertFalse($this->signer('')->isConfigured(), 'No key.');
        self::assertFalse($this->signer('/no/such/apple-key.p8')->isConfigured(), 'A path to nothing.');
    }

    public function testTheKeyMayBeAPathToTheP8(): void
    {
        $path = sys_get_temp_dir().'/memex-apple-test-'.bin2hex(random_bytes(6)).'.p8';
        file_put_contents($path, self::$pem);

        try {
            $signer = $this->signer($path);
            self::assertTrue($signer->isConfigured());
            self::assertCount(3, explode('.', $signer->mint('tools.memex.signin')));
        } finally {
            @unlink($path);
        }
    }

    /**
     * The commonest way to misconfigure this is pointing it at some other key
     * Apple issued. `could not sign` would send somebody to look at Apple; the
     * message has to send them to the path.
     */
    public function testAKeyThatIsNotEllipticCurveSaysSo(): void
    {
        $rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($rsa, $pem);

        $this->expectException(SocialAuthException::class);
        $this->expectExceptionMessageMatches('/elliptic-curve/');
        $this->signer($pem)->mint('tools.memex.signin');
    }

    public function testRubbishWhereTheKeyShouldBeIsRefusedRatherThanSigned(): void
    {
        $this->expectException(SocialAuthException::class);
        $this->signer("-----BEGIN PRIVATE KEY-----\nnot a key\n-----END PRIVATE KEY-----")->mint('tools.memex.signin');
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function parse(string $jwt): array
    {
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts, 'A JWT is three segments.');

        return [
            json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true),
            json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true),
        ];
    }

    /**
     * The inverse of the conversion under test, so `openssl_verify` — which
     * speaks DER only — can check a JOSE signature. Deliberately written the
     * long way rather than borrowed from the class it is checking.
     */
    private static function joseToDer(string $signature): string
    {
        $encode = static function (string $value): string {
            $value = ltrim($value, "\x00");
            if ($value === '') {
                $value = "\x00";
            }
            if ((\ord($value[0]) & 0x80) !== 0) {
                $value = "\x00".$value;
            }

            return "\x02".\chr(\strlen($value)).$value;
        };

        $body = $encode(substr($signature, 0, 32)).$encode(substr($signature, 32));

        return "\x30".\chr(\strlen($body)).$body;
    }
}
