<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Storage\DataDir;
use Doctrine\DBAL\Connection;

/**
 * The owner deleting a note somebody has in review.
 *
 * The note is restorable for 30 days; the draft held against it is not, so a
 * deletion the owner reverses still costs an agent everything it wrote. The
 * save already refuses once and names what is waiting (2026-09-08) and the
 * delete did not — it retired the note and let the foreign key take the rest.
 */
final class OwnerDeleteOverDraftTest extends ApiTestCase
{
    private function fileDraft(int $noteId, string $body): void
    {
        $this->request('PUT', '/api/notes/'.$noteId, $this->kb->a->agentBearer, [
            'body_md' => $body,
            'comment' => 'What I think it should say.',
        ]);
        self::assertSame(202, $this->httpStatus(), $this->body());
    }

    public function testTheFirstDeleteIsRefusedAndSaysWhatIsWaiting(): void
    {
        $note = $this->kb->a->note('Title', 'Original body.');
        $id = (int) $note->getId();
        $this->fileDraft($id, 'The agent’s rewrite.');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('DELETE', '/api/notes/'.$id);

        self::assertSame(409, $this->httpStatus(), $this->body());
        $conflict = $this->jsonResponse();
        self::assertSame('pending_proposals', $conflict['conflict']);
        self::assertCount(1, $conflict['pending_proposals']);
        self::assertSame('The agent’s rewrite.', $conflict['pending_proposals'][0]['proposed_body_md']);

        $this->sessionRequest('GET', '/api/notes/'.$id);
        self::assertSame(200, $this->httpStatus(), 'A refused delete must leave the note alone');
    }

    public function testDeletingWithTheAnswerRetiresTheNoteAndSaysWhatWentWithIt(): void
    {
        $note = $this->kb->a->note('Title', 'Original body.');
        $id = (int) $note->getId();
        // Read before the delete: the journal names the note by its row key,
        // and after retirement there is no row left to resolve it from.
        $rowId = (int) $note->getId();
        $this->fileDraft($id, 'The agent’s rewrite.');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('DELETE', '/api/notes/'.$id, ['discard_proposals' => true]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('GET', '/api/notes/'.$id);
        self::assertSame(404, $this->httpStatus(), 'The note is retired');

        $this->in($this->kb->a);
        $discards = $this->em->getConnection()->fetchFirstColumn(
            'SELECT description FROM curator_log
              WHERE (note_id = :id OR retired_note_id = :id) AND description LIKE :like',
            ['id' => $rowId, 'like' => '%discarded the held%']
        );
        self::assertCount(1, $discards, 'The journal has to say what the deletion took');
        self::assertStringContainsString('agent', $discards[0]);
    }

    public function testDeleteLocksBeforeCheckingAndKeepsTheLockThroughRetirement(): void
    {
        $this->client->catchExceptions(false);
        $note = $this->kb->a->note('Delete race', 'Original body.');
        $number = $note->getId();
        $vault = static::getContainer()->get(DataDir::class)->vaultPath($this->kb->a->vault->key);
        $this->loginAs($this->kb->a);
        $reads = 0;
        $snapshots = 0;
        $readTransaction = null;
        $retireTransaction = null;
        \App\Tests\Support\SqlObservation::during($this->em->getConnection(), function (string $sql, array $params, ?int $transaction) use ($vault, &$reads, &$snapshots, &$readTransaction, &$retireTransaction): void {
            if (str_starts_with($sql, 'SELECT') && str_contains($sql, 'FROM edit_proposals')) {
                ++$reads;
                self::assertTrue(\App\Tests\Support\SqlObservation::isWriteLocked($vault), 'The draft lookup must own the note lock');
                $readTransaction ??= $transaction;
            }
            if (str_starts_with($sql, 'INSERT INTO deleted_notes')) {
                ++$snapshots;
                self::assertTrue(\App\Tests\Support\SqlObservation::isWriteLocked($vault));
                $retireTransaction = $transaction;
            }
        }, function () use ($number): void {
            $this->sessionRequest('DELETE', '/api/notes/'.$number);
            self::assertSame(200, $this->httpStatus(), $this->body());
        });
        self::assertGreaterThanOrEqual(1, $reads);
        self::assertSame(1, $snapshots);
        self::assertNotNull($retireTransaction);
        self::assertSame($readTransaction, $retireTransaction);
    }

    public function testDeletingAMergeKeeperRequiresAcknowledgingItsDraft(): void
    {
        $source = $this->kb->a->note('Source');
        $keeper = $this->kb->a->note('Keeper');
        $this->request('POST', '/api/notes/'.$source->getId().'/propose-merge', $this->kb->a->agentBearer, [
            'into_note_id' => $keeper->getId(), 'merged_body_md' => 'Combined text.',
        ]);
        self::assertSame(202, $this->httpStatus(), $this->body());
        $this->loginAs($this->kb->a);
        $this->sessionRequest('DELETE', '/api/notes/'.$keeper->getId());
        self::assertSame(409, $this->httpStatus(), $this->body());
        self::assertCount(1, $this->jsonResponse()['pending_proposals']);
    }

    public function testANoteWithNothingHeldStillDeletesInOneStep(): void
    {
        $note = $this->kb->a->note('Nothing waiting', 'Original body.');
        $id = (int) $note->getId();

        $this->loginAs($this->kb->a);
        $this->sessionRequest('DELETE', '/api/notes/'.$id);

        self::assertSame(200, $this->httpStatus(), $this->body());
    }

    public function testAnAgentsDeleteIsStillHeldForReviewRatherThanRefused(): void
    {
        // The guard is the OWNER's step. A token reaching this route proposes
        // a deletion, and its own held edit is superseded by that proposal —
        // answering 409 there would break the review gate's own path.
        $note = $this->kb->a->note('Title', 'Original body.');
        $id = (int) $note->getId();
        $this->fileDraft($id, 'The agent’s rewrite.');

        $this->request('DELETE', '/api/notes/'.$id, $this->kb->a->agentBearer, ['comment' => 'Stale.']);

        self::assertSame(202, $this->httpStatus(), $this->body());
    }
}
