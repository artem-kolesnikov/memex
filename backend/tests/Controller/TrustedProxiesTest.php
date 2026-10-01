<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Behind a TLS proxy the OAuth metadata has to name the address assistants
 * reach, https included, or claude.ai refuses the server.
 */
final class TrustedProxiesTest extends WebTestCase
{
    private const FORWARDED = [
        'REMOTE_ADDR' => '10.0.0.2',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'memex.example.org',
        'HTTP_X_FORWARDED_PORT' => '443',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
    ];

    private string $before = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->before = $_SERVER['TRUSTED_PROXIES'] ?? '';
    }

    protected function tearDown(): void
    {
        $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'] = $this->before;
        Request::setTrustedProxies([], -1);
        parent::tearDown();
    }

    public function testANamedProxyGivesTheVisitorsAddress(): void
    {
        $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8';

        self::assertSame('https://memex.example.org', $this->issuer());
    }

    public function testWithNoProxyNamedTheHeadersAreIgnored(): void
    {
        self::assertSame('http://localhost', $this->issuer());
    }

    private function issuer(): string
    {
        $client = static::createClient();
        $client->request('GET', '/.well-known/oauth-authorization-server', server: self::FORWARDED);
        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['issuer'];
    }
}
