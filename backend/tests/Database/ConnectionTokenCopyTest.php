<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Directory\Account;
use App\Entity\ApiToken;
use App\Service\BearerTokens;

/**
 * A token the owner made for an assistant can be copied again from Settings,
 * and one memex did not keep is replaced there. Only a browser session reaches
 * either, and an OAuth client's token is never kept or replaced.
 */
final class ConnectionTokenCopyTest extends ApiTestCase
{
    public function testTheOwnerCopiesATokenAgainAndOnlyItsCiphertextIsStored(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/tokens', ['name' => 'Hermes']);
        $created = $this->jsonResponse();

        $this->sessionRequest('GET', '/api/tokens/'.$created['id'].'/token');
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame($created['token'], $this->jsonResponse()['token']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        self::assertTrue($this->listed((int) $created['id'])['token_kept']);
        $this->in($this->kb->a);
        $stored = (string) $this->em->getConnection()->fetchOne('SELECT secret FROM api_tokens WHERE id = ?', [$created['id']]);
        self::assertNotSame('', $stored);
        self::assertStringNotContainsString(substr((string) $created['token'], 4), $stored);
    }

    public function testABearerTokenNeitherReadsATokenNorReplacesOne(): void
    {
        $this->request('GET', '/api/tokens/'.$this->kb->a->agentTokenId.'/token', $this->kb->a->curatorBearer);
        self::assertSame(403, $this->httpStatus());
        $this->request('POST', '/api/tokens/'.$this->kb->a->agentTokenId.'/token', $this->kb->a->curatorBearer);
        self::assertSame(403, $this->httpStatus());

        $this->request('GET', '/api/notes', $this->kb->a->agentBearer);
        self::assertSame(200, $this->httpStatus(), 'A refused replacement left the token working');
    }

    public function testATokenMemexDidNotKeepIsReplacedAndTheOldOneStops(): void
    {
        $this->loginAs($this->kb->a);
        $id = $this->kb->a->agentTokenId;
        self::assertFalse($this->listed($id)['token_kept']);
        $this->sessionRequest('GET', '/api/tokens/'.$id.'/token');
        self::assertSame(409, $this->httpStatus());

        $this->sessionRequest('POST', '/api/tokens/'.$id.'/token');
        self::assertSame(200, $this->httpStatus(), $this->body());
        $fresh = (string) $this->jsonResponse()['token'];

        $this->sessionRequest('GET', '/api/tokens/'.$id.'/token');
        self::assertSame($fresh, $this->jsonResponse()['token']);

        $this->request('GET', '/api/notes', $this->kb->a->agentBearer);
        self::assertSame(401, $this->httpStatus(), 'The replaced token still reaches the vault');
        $this->request('GET', '/api/notes', $fresh);
        self::assertSame(200, $this->httpStatus());
        $this->request('POST', '/mcp', $fresh, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'health', 'arguments' => []]]);
        self::assertSame($id, $this->tokenIdOf((string) $this->jsonResponse()['result']['structuredContent']['authenticated_as']));
    }

    public function testATokenThatNoLongerDecryptsIsOfferedForReplacement(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/tokens', ['name' => 'Hermes']);
        $id = (int) $this->jsonResponse()['id'];
        $this->in($this->kb->a);
        $this->em->getConnection()->executeStatement('UPDATE api_tokens SET secret = ? WHERE id = ?', [
            (new \App\Service\CredentialCipher('a different APP_SECRET', 'memex.connection-tokens'))->encrypt('mxt_old'),
            $id,
        ]);

        self::assertFalse($this->listed($id)['token_kept']);
        $this->sessionRequest('POST', '/api/tokens/'.$id.'/token');
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertTrue($this->listed($id)['token_kept']);
    }

    public function testRevokingForgetsTheToken(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/tokens', ['name' => 'Hermes']);
        $id = (int) $this->jsonResponse()['id'];

        $this->sessionRequest('DELETE', '/api/tokens/'.$id);
        $this->sessionRequest('GET', '/api/tokens/'.$id.'/token');
        self::assertSame(409, $this->httpStatus());
        $this->sessionRequest('POST', '/api/tokens/'.$id.'/token');
        self::assertSame(409, $this->httpStatus());

        $this->in($this->kb->a);
        self::assertNull($this->em->getConnection()->fetchOne('SELECT secret FROM api_tokens WHERE id = ?', [$id]));
    }

    public function testAnOAuthClientsTokenIsNeitherKeptNorReplaced(): void
    {
        $this->in($this->kb->a);
        $account = self::getContainer()->get('doctrine.orm.directory_entity_manager')->getRepository(Account::class)->find($this->kb->a->accountId);
        [$token, $plaintext] = self::getContainer()->get(BearerTokens::class)->issue($account, 'oauth: Claude');

        $this->loginAs($this->kb->a);
        self::assertFalse($this->listed((int) $token->getId())['token_kept']);
        $this->sessionRequest('POST', '/api/tokens/'.$token->getId().'/token');
        self::assertSame(409, $this->httpStatus());
        $this->request('GET', '/api/notes', $plaintext);
        self::assertSame(200, $this->httpStatus());
    }

    public function testEachMemexNamesItsOwnAddress(): void
    {
        $this->request('POST', '/mcp', $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18'],
        ]);
        self::assertStringStartsWith('The memex at http://localhost is ', (string) $this->jsonResponse()['result']['instructions']);

        $this->request('POST', '/mcp', $this->kb->a->agentBearer, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'health', 'arguments' => []]]);
        self::assertSame('http://localhost', $this->jsonResponse()['result']['structuredContent']['address']);
    }

    /** @return array<string, mixed> */
    private function listed(int $id): array
    {
        $this->sessionRequest('GET', '/api/tokens');
        foreach ($this->jsonResponse()['tokens'] as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        self::fail("Connection $id is not listed");
    }

    private function tokenIdOf(string $name): int
    {
        $this->in($this->kb->a);

        return (int) $this->em->getRepository(ApiToken::class)->findOneBy(['name' => $name])?->getId();
    }
}
