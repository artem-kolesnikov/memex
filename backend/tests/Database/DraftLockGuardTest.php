<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\NoteWriter;
use App\Storage\DataDir;
use App\Storage\VaultContext;
use App\Tests\Support\PhpSource;
use App\Service\ReviewVerdicts;
use App\Tests\Support\KbFixture;
use App\Tests\Support\Tenant;
use Doctrine\DBAL\Connection;

final class DraftLockGuardTest extends DatabaseTestCase
{
    public function testApprovalLocksTheNoteBeforeClaimingTheProposal(): void
    {
        $writer = self::getContainer()->get(NoteWriter::class);
        $kb = new KbFixture(self::getContainer());
        $note = $kb->note($writer, 'Approval order', 'Original.');
        $proposal = $writer->propose($note, $kb->agentToken, null, 'Approved.', null, applyTags: false);
        $vault = $this->vaultPath();
        $checked = null;
        $claims = 0;
        \App\Tests\Support\SqlObservation::during($this->em->getConnection(), function (string $sql, array $params, ?int $transaction) use ($vault, $note, &$checked, &$claims): void {
            if (str_starts_with($sql, 'SELECT id FROM notes WHERE id') && \in_array($note->getId(), $params, true)) {
                $checked = $transaction;
            }
            if (str_starts_with($sql, 'UPDATE edit_proposals SET applied_at')) {
                ++$claims;
                self::assertTrue(\App\Tests\Support\SqlObservation::isWriteLocked($vault), 'Approval must lock the note before claiming its proposal');
                self::assertNotNull($transaction);
                self::assertSame($checked, $transaction, 'The note is found live in the transaction that claims');
            }
        }, fn () => self::getContainer()->get(ReviewVerdicts::class)->approveProposal($proposal, ['comment' => null, 'precedent' => false], $this->reviewSnapshot($proposal)));
        self::assertSame(1, $claims);
        self::assertSame('Approved.', $note->getBodyMd());
    }

    public function testMergeLocksBothNotesBeforeInsertingEitherDirection(): void
    {
        $writer = self::getContainer()->get(NoteWriter::class);
        $kb = new KbFixture(self::getContainer());
        $first = $kb->note($writer, 'First');
        $second = $kb->note($writer, 'Second');
        $vault = $this->vaultPath();
        $inserts = 0;
        \App\Tests\Support\SqlObservation::during($this->em->getConnection(), function (string $sql, array $params, ?int $transaction) use ($vault, &$inserts): void {
            if (str_starts_with($sql, 'INSERT INTO edit_proposals')) {
                ++$inserts;
                self::assertNotNull($transaction, 'Both notes must be checked in the transaction that inserts');
                self::assertTrue(\App\Tests\Support\SqlObservation::isWriteLocked($vault), 'Both notes must be locked before the FK check');
            }
        }, function () use ($writer, $first, $second, $kb): void {
            $writer->proposeMerge($first, $second, $kb->agentToken, null, null);
            $writer->proposeMerge($second, $first, $kb->agentToken, null, null);
        });
        self::assertSame(2, $inserts);
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM edit_proposals'));
    }

    public static function draftMutations(): array
    {
        return array_map(static fn (string $path): array => [$path], [
            'edit', 'comment', 'report', 'delete', 'merge', 'curator',
            'approve', 'reject', 'superseded', 'retire',
        ]);
    }

