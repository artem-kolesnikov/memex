<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Symmetric encryption for the secrets this application stores on a user's
 * behalf: their own AI provider key, and the tokens they made for their
 * assistants ({@see ConnectionSecrets}), each under a key of its own.
 *
 * AES-256-GCM (ext-openssl, always present in the PHP the box installs —
 * `php8.3-sodium` is not, see deploy/provision-base.sh) under a key derived
 * from APP_SECRET with HKDF, so no second secret has to be provisioned,
 * rotated or remembered on a box where dev is prod.
 *
 * **The consequence of that choice, stated rather than discovered:** rotating
 * APP_SECRET makes every stored provider key undecryptable. That is why
 * `decrypt()` returns null instead of throwing — the caller falls back to the
 * server's own key exactly as it does for an account that never supplied one,
 * and the user re-enters theirs. A rotation must never turn every capture into
 * a 500.
 */
final class CredentialCipher
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    private readonly string $key;

    public function __construct(string $appSecret, string $purpose = 'memex.team-ai-credentials')
    {
        $this->key = hash_hkdf('sha256', $appSecret, 32, $purpose);
    }

    /** @return string base64 of iv|tag|ciphertext */
    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES);
        if ($ciphertext === false) {
            throw new \RuntimeException('Could not encrypt credential');
        }

        return base64_encode($iv.$tag.$ciphertext);
    }

    /** @return string|null null = this ciphertext cannot be read with the current APP_SECRET */
    public function decrypt(string $stored): ?string
    {
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) <= self::IV_BYTES + self::TAG_BYTES) {
            return null;
        }
        $plaintext = openssl_decrypt(
            substr($raw, self::IV_BYTES + self::TAG_BYTES),
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::IV_BYTES),
            substr($raw, self::IV_BYTES, self::TAG_BYTES)
        );

        return $plaintext === false ? null : $plaintext;
    }
}
