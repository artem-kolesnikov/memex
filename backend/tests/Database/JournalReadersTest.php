<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * Who reads the journal, now that it records everyone: the owner on the
 * Activity page, and a curator bootstrapping a pass from `log_recent`.
 */
final class JournalReadersTest extends ApiTestCase
{
    private function tool(string $name, string $bearer, array $arguments = []): array
    {
        $this->request('POST', '/mcp', $bearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $result = $this->jsonResponse()['result'];
        self::assertNotTrue($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));

        return json_decode($result['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);
    }

    public function testTheOwnerIsShownByNameAndFilteredAsOneWriter(): void
    {
        $this->kb->a->note('Mine');
        $this->in($this->kb->a);
        $this->em->getConnection()->executeStatement(
            "INSERT INTO curator_log (token_name, actor, action, description, is_precedent, created_at)
             VALUES ('Owner A', 'human', 'tag-merged', 'Merged the tag “x” into “y” across 1 note(s).', 0, CURRENT_TIMESTAMP)",
        );
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/curator-log');
        $answer = $this->jsonResponse();
        self::assertSame(['Owner A'], array_values(array_unique(array_column($answer['entries'], 'by'))));
        self::assertSame(['human'], array_values(array_unique(array_column($answer['entries'], 'actor'))));
        self::assertSame([['value' => 'owner', 'label' => 'Owner A', 'count' => 2]], $answer['filters']['writers']);

        $this->sessionRequest('GET', '/api/curator-log?writer=owner');
        self::assertCount(2, $this->jsonResponse()['entries']);
    }

    public function testAConnectionsUploadKeepsARowPerNote(): void
    {
        $dir = sys_get_temp_dir().'/memex-upload-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/one.md', "# One\n\nFirst.");
        file_put_contents($dir.'/two.md', "# Two\n\nSecond.");
        $this->client->request('POST', '/api/upload', files: ['files' => [
            new \Symfony\Component\HttpFoundation\File\UploadedFile($dir.'/one.md', 'one.md', 'text/markdown', test: true),
            new \Symfony\Component\HttpFoundation\File\UploadedFile($dir.'/two.md', 'two.md', 'text/markdown', test: true),
        ]], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->kb->a->curatorBearer]);
        self::assertSame(201, $this->httpStatus(), $this->body());

        $this->in($this->kb->a);
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT action, note_id FROM curator_log ORDER BY id');
        self::assertSame(['create', 'create'], array_column($rows, 'action'));
        self::assertNotContains(null, array_column($rows, 'note_id'), 'Each note\'s history and cooldown read its own row');
    }

    public function testTheOwnersUploadIsOneRow(): void
    {
        $dir = sys_get_temp_dir().'/memex-upload-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/one.md', "# One\n\nFirst.");
        file_put_contents($dir.'/two.md', "# Two\n\nSecond.");
        $this->loginAs($this->kb->a);
        $this->client->request('POST', '/api/upload', files: ['files' => [
            new \Symfony\Component\HttpFoundation\File\UploadedFile($dir.'/one.md', 'one.md', 'text/markdown', test: true),
            new \Symfony\Component\HttpFoundation\File\UploadedFile($dir.'/two.md', 'two.md', 'text/markdown', test: true),
        ]]);
        self::assertSame(201, $this->httpStatus(), $this->body());

        $this->in($this->kb->a);
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT action, description FROM curator_log');
        self::assertSame([['action' => 'import', 'description' => 'Uploaded 2 notes']], $rows);
    }

    public function testChangingAConnectionsRoleIsLogged(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PATCH', '/api/tokens/'.$this->kb->a->agentTokenId.'/role', ['role' => 'curator']);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('GET', '/api/curator-log');
        $entries = $this->jsonResponse()['entries'];
        self::assertSame('connection-changed', $entries[0]['action']);
        self::assertSame('Made “agent-a” a curator: its edits apply without review', $entries[0]['description']);
    }

    public function testACuratorBootstrapsFromTheCurationRecordAndSeesANotesWholeHistory(): void
    {
        $note = $this->kb->a->note('Shared note');
        $this->request('PUT', '/api/notes/'.$note->getId(), $this->kb->a->agentBearer, ['title' => 'Shared note (renamed)']);
        self::assertSame(202, $this->httpStatus(), $this->body());
        $this->tool('log', $this->kb->a->curatorBearer, ['action' => 'observation', 'description' => 'Where the last pass stopped.']);

        $recent = $this->tool('log_recent', $this->kb->a->curatorBearer);
        self::assertSame(['observation'], array_column($recent['entries'], 'action'));

        $history = $this->tool('log_recent', $this->kb->a->curatorBearer, ['note_id' => $note->getId()]);
        self::assertSame(['edit-proposed', 'create'], array_column($history['entries'], 'action'));
        self::assertSame(['agent', 'human'], array_column($history['entries'], 'actor'));
    }
}
