<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\StorageLimits;

final class NoteBodyLimitApiTest extends ApiTestCase
{
    private function limit(int $bytes): void
    {
        static::getContainer()->get(StorageLimits::class)->update(['max_note_bytes' => $bytes]);
    }

    private function assertLimit(int $observed): void
    {
        self::assertSame(413, $this->httpStatus(), $this->body());
        $payload = $this->jsonResponse();
        self::assertSame('note_bytes', $payload['dimension']);
        self::assertSame($observed, $payload['observed']);
        self::assertSame(10, $payload['limit']);
        self::assertNotEmpty($payload['action']);
    }

    public function testRestCreationRefusesBeforeNotesTagsAndProviders(): void
    {
        $this->limit(10);
        $this->loginAs($this->kb->a);
        $this->ml->calls = [];
        $this->sessionRequest('POST', '/api/notes', ['title' => 'Oversized', 'body_md' => str_repeat('é', 6), 'tags' => ['new-tag']]);
        $this->assertLimit(12);
        $this->in($this->kb->a);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM notes'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM tags'));
        self::assertSame([], $this->ml->calls);
    }

    public function testAnUploadNamesTheOversizedFileAndSavesTheOthers(): void
    {
        $this->limit(10);
        $this->loginAs($this->kb->a);
        $files = [];
        foreach (['Fits.md' => 'short', 'Big.md' => str_repeat('x', 11), 'Also fits.md' => 'tiny'] as $name => $body) {
            $path = tempnam(sys_get_temp_dir(), 'upload-limit-');
            file_put_contents($path, $body);
            $files[] = new \Symfony\Component\HttpFoundation\File\UploadedFile($path, $name, 'text/markdown', test: true);
        }
        $this->client->request('POST', '/api/upload', files: ['files' => $files]);
        self::assertSame(201, $this->httpStatus(), $this->body());
        self::assertSame(['Fits', 'Also fits'], array_column($this->jsonResponse()['created'], 'title'));
        self::assertSame('Big.md', $this->jsonResponse()['errors'][0]['file']);
        self::assertStringContainsString('note_bytes', $this->jsonResponse()['errors'][0]['error']);
        $this->in($this->kb->a);
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM notes'));
    }

    public function testMcpProposeReturnsAStructuredError(): void
    {
        $note = $this->kb->a->note('Note', 'short');
        $this->limit(10);
        $this->request('POST', '/mcp', $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'propose', 'arguments' => ['note_id' => $note->getId(), 'body_md' => str_repeat('x', 11)]],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $result = $this->jsonResponse()['result'];
        self::assertTrue($result['isError']);
        $payload = json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('note_bytes', $payload['dimension']);
        self::assertSame(11, $payload['observed']);
        self::assertSame(10, $payload['limit']);
        self::assertNotEmpty($payload['action']);
        $this->in($this->kb->a);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM edit_proposals'));
    }

    public function testApprovalAfterAReductionKeepsTheDraftAndCanRecover(): void
    {
        $note = $this->kb->a->note('Note', 'short');
        $number = $note->getId();
        $this->request('PUT', '/api/notes/'.$number, $this->kb->a->agentBearer, ['body_md' => str_repeat('x', 11)]);
        self::assertSame(202, $this->httpStatus(), $this->body());
        $id = $this->jsonResponse()['proposal']['id'];
        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/proposals/'.$id);
        $seen = $this->jsonResponse();
        $snapshot = ['expected_revision' => $seen['revision'], 'expected_version' => $seen['note']['version']];
        $this->limit(10);
        $this->sessionRequest('POST', '/api/proposals/'.$id.'/approve', $snapshot);
        $this->assertLimit(11);
        $this->in($this->kb->a);
        $db = $this->em->getConnection();
        self::assertSame('short', $db->fetchOne('SELECT body_md FROM notes WHERE id = ?', [$note->getId()]));
        self::assertSame('held', $db->fetchOne('SELECT status FROM edit_proposals WHERE id = ?', [$id]));
        self::assertNull($db->fetchOne('SELECT applied_at FROM edit_proposals WHERE id = ?', [$id]));
        self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM note_revisions'));
        $this->limit(20);
        $this->sessionRequest('POST', '/api/proposals/'.$id.'/approve', $snapshot);
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame(str_repeat('x', 11), $this->jsonResponse()['note']['body_md']);
    }

    public function testRevisionRestoreRefusesGrowthWithoutNewHistory(): void
    {
        $note = $this->kb->a->note('Note', str_repeat('x', 20));
        $number = $note->getId();
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$number, ['body_md' => 'short']);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $this->sessionRequest('GET', '/api/notes/'.$number.'/revisions');
        $revision = $this->jsonResponse()['revisions'][0]['id'];
        $this->in($this->kb->a);
        $db = $this->em->getConnection();
        $count = (int) $db->fetchOne('SELECT count(*) FROM note_revisions');
        $this->limit(10);
        $this->sessionRequest('POST', '/api/notes/'.$number.'/revisions/'.$revision.'/restore');
        $this->assertLimit(20);
        $this->in($this->kb->a);
        self::assertSame($count, (int) $db->fetchOne('SELECT count(*) FROM note_revisions'));
        self::assertSame('short', $db->fetchOne('SELECT body_md FROM notes WHERE id = ?', [$note->getId()]));
    }
}
