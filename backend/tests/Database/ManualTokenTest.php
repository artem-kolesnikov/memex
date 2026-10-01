<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\ApiToken;

/**
 * Making a connection token by hand, which is how an agent that cannot finish
 * an OAuth flow gets in at all.
 *
 * `POST /api/tokens` outlived the Settings form that called it (removed
 * 2026-08-20, restored 2026-08-23 as the "Other agents" tab) and spent that
 * time with no test of its own, because nothing in the product reached it. It
 * is user-facing again, and it mints a credential, so the two things that must
 * hold are asserted here rather than assumed:
 *
 *  - a token can only be made from a browser session, never by a bearer token.
 *    A bearer that can mint a bearer is privilege escalation with extra steps,
 *    and the curator bearer is the one that would matter — its writes already
 *    skip the review gate.
 *  - what it mints is an AGENT. Curator is granted afterwards, in the
 *    Connections table, behind a draft and a warning. A creation form that
 *    could hand out unattended writes would be the easiest place on the site
 *    to do it by accident.
 */
class ManualTokenTest extends ApiTestCase
{
    public function testASessionMintsAWorkingAgentToken(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/tokens', ['name' => 'Hermes']);
        self::assertSame(201, $this->httpStatus());

        $created = $this->jsonResponse();
        self::assertSame('Hermes', $created['name']);
        self::assertStringStartsWith('mxt_', (string) $created['token']);

        // The plaintext is handed over once and never stored, so the only
        // proof it is the real credential is using it.
        $this->request('GET', '/api/notes', (string) $created['token']);
        self::assertSame(200, $this->httpStatus());

        $this->in($this->kb->a);
        $token = $this->em->getRepository(ApiToken::class)->find($created['id']);
        self::assertNotNull($token);
        self::assertSame(ApiToken::ROLE_AGENT, $token->getRole());
        self::assertSame(
            $this->kb->a->accountId,
            (int) self::getContainer()->get('doctrine.dbal.directory_connection')->fetchOne(
                'SELECT account_id FROM bearer_tokens WHERE connection_id = :id AND token_hash = :hash',
                ['id' => $token->getId(), 'hash' => \App\Service\BearerTokens::hash((string) $created['token'])],
            ),
        );
    }

    /** It shows up as a connection the owner can see, rename and revoke. */
    public function testTheNewTokenIsListedAsManual(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/tokens', ['name' => 'Hermes']);
        $id = $this->jsonResponse()['id'];

        $this->sessionRequest('GET', '/api/tokens');
        $listed = null;
        foreach ($this->jsonResponse()['tokens'] as $row) {
            if ($row['id'] === $id) {
                $listed = $row;
            }
        }

        self::assertNotNull($listed, 'A token that was just created is not in the list');
        self::assertSame('manual', $listed['auth']);
        self::assertSame('agent', $listed['role']);
    }

    /**
     * The escalation this endpoint would otherwise offer: an agent token whose
     * every write is held could mint itself a second token, and a curator
     * token could mint one for anybody. Session-only is the whole defence.
     *
     * @dataProvider bearers
     */
    public function testABearerTokenCannotMintATokenAtAll(string $which): void
    {
        $this->in($this->kb->a);
        $before = $this->tokenCount();

        $bearer = $which === 'curator' ? $this->kb->a->curatorBearer : $this->kb->a->agentBearer;
        $this->request('POST', '/api/tokens', $bearer, ['name' => 'a second key']);

        self::assertSame(403, $this->httpStatus(), 'A '.$which.' bearer was allowed to mint a token');
        $this->in($this->kb->a);
        self::assertSame($before, $this->tokenCount(), 'A refused request created a token anyway');
    }

    /** @return array<string, array{string}> */
    public static function bearers(): array
    {
        return ['agent' => ['agent'], 'curator' => ['curator']];
    }

    public function testAnEmptyNameIsRefused(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/tokens', ['name' => '   ']);

        self::assertSame(400, $this->httpStatus());
    }

    private function tokenCount(): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(t.id) FROM App\Entity\ApiToken t'
        )->getSingleScalarResult();
    }
}
