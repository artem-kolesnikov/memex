<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\ApiToken;

/**
 * What `/api/me/welcome` reports: whether an assistant has connected, which
 * the sidebar's *Connect an assistant* button reads, and the owner's profile
 * notes. A `connected` that reads true too early hides the button from the
 * person who still needs it.
 */
class WelcomeFactsTest extends ApiTestCase
{
    public function testTheAnswerIsTheTwoFactsTheAppReads(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/me/welcome');
        self::assertSame(['facts'], array_keys($this->jsonResponse()));
        self::assertSame(['connected', 'profiles'], array_keys($this->jsonResponse()['facts']));

        $this->sessionRequest('GET', '/api/me');
        self::assertArrayNotHasKey('welcome_completed', $this->jsonResponse());
    }

    /**
     * A token that exists is not a connection. Minting one and pasting it
     * nowhere is the commonest way for connecting to be left half done.
     */
    public function testAMintedTokenIsNotAConnection(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/me/welcome');
        self::assertFalse(
            $this->jsonResponse()['facts']['connected'],
            'the fixture has two tokens and neither has ever spoken'
        );

        $this->in($this->kb->a);
        $this->em->getRepository(ApiToken::class)->find($this->kb->a->agentTokenId)->touch();
        $this->em->flush();

        $this->sessionRequest('GET', '/api/me/welcome');
        self::assertTrue($this->jsonResponse()['facts']['connected'], 'a token that has been used is a connection');
    }

    /** A revoked connection is not one either, however recently it spoke. */
    public function testARevokedConnectionDoesNotCount(): void
    {
        $this->in($this->kb->a);
        $token = $this->em->getRepository(ApiToken::class)->find($this->kb->a->agentTokenId);
        $token->touch();
        $token->revoke();
        $this->em->flush();

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/me/welcome');

        self::assertFalse($this->jsonResponse()['facts']['connected']);
    }

    /**
     * Isolation: a request that opened another vault would look perfectly
     * healthy on a single-tenant box, the facts would simply be somebody
     * else's.
     */
    public function testOneAccountsProgressIsNotAnothers(): void
    {
        $this->in($this->kb->b);
        $this->em->getRepository(ApiToken::class)->find($this->kb->b->agentTokenId)->touch();
        $this->em->flush();
        $this->kb->b->note('B profile', 'Body.', ['user-profile']);

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/me/welcome');

        $facts = $this->jsonResponse()['facts'];
        self::assertFalse($facts['connected'], "B's connection is not A's");
        self::assertSame([], $facts['profiles'], "B's profile is not A's");
    }

    /** A connected assistant has no business reading what its owner is shown. */
    public function testATokenCannotReadTheFacts(): void
    {
        $this->request('GET', '/api/me/welcome', $this->kb->a->agentBearer);
        self::assertSame(403, $this->httpStatus());
    }
}
