<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;
use App\Storage\DataDir;
use Doctrine\DBAL\Connection;

final class ReviewSnapshotTest extends ApiTestCase
{
    public function testMalformedBatchItemFailsClearlyAndDoesNotStopAFreshApproval(): void
    {
        [, $proposal, $seen] = $this->draft();
        $this->sessionRequest('POST', '/api/inbox/batch', ['action' => 'approve', 'items' => [
            'invalid',
            null,
            ['kind' => ['proposal'], 'id' => $proposal],
            ['kind' => 'proposal', 'id' => $proposal] + $seen,
        ]]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $response = $this->jsonResponse();
        self::assertSame(1, $response['done']);
        self::assertCount(3, $response['failed']);
        foreach ($response['failed'] as $failure) {
            self::assertSame('Unknown item kind "" (proposal|note)', $failure['error']);
        }
    }

    public function testARevisedDraftCannotBeApprovedFromAnOlderCard(): void
    {
        [$note, $proposal, $seen] = $this->draft();
        $this->request('PUT', '/api/notes/'.$note, $this->kb->b->agentBearer, ['body_md' => 'Unseen second draft']);
        self::assertSame(202, $this->httpStatus(), $this->body());
        $this->loginAs($this->kb->b);
        $this->sessionRequest('POST', '/api/proposals/'.$proposal.'/approve', $seen);
        self::assertSame(409, $this->httpStatus(), $this->body());
        $this->in($this->kb->b);
        self::assertSame('Original', $this->findNumbered($note)->getBodyMd());
        self::assertNotNull($this->findProposalNumbered($proposal));
    }

    public function testChangedBaseRefusesAnAmendmentWithoutConsumingTheDraft(): void
    {
        [$note, $proposal, $seen] = $this->draft();
        $this->in($this->kb->b);
        $this->em->getConnection()->executeStatement('UPDATE notes SET version = version + 1, body_md = :body WHERE id = :id', ['body' => 'New base', 'id' => $note]);
        $this->em->clear();
        $this->sessionRequest('POST', '/api/proposals/'.$proposal.'/approve', $seen + ['body_md' => 'My amendment']);
        self::assertSame(409, $this->httpStatus(), $this->body());
        $this->in($this->kb->b);
        self::assertSame('New base', $this->findNumbered($note)->getBodyMd());
        self::assertSame('First draft', $this->findProposalNumbered($proposal)->getProposedBodyMd());
    }

    public function testFreshSnapshotCanBeApprovedAndMissingSnapshotIsRefused(): void
    {
        [$note, $proposal, $seen] = $this->draft();
        $this->sessionRequest('POST', '/api/proposals/'.$proposal.'/approve');
        self::assertSame(400, $this->httpStatus(), $this->body());
        $this->sessionRequest('POST', '/api/proposals/'.$proposal.'/approve', $seen);
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame('First draft', $this->jsonResponse()['note']['body_md']);
    }

    public function testPendingNoteApprovalRequiresTheDisplayedVersion(): void
    {
        $this->request('POST', '/api/notes', $this->kb->b->agentBearer, ['title' => 'Pending', 'body_md' => 'Seen']);
        self::assertSame(201, $this->httpStatus(), $this->body());
        $note = $this->jsonResponse()['note'];
        $this->loginAs($this->kb->b);
        $this->sessionRequest('PUT', '/api/notes/'.$note['id'], ['body_md' => 'Changed', 'expected_version' => $note['version']]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $fresh = $this->jsonResponse()['note'];
        $this->sessionRequest('POST', '/api/notes/'.$note['id'].'/approve', ['expected_version' => $note['version']]);
        self::assertSame(409, $this->httpStatus(), $this->body());
        $this->sessionRequest('POST', '/api/notes/'.$note['id'].'/approve');
        self::assertSame(400, $this->httpStatus(), $this->body());
        $this->sessionRequest('POST', '/api/notes/'.$note['id'].'/approve', ['expected_version' => $fresh['version']]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame(Note::STATUS_VERIFIED, $this->jsonResponse()['note']['status']);
    }

    public function testBatchRefusesStaleAndMissingSnapshotsButContinuesWithFreshItems(): void
    {
        [$note, $proposal, $seen] = $this->draft();
        $this->request('PUT', '/api/notes/'.$note, $this->kb->b->agentBearer, ['body_md' => 'Second']);
        $this->loginAs($this->kb->b);
        $this->sessionRequest('GET', '/api/proposals/'.$proposal);
        $fresh = $this->snapshot($this->jsonResponse());
        $this->sessionRequest('POST', '/api/inbox/batch', ['action' => 'approve', 'items' => [
            ['kind' => 'proposal', 'id' => $proposal] + $seen,
            ['kind' => 'proposal', 'id' => $proposal],
            ['kind' => 'proposal', 'id' => $proposal] + $fresh,
        ]]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame(1, $this->jsonResponse()['done']);
        self::assertCount(2, $this->jsonResponse()['failed']);
        self::assertStringContainsString('revised', $this->jsonResponse()['failed'][0]['error']);
        self::assertStringContainsString('expected_revision', $this->jsonResponse()['failed'][1]['error']);
    }

    private function draft(): array
    {
        $note = $this->kb->b->note('Review snapshot', 'Original');
        $number = $note->getId();
        $this->request('PUT', '/api/notes/'.$number, $this->kb->b->agentBearer, ['body_md' => 'First draft']);
        self::assertSame(202, $this->httpStatus(), $this->body());
        $proposal = $this->jsonResponse()['proposal']['id'];
        $this->loginAs($this->kb->b);
        $this->sessionRequest('GET', '/api/proposals/'.$proposal);
        return [$number, $proposal, $this->snapshot($this->jsonResponse())];
    }

    private function snapshot(array $proposal): array
    {
        self::assertIsInt($proposal['revision']);
        self::assertIsInt($proposal['note']['version']);
        return ['expected_revision' => $proposal['revision'], 'expected_version' => $proposal['note']['version']];
    }

    public function testMergeRequiresBothDisplayedNoteVersions(): void
    {
        $source = $this->kb->b->note('Duplicate', 'Source');
        $keeper = $this->kb->b->note('Keeper', 'Keeper body');
        $this->request('POST', '/api/notes/'.$source->getId().'/propose-merge', $this->kb->b->agentBearer, ['into_note_id' => $keeper->getId()]);
        self::assertSame(202, $this->httpStatus(), $this->body());
        $proposal = $this->jsonResponse()['proposal']['id'];
        $this->loginAs($this->kb->b);
        $this->sessionRequest('GET', '/api/proposals/'.$proposal);
        $data = $this->jsonResponse();
        $seen = $this->snapshot($data);
        $this->sessionRequest('POST', '/api/proposals/'.$proposal.'/approve', $seen);
        self::assertSame(400, $this->httpStatus(), $this->body());
        $seen['expected_merge_version'] = $data['merge_into']['version'];
        $this->in($this->kb->b);
        $this->em->getConnection()->executeStatement('UPDATE notes SET version = version + 1 WHERE id = :id', ['id' => $keeper->getId()]);
        $this->em->clear();
        $this->sessionRequest('POST', '/api/proposals/'.$proposal.'/approve', $seen);
        self::assertSame(409, $this->httpStatus(), $this->body());
        $this->in($this->kb->b);
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM notes'));
    }

    public function testPendingNoteBatchRefusesAStaleVersionAndAcceptsTheDisplayedOne(): void
    {
        $this->request('POST', '/api/notes', $this->kb->b->agentBearer, ['title' => 'Batch pending', 'body_md' => 'Body']);
        $note = $this->jsonResponse()['note'];
        $this->loginAs($this->kb->b);
        $this->sessionRequest('GET', '/api/notes?status=pending&per_page=100');
        self::assertSame(200, $this->httpStatus(), $this->body());
        $listed = $this->jsonResponse()['items'][0];
        self::assertSame($note['id'], $listed['id']);
        self::assertIsInt($listed['version']);
        self::assertSame($note['version'], $listed['version']);
        $this->sessionRequest('POST', '/api/inbox/batch', ['action' => 'approve', 'items' => [
            ['kind' => 'note', 'id' => $note['id'], 'expected_version' => $note['version'] + 1],
        ]]);
        self::assertSame(0, $this->jsonResponse()['done']);
        $this->sessionRequest('POST', '/api/inbox/batch', ['action' => 'approve', 'items' => [
            ['kind' => 'note', 'id' => $note['id'], 'expected_version' => $note['version']],
        ]]);
        self::assertSame(1, $this->jsonResponse()['done']);
    }

    public function testTagsOnlyChangesInvalidateTheDisplayedReviewVersion(): void
    {
        [$number, $proposal, $seen] = $this->draft();
        $this->in($this->kb->b);
        $note = $this->findNumbered($number);
        self::getContainer()->get(\App\Service\NoteWriter::class)->update($note, null, null, ['new-tag'], null);
        $this->sessionRequest('POST', '/api/proposals/'.$proposal.'/approve', $seen);
        self::assertSame(409, $this->httpStatus(), $this->body());
        self::assertGreaterThan($seen['expected_version'], $note->getVersion());
    }

    public function testOwnerDraftDiscoveryHoldsTheTeamLockUntilTheSave(): void
    {
        $note = $this->kb->b->note('Locked discovery', 'Before');
        $this->loginAs($this->kb->b);
        $vault = static::getContainer()->get(DataDir::class)->vaultPath($this->kb->b->vault->key);
        $reads = 0;
        \App\Tests\Support\SqlObservation::during($this->em->getConnection(), function (string $sql) use ($vault, &$reads): void {
            if (str_starts_with($sql, 'SELECT') && str_contains($sql, 'FROM edit_proposals')) {
                ++$reads;
                self::assertTrue(\App\Tests\Support\SqlObservation::isWriteLocked($vault));
            }
        }, function () use ($note): void {
            $this->sessionRequest('PUT', '/api/notes/'.$note->getId(), ['body_md' => 'After', 'expected_version' => $note->getVersion()]);
            self::assertSame(200, $this->httpStatus(), $this->body());
        });
        self::assertGreaterThan(0, $reads);
    }
}
