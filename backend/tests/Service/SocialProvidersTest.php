<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SocialProviders;
use PHPUnit\Framework\TestCase;

/**
 * Which providers the sign-in screen and Settings may offer: those configured,
 * and none at all in an edition that does not sign in with them.
 */
class SocialProvidersTest extends TestCase
{
    public function testAConfiguredProviderIsOffered(): void
    {
        $providers = new SocialProviders('g-id', 'g-secret', 'gh-id', 'gh-secret');

        self::assertTrue($providers->isConfigured(SocialProviders::GOOGLE));
        self::assertSame([SocialProviders::GOOGLE, SocialProviders::GITHUB], array_column($providers->available(), 'id'));
    }

    public function testAnEditionWithoutProviderSignInOffersNoneWhateverIsConfigured(): void
    {
        $providers = new SocialProviders('g-id', 'g-secret', 'gh-id', 'gh-secret', 'm-id', 'm-secret', offered: false);

        foreach ([SocialProviders::GOOGLE, SocialProviders::GITHUB, SocialProviders::MICROSOFT] as $provider) {
            self::assertFalse($providers->isConfigured($provider), $provider);
        }
        self::assertSame([], $providers->available());
    }
}
