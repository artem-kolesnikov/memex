<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;

/**
 * The tokens an owner made for their assistants, kept encrypted so Settings can
 * copy one again. Whoever holds both a vault file and APP_SECRET can read that
 * vault's tokens, which reach nothing that vault file does not already hold.
 */
final class ConnectionSecrets
{
    private readonly CredentialCipher $cipher;

    public function __construct(string $appSecret)
    {
        $this->cipher = new CredentialCipher($appSecret, 'memex.connection-tokens');
    }

    public function keep(ApiToken $token, string $plaintext): void
    {
        $token->keepSecret($this->cipher->encrypt($plaintext));
    }

    /** Null when the token was never kept, or APP_SECRET has changed since. */
    public function reveal(ApiToken $token): ?string
    {
        $stored = $token->getSecret();

        return $stored === null ? null : $this->cipher->decrypt($stored);
    }
}
