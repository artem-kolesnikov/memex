<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Whether ChatGPT, Claude and Gemini, which call memex from their own servers,
 * can reach this one. An edition that only ever runs as a public service says
 * so (`memex.always_public`); otherwise the address it was installed at
 * decides: an `APP_BASE_URL` on this computer or a private network cannot be
 * reached from theirs, and then memex offers only the assistants running
 * beside it.
 */
final class PublicAddress
{
    /** Names that only a home or private network resolves. */
    private const PRIVATE_NAMES = ['.localhost', '.local', '.lan', '.home.arpa', '.internal'];

    public function __construct(
        #[Autowire(env: 'APP_BASE_URL')]
        private readonly string $appBaseUrl,
        #[Autowire('%memex.always_public%')]
        private readonly bool $alwaysPublic,
    ) {
    }

    public function reachableFromTheWeb(): bool
    {
        return $this->alwaysPublic || !self::isLocal((string) parse_url(trim($this->appBaseUrl), PHP_URL_HOST));
    }

    /** No host at all is no public address either. */
    public static function isLocal(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || $host === 'localhost' || $host === '::1') {
            return true;
        }
        foreach (self::PRIVATE_NAMES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        // 100.64.0.0/10, shared address space: a Tailscale address is one.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && (ip2long($host) & 0xFFC00000) === 0x64400000) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
