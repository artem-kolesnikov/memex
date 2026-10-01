<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\ApiToken;
use App\Entity\Note;
use App\Service\UserGuide;
use App\Service\WelcomeNotes;

/**
 * The first-run wizard: what it reports, and what it refuses to call progress.
 *
 * The wizard stores no step. Every screen it opens on is derived from the
 * facts below, so a fact that reads true too early sends a new person past the
 * step they actually needed — which is a failure with no visible symptom, the
 * wizard simply looks finished. Each test here is one of those.
 */
class WelcomeWizardTest extends ApiTestCase
{
    public function testANewAccountIsSentToTheWizard(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/me');
        self::assertFalse(
            $this->jsonResponse()['welcome_completed'],
            'an account that has never been through the wizard is routed to it'
        );

        $this->sessionRequest('POST', '/api/me/welcome/done');
        self::assertSame(200, $this->httpStatus());

        $this->sessionRequest('GET', '/api/me');
        self::assertTrue($this->jsonResponse()['welcome_completed'], 'and never again after that');
    }

    /**
     * The one that matters most.
     *
     * A token that exists is not a connection. Minting one and pasting it
     * nowhere is the commonest way for connecting to be left half done, and a
     * wizard that ticked on the row's existence would congratulate somebody at
     * the exact moment they were stuck.
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
        $facts = $this->jsonResponse()['facts'];
        self::assertTrue($facts['connected'], 'a token that has been used is a connection');
        self::assertSame('agent-a', $facts['connection'], 'and the step can name it');
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
     * The guide is the `memex-guide` skill and nothing else: no note is a copy
     * of it, so fetching one, even a welcome note memex wrote, records nothing.
     */
    public function testFetchingANoteMemexWroteIsNotReadingTheGuide(): void
    {
        $this->kb->a->enter();
        $welcome = self::getContainer()->get(WelcomeNotes::class)->seed($this->kb->a->handle());

        $this->mcp('get', $this->kb->a->agentBearer, ['id' => $welcome[0]->getId()]);
        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/me/welcome');
        self::assertFalse($this->jsonResponse()['facts']['guide_read']);
    }

    /** Only the guide: loading one of the vault's own skills records nothing. */
    public function testLoadingAnotherSkillIsNotReadingTheGuide(): void
    {
        $this->kb->a->note('House style', 'Body.', ['skill']);
        $this->mcp('get_skill', $this->kb->a->agentBearer, ['slug' => 'house-style']);

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/me/welcome');
        $facts = $this->jsonResponse()['facts'];
        self::assertFalse($facts['guide_read']);
        self::assertTrue($facts['connected'], 'the call was a connection all the same');
    }

    public function testLoadingTheGuideSkillCountsAsReadingIt(): void
    {
        $this->mcp('get_skill', $this->kb->a->agentBearer, ['slug' => UserGuide::SLUG]);

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/me/welcome');
        $facts = $this->jsonResponse()['facts'];
        self::assertTrue($facts['guide_read']);
        self::assertTrue($facts['connected'], 'and the call itself is what made it a connection');
        self::assertNotNull($facts['last_seen']);
    }