    /** @dataProvider draftMutations */
    public function testDraftMutationsTakeTheTeamLockBeforeRows(string $path): void
    {
        $other = new Tenant(self::getContainer(), 'Other');
        $writer = self::getContainer()->get(NoteWriter::class);
        $verdicts = self::getContainer()->get(ReviewVerdicts::class);
        $limbo = self::getContainer()->get(\App\Service\NoteLimbo::class);
        $kb = new KbFixture(self::getContainer());
        $note = $kb->note($writer, 'Lock order', 'Original.');
        $keeper = $kb->note($writer, 'Keeper');
        $proposal = $writer->propose($note, $kb->agentToken, null, 'Draft.', null, applyTags: false);
        $vault = $this->vaultPath();
        $otherVault = self::getContainer()->get(DataDir::class)->vaultPath($other->vault->key);
        $noteId = $note->getId();
        $checked = null;
        $mutations = 0;
        \App\Tests\Support\SqlObservation::during($this->em->getConnection(), function (string $sql, array $params, ?int $transaction) use ($vault, $otherVault, $noteId, &$checked, &$mutations): void {
            if ($mutations === 0 && str_starts_with($sql, 'SELECT id FROM notes WHERE id') && \in_array($noteId, $params, true)) {
                self::assertTrue(\App\Tests\Support\SqlObservation::isWriteLocked($vault), 'Take the vault lock before a note row');
                self::assertFalse(\App\Tests\Support\SqlObservation::isWriteLocked($otherVault), 'Other vaults must remain independent');
                $checked = $transaction;
            }
            if (preg_match('/^(INSERT INTO|UPDATE|DELETE FROM) edit_proposals\b/', $sql)) {
                ++$mutations;
                self::assertTrue(\App\Tests\Support\SqlObservation::isWriteLocked($vault), 'Draft mutation must hold the vault lock');
                self::assertNotNull($transaction);
                self::assertSame($checked, $transaction, 'Draft mutation must hold its note');
            }
        }, function () use ($path, $writer, $verdicts, $limbo, $kb, $note, $keeper, $proposal): void {
            match ($path) {
                'edit' => $writer->propose($note, $kb->agentToken, null, 'Revised.', null, applyTags: false),
                'comment' => $writer->propose($note, $kb->agentToken, null, null, null, 'New rationale.', applyTags: false),
                'report' => $writer->report($note, $kb->agentToken, 'Stale.'),
                'delete' => $writer->proposeDelete($note, $kb->agentToken, 'Obsolete.'),
                'merge' => $writer->proposeMerge($note, $keeper, $kb->agentToken, null, null),
                'curator' => $writer->propose($note, $kb->curatorToken, 'Curated title', null, null, applyTags: false),
                'approve' => $verdicts->approveProposal($proposal, ['comment' => null, 'precedent' => false], $this->reviewSnapshot($proposal)),
                'reject' => $verdicts->rejectProposal($proposal, ['comment' => null, 'precedent' => false]),
                'superseded' => $verdicts->rejectSuperseded($proposal),
                'retire' => $limbo->retire($note, 'operator'),
            };
        });
        self::assertGreaterThanOrEqual(1, $mutations, 'The selected path must really mutate a proposal');
    }

    /** Reading what this connection already has waiting, which is half of the race. */
    private const READS_THE_DRAFTS = ['heldDraft', 'heldDrafts', 'supersedeDraft'];

    private const LOCK = 'whileHoldingNote';

    public function testEveryMethodThatReadsAHeldDraftTakesTheNoteLock(): void
    {
        $file = \dirname(__DIR__, 2).'/src/Service/'.(new \ReflectionClass(NoteWriter::class))->getShortName().'.php';
        $unguarded = [];

        foreach (PhpSource::methodsIn($file) as $method) {
            if (\in_array($method['name'], self::READS_THE_DRAFTS, true)) {
                continue;
            }
            $reads = array_intersect(self::READS_THE_DRAFTS, $method['calls']);
            if ($reads !== [] && !\in_array(self::LOCK, $method['calls'], true)) {
                $unguarded[] = $method['name'].'() at line '.$method['line'].' calls '.implode(', ', $reads);
            }
        }

        self::assertSame(
            [],
            $unguarded,
            'These read a note\'s held drafts without '.self::LOCK.'(): '."\n".implode("\n", $unguarded),
        );
    }

    public function testTheGuardWouldNoticeAMethodThatSkippedTheLock(): void
    {
        // The guard's own failing case, because a source-reading test that
        // cannot be made to fail is the thing this project keeps shipping.
        $methods = PhpSource::parseMethods(<<<'PHP'
            <?php
            class Example
            {
                public function forgetful(): void
                {
                    $drafts = $this->heldDrafts($note, $token);
                    $this->em->flush();
                }
            }
            PHP);

        self::assertCount(1, $methods);
        self::assertContains('heldDrafts', $methods[0]['calls']);
        self::assertNotContains(self::LOCK, $methods[0]['calls']);
    }

    private function vaultPath(): string
    {
        return self::getContainer()->get(DataDir::class)->vaultPath(self::getContainer()->get(VaultContext::class)->current()->key);
    }
}
