<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\EditProposal;
use App\Service\ReviewVerdicts;

/**
 * `recent_changes` answers "what changed since then, and who changed it" for
 * every role, from the journal, which until 2026-10-17 only a curator
 * connection could read.
 */
final class McpRecentChangesTest extends ApiTestCase
{
    /** @return array<string, mixed> the decoded payload, or ['error' => text] */
    private function call(string $tool, array $arguments, ?string $bearer = null): array
    {
        $this->request('POST', '/mcp', $bearer ?? $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $result = $this->jsonResponse()['result'];
        $text = $result['content'][0]['text'];

        return ($result['isError'] ?? false) ? ['error' => $text] : json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    }

    private function decide(int $proposalId, bool $approve): void
    {
        $this->in($this->kb->a);
        $proposal = $this->em->find(EditProposal::class, $proposalId);
        $verdicts = self::getContainer()->get(ReviewVerdicts::class);
        $snapshot = ['expected_revision' => $proposal->getRevision(), 'expected_version' => $proposal->getNote()->getVersion()];
        $approve
            ? $verdicts->approveProposal($proposal, ['comment' => null, 'precedent' => false], $snapshot)
            : $verdicts->rejectProposal($proposal, ['comment' => null, 'precedent' => false], $snapshot);
    }

    public function testAnAgentSeesWhatChangedAndWhoChangedIt(): void
    {
        $note = $this->kb->a->note('Garden plan', "Dig the beds.\n");
        $this->call('propose', ['title' => 'Seed list', 'body_md' => 'Carrots.', 'summary' => 'What to sow.']);
        $held = $this->call('propose', ['note_id' => $note->getId(), 'patch' => [['append' => 'Water them.']]]);
        $this->decide((int) $held['proposal']['id'], true);
        $rejected = $this->call('propose', ['note_id' => $note->getId(), 'patch' => [['append' => 'Pave it over.']]]);
        $this->decide((int) $rejected['proposal']['id'], false);
        $report = $this->call('propose', ['note_id' => $note->getId(), 'comment' => 'The bed sizes are out of date.']);
        $this->decide((int) $report['proposal']['id'], true);

        $changes = $this->call('recent_changes', []);

        self::assertArrayNotHasKey('error', $changes, json_encode($changes, JSON_THROW_ON_ERROR));
        $actions = array_column($changes['changes'], 'action');
        self::assertSame(['approved', 'approved', 'create-proposed', 'create'], $actions, 'the acknowledged report, the approved edit, the filed note and the owner\'s note, newest first; nothing waiting or rejected');
        self::assertStringContainsString('acknowledged', $changes['changes'][0]['description']);
        self::assertStringContainsString('approved', $changes['changes'][1]['description']);
        self::assertSame($note->getId(), $changes['changes'][1]['note_id']);
        self::assertSame('Garden plan', $changes['changes'][1]['note_title']);
        self::assertSame(4, $changes['total']);
    }

    public function testSinceAndNoteNarrowTheChanges(): void
    {
        $first = $this->kb->a->note('First', 'Body.');
        $second = $this->kb->a->note('Second', 'Body.');
        $this->in($this->kb->a);
        $this->em->getConnection()->executeStatement(
            "UPDATE curator_log SET created_at = '2026-09-01 10:00:00' WHERE note_id = ?",
            [$first->getId()]
        );

        self::assertSame(['Second'], array_column($this->call('recent_changes', ['since' => '2026-10-01'])['changes'], 'note_title'));
        self::assertSame(['First'], array_column($this->call('recent_changes', ['since' => '2026-08-01', 'until' => '2026-10-01'])['changes'], 'note_title'));
        self::assertSame(['Second'], array_column($this->call('recent_changes', ['since' => '2026-08-01', 'note_id' => $second->getId()])['changes'], 'note_title'));
    }

    public function testANotesChangesIncludeTheImportThatBroughtItAndOutliveIt(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'recent-zip-');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
        $zip->addFromString('Pump manual.md', "Prime it first.\n");
        self::assertTrue($zip->close());
        $this->loginAs($this->kb->a);
        $this->client->request('POST', '/api/import', files: ['archive' => new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'vault.zip', 'application/zip', test: true)]);
        unlink($path);
        self::assertSame(201, $this->httpStatus(), $this->body());
        $id = (int) $this->jsonResponse()['notes'][0]['id'];

        $all = $this->call('recent_changes', []);
        self::assertSame('import', $all['changes'][0]['action']);
        self::assertSame([$id], $all['changes'][0]['note_ids'], 'a one-note import still names its note');

        self::assertSame(['import'], array_column($this->call('recent_changes', ['note_id' => $id])['changes'], 'action'));

        $this->in($this->kb->a);
        self::getContainer()->get(\App\Service\NoteLimbo::class)->retire($this->em->find(\App\Entity\Note::class, $id), 'operator');
        $after = $this->call('recent_changes', ['note_id' => $id]);
        self::assertArrayNotHasKey('error', $after, 'a deleted note\'s history is still asked for');
        self::assertSame(['delete', 'import'], array_column($after['changes'], 'action'));
    }

    public function testWithoutSinceItCoversTheLastSevenDays(): void
    {
        $old = $this->kb->a->note('Old', 'Body.');
        $this->kb->a->note('New', 'Body.');
        $this->in($this->kb->a);
        $this->em->getConnection()->executeStatement(
            'UPDATE curator_log SET created_at = :at WHERE note_id = :id',
            ['at' => (new \DateTimeImmutable('-8 days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), 'id' => $old->getId()]
        );

        $changes = $this->call('recent_changes', []);

        self::assertSame(['New'], array_column($changes['changes'], 'note_title'));
        self::assertStringStartsWith((new \DateTimeImmutable('-7 days', new \DateTimeZone('UTC')))->format('Y-m-d'), $changes['since']);
    }

    public function testAMomentThatIsNotOneIsRefused(): void
    {
        self::assertStringContainsString('since must be an ISO-8601 instant', $this->call('recent_changes', ['since' => 'yesterday'])['error'] ?? '');
        self::assertStringContainsString('since must be earlier than until', $this->call('recent_changes', ['since' => '2026-10-02', 'until' => '2026-10-01'])['error'] ?? '');
    }

    public function testEveryRoleIsServedIt(): void
    {
        foreach ([$this->kb->a->agentBearer, $this->kb->a->curatorBearer] as $bearer) {
            $this->request('POST', '/mcp', $bearer, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
            self::assertContains('recent_changes', array_column($this->jsonResponse()['result']['tools'], 'name'));
        }
    }
}