    /**
     * The facts are account-level. A read by one connection and a later call
     * from another leave `guide_read` true and `connection` naming the LATEST
     * caller — which is why the wizard's copy for a read stays neutral.
     */
    public function testAReadByOneConnectionAndACallByAnotherAreBothReported(): void
    {
        $this->mcp('get_skill', $this->kb->a->agentBearer, ['slug' => UserGuide::SLUG]);
        // Clearly later than the read, not merely in the same second.
        $this->in($this->kb->a);
        $this->em->getConnection()->executeStatement(
            'UPDATE api_tokens SET last_used_at = :later WHERE id = :id',
            [
                'later' => (new \DateTimeImmutable('+1 minute', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'id' => $this->kb->a->curatorTokenId,
            ]
        );

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/me/welcome');
        $facts = $this->jsonResponse()['facts'];
        self::assertTrue($facts['guide_read']);
        self::assertSame('curator-a', $facts['connection'], 'the most recent caller, not the reader');
    }

    /** A revoked connection's reading is forgotten with it. */
    public function testARevokedConnectionsReadDoesNotCount(): void
    {
        $this->mcp('get_skill', $this->kb->a->agentBearer, ['slug' => UserGuide::SLUG]);
        $this->in($this->kb->a);
        $this->em->getRepository(ApiToken::class)->find($this->kb->a->agentTokenId)->revoke();
        $this->em->flush();

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/me/welcome');
        self::assertFalse($this->jsonResponse()['facts']['guide_read']);
    }

    /** The two facts the previous wizard read stay in the answer, for a client built before this one. */
    public function testAHeldProposalIsWaitingAndAnApprovedOneIsKept(): void
    {
        $this->request('POST', '/api/notes', $this->kb->a->agentBearer, [
            'title' => 'From an assistant',
            'body_md' => 'Body.',
        ]);
        $id = $this->jsonResponse()['note']['id'];

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/me/welcome');
        $facts = $this->jsonResponse()['facts'];
        self::assertSame(1, $facts['waiting']);
        self::assertFalse($facts['kept']);

        $this->reviewedRequest('POST', "/api/notes/$id/approve");
        self::assertSame(200, $this->httpStatus());
        $this->sessionRequest('GET', '/api/me/welcome');
        $facts = $this->jsonResponse()['facts'];
        self::assertSame(0, $facts['waiting']);
        self::assertTrue($facts['kept']);
    }

    /** A live curator token is reported, a revoked one is not. */
    public function testACuratorConnectionIsReported(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/me/welcome');
        self::assertTrue($this->jsonResponse()['facts']['curator'], 'the fixture carries a curator token');

        $this->in($this->kb->a);
        $this->em->getRepository(ApiToken::class)->find($this->kb->a->curatorTokenId)->revoke();
        $this->em->flush();

        $this->sessionRequest('GET', '/api/me/welcome');
        self::assertFalse($this->jsonResponse()['facts']['curator']);
    }

    public function testSkillsAreCounted(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/me/welcome');
        self::assertSame(0, $this->jsonResponse()['facts']['skills']);

        $this->kb->a->note('House style', 'Body.', ['skill']);

        $this->sessionRequest('GET', '/api/me/welcome');
        self::assertSame(1, $this->jsonResponse()['facts']['skills']);
    }

    /**
     * Isolation, on a screen that reads six facts about a vault at once.
     *
     * Everything the wizard shows is read from the caller's own vault, and a
     * request that opened another would look perfectly healthy on a
     * single-tenant box: the numbers would simply be somebody else's.
     */
    public function testOneAccountsProgressIsNotAnothers(): void
    {
        $this->mcp('get_skill', $this->kb->b->agentBearer, ['slug' => UserGuide::SLUG]);
        $this->kb->b->note('B skill', 'Body.', ['skill']);
        $this->request('POST', '/api/notes', $this->kb->b->agentBearer, ['title' => 'B proposal', 'body_md' => 'Body.']);

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/me/welcome');

        $facts = $this->jsonResponse()['facts'];
        self::assertFalse($facts['connected'], "B's connection is not A's");
        self::assertFalse($facts['guide_read'], "B's reading is not A's");
        self::assertSame(0, $facts['skills'], "and B's skills are not A's");
        self::assertSame(0, $facts['waiting'], "B's proposals are not A's");
        self::assertNull($facts['connection']);
        self::assertNull($facts['last_seen']);
    }

    /**
     * A bearer token may not read the wizard, and may not finish it.
     *
     * Same rule every account-shaped endpoint follows: a connected assistant
     * has no business deciding what its owner is shown on sign-in.
     */
    public function testATokenCannotReachTheWizard(): void
    {
        $this->request('GET', '/api/me/welcome', $this->kb->a->agentBearer);
        self::assertSame(403, $this->httpStatus());

        $this->request('POST', '/api/me/welcome/done', $this->kb->a->agentBearer);
        self::assertSame(403, $this->httpStatus());

        $this->request('GET', '/api/me', $this->kb->a->agentBearer);
        self::assertArrayNotHasKey(
            'welcome_completed',
            $this->jsonResponse(),
            'a connection has no screen to be sent to, and the guard treats absent as not owed'
        );
    }

    /** @param array<string, mixed> $arguments */
    private function mcp(string $tool, string $bearer, array $arguments): void
    {
        $this->request('POST', '/mcp', $bearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
        self::assertSame(200, $this->httpStatus());
    }
}
