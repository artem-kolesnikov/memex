<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PublicAddress;
use PHPUnit\Framework\TestCase;

/**
 * A memex installed at an address ChatGPT, Claude and Gemini cannot reach
 * offers them nowhere: Settings, the wizard and the welcome note would
 * otherwise walk its owner through steps that cannot work.
 */
final class PublicAddressTest extends TestCase
{
    public function testAnAddressOnThisComputerOrAPrivateNetworkIsNotReachable(): void
    {
        foreach (['', 'http://localhost:8080', 'http://LOCALHOST', 'http://memex.localhost:5100', 'http://mini.local:8080',
            'http://127.0.0.1:8080', 'http://[::1]:8080', 'http://10.0.0.5', 'http://172.20.1.2:8080', 'http://192.168.1.10:8080',
            'http://169.254.10.1', 'http://100.101.102.103:8080', 'http://mini.home.arpa:8080', 'http://nas.lan', 'https://memex.corp.internal',
            'not an address'] as $base) {
            self::assertFalse((new PublicAddress($base, false))->reachableFromTheWeb(), $base);
        }
    }

    public function testAPublicAddressIsReachable(): void
    {
        foreach (['https://memex.example.org', 'https://memex.example.org/', 'http://203.0.113.7:8080', 'https://172.32.0.1', 'https://100.128.0.1'] as $base) {
            self::assertTrue((new PublicAddress($base, false))->reachableFromTheWeb(), $base);
        }
    }

    public function testAnAlwaysPublicEditionIsReachableAtAnyAddress(): void
    {
        self::assertTrue((new PublicAddress('http://localhost:5100', true))->reachableFromTheWeb());
    }
}
