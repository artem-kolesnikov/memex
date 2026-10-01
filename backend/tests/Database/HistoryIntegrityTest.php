<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\EditProposal;
use App\Entity\Note;
use App\Entity\NoteRevision;
use App\Service\LineDiff;
use App\Service\NoteRevisions;
use App\Service\NoteWriter;

final class HistoryIntegrityTest extends ApiTestCase
{
    public function testNewSnapshotsAreIndependentOfCurrentBody(): void
    {
        $body = str_repeat("Long unchanged paragraph.\n", 80).'original';
        $note = $this->kb->a->note('Title', $body);
        self::getContainer()->get(NoteWriter::class)->update($note, null, str_replace('original', 'edited', $body), null, null);
        $stored = $this->em->getConnection()->fetchAssociative('SELECT body_md, body_diff FROM note_revisions WHERE note_id = ?', [$note->getId()]);
        self::assertSame($body, $stored['body_md']);
        self::assertNull($stored['body_diff']);
    }

    public function testAStaleRevisionListCannotApplyItsDeltaToANewerBase(): void
    {
        $note = $this->kb->a->note('Title', "a1\nb0");
        $revision = NoteRevision::capture($note, Note::ACTOR_HUMAN);
        $this->em->persist($revision);
        $this->em->flush();
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE note_revisions SET body_md = NULL, body_diff = ? WHERE id = ?', [json_encode(LineDiff::diff("a1\nb0", "a0\nb0")), $revision->getId()]);
        $this->em->clear();
        $service = self::getContainer()->get(NoteRevisions::class);
        $stale = $service->forNote($note->getId());
        $note = $this->em->find(Note::class, $note->getId());
        self::getContainer()->get(NoteWriter::class)->update($note, null, "a1\nb1", null, null);
        self::assertSame("a0\nb0", $service->bodiesFor($note->getId(), $stale)[$revision->getId()]);
    }

    public function testHashedDeltaRefusesAnEqualLineCountWrongBase(): void
    {
        $prefix = str_repeat("Unchanged runbook paragraph.\n", 80);
        $original = $prefix."a0\nb0";
        $successor = $prefix."a1\nb0";
        $note = $this->kb->a->note('Title', $original);
        $revision = NoteRevision::capture($note, Note::ACTOR_HUMAN);
        $revision->compressAgainst($successor);
        self::assertNull($revision->getBodyMd(), 'The fixture must exercise a stored delta.');
        self::assertSame(hash('sha256', $successor), $revision->getBodyDiffSourceHash());
        $this->em->persist($revision);
        $this->em->flush();
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE notes SET body_md = ? WHERE id = ?', [$successor, $note->getId()]);
        $service = self::getContainer()->get(NoteRevisions::class);
        self::assertSame($original, $service->bodyFor($revision));
        $conn->executeStatement('UPDATE notes SET body_md = ? WHERE id = ?', [$prefix."a1\nb1", $note->getId()]);
        self::assertNull($service->bodyFor($revision));
    }

    public function testRevisionInsertFailureRollsBackTheNoteAndLinks(): void
    {
        $note = $this->kb->a->note('Title', 'original');
        $conn = $this->em->getConnection();
        $conn->executeStatement("CREATE TRIGGER fail_history BEFORE INSERT ON note_revisions BEGIN SELECT RAISE(ABORT, 'injected history failure'); END");
        try {
            self::getContainer()->get(NoteWriter::class)->update($note, null, 'edited', null, null);
            self::fail('Expected injected failure');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('injected history failure', $e->getMessage());
        }
        self::assertSame('original', $conn->fetchOne('SELECT body_md FROM notes WHERE id = ?', [$note->getId()]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM note_revisions WHERE note_id = ?', [$note->getId()]));
    }

    public function testFailedOwnerSaveDoesNotDiscardHeldDraft(): void
    {
        $note = $this->kb->a->note('Title', 'original');
        $draft = $this->proposal($note, 'HELD-MARKER', true);
        $conn = $this->em->getConnection();
        $conn->executeStatement("CREATE TRIGGER fail_owner BEFORE INSERT ON note_revisions BEGIN SELECT RAISE(ABORT, 'injected owner history failure'); END");
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$note->getId(), [
            'body_md' => 'owner edited', 'discard_proposals' => true, 'expected_version' => $note->getVersion(),
        ]);
        self::assertSame(500, $this->httpStatus());
        $this->in($this->kb->a);
        self::assertSame('held', $conn->fetchOne('SELECT status FROM edit_proposals WHERE id = ?', [$draft->getId()]));
        self::assertSame('original', $conn->fetchOne('SELECT body_md FROM notes WHERE id = ?', [$note->getId()]));
    }

    public function testRestoreHoldsTheSameTeamLockAsConcurrentErasure(): void
    {
        $note = $this->kb->a->note('Title', 'original');
        self::getContainer()->get(NoteWriter::class)->update($note, null, 'edited', null, null);
        $conn = $this->em->getConnection();
        $revisionId = (int) $conn->fetchOne('SELECT id FROM note_revisions WHERE note_id = ?', [$note->getId()]);
        $number = $note->getId();
        $this->loginAs($this->kb->a);
        $listener = new class(fn (): bool => !\App\Tests\Support\SqlObservation::isWriteLocked($this->kb->a->vaultPath())) {
            public ?bool $competingEraseCouldLock = null;
            public int $reads = 0;
            public function __construct(private \Closure $couldBeginWriting) {}
            public function postLoad(\Doctrine\Persistence\Event\LifecycleEventArgs $event): void
            {
                if (!$event->getObject() instanceof NoteRevision || ++$this->reads !== 2) {
                    return;
                }
                $this->competingEraseCouldLock = ($this->couldBeginWriting)();
            }
        };
        $this->em->getEventManager()->addEventListener([\Doctrine\ORM\Events::postLoad], $listener);
        $this->em->clear();
        try {
            self::assertTrue(!\App\Tests\Support\SqlObservation::isWriteLocked($this->kb->a->vaultPath()), 'Precondition: the probe sees an idle vault');
            $this->sessionRequest('POST', '/api/notes/'.$number.'/revisions/'.$revisionId.'/restore');
            self::assertSame(200, $this->httpStatus(), $this->body());
            self::assertSame(false, $listener->competingEraseCouldLock, 'Erasure must wait while a revision is read and restored.');
            $this->in($this->kb->a);
            self::assertSame('original', $this->em->getConnection()->fetchOne('SELECT body_md FROM notes WHERE id = ?', [$note->getId()]));
        } finally {
            $this->em->getEventManager()->removeEventListener([\Doctrine\ORM\Events::postLoad], $listener);
        }
    }

    private function proposal(Note $note, string $marker, bool $held = false): EditProposal
    {
        $proposal = new EditProposal($note, null, $marker, $marker, [$marker], $marker);
        if (!$held) {
            $proposal->markApplied($marker, $marker, [$marker], $marker);
        }
        $this->em->persist($proposal);
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE edit_proposals SET proposed_summary = ?, proposed_patch = ?, change_title = ? WHERE id = ?', [$marker, json_encode([['find' => $marker, 'replace' => $marker]]), $marker, $proposal->getId()]);
        return $proposal;
    }

    public function testForgetErasesRetainedPayloadsButKeepsAuditIdentityAndOtherTeam(): void
    {
        $marker = 'SYNTHETIC-ERASURE-MARKER';
        $note = $this->kb->a->note('Safe title', 'safe current body');
        $proposal = $this->proposal($note, $marker);
        $other = $this->proposal($this->kb->b->note('Other', 'safe'), $marker);
        $this->loginAs($this->kb->a);
        $this->sessionRequest('DELETE', '/api/notes/'.$note->getId().'/revisions');
        self::assertSame(200, $this->httpStatus(), $this->body());
        $this->in($this->kb->a);
        $conn = $this->em->getConnection();
        $row = $conn->fetchAssociative('SELECT * FROM edit_proposals WHERE id = ?', [$proposal->getId()]);
        self::assertIsArray($row);
        self::assertSame('applied', $row['status']);
        self::assertStringNotContainsString($marker, json_encode($row));
        $this->in($this->kb->b);
        self::assertStringContainsString($marker, json_encode($this->em->getConnection()->fetchAssociative('SELECT * FROM edit_proposals WHERE id = ?', [$other->getId()])));
    }

    public function testForgetErasesRelatedActivityTextOnly(): void
    {
        $marker = 'SYNTHETIC-NARRATIVE-MARKER';
        $note = $this->kb->a->note('Safe', 'safe');
        $proposal = $this->proposal($note, $marker);
        $other = $this->proposal($this->kb->a->note('Other', 'other'), 'KEEP-OTHER-PAYLOAD');
        $entry = (new \App\Entity\CuratorLogEntry('curator', 'edit', $marker))
            ->withNote($note, $marker)->withProposal($proposal)->withOperatorVerdict($marker);
        $unrelated = new \App\Entity\CuratorLogEntry('curator', 'observation', 'KEEP-UNRELATED');
        $this->em->persist($entry);
        $this->em->persist($unrelated);
        $this->em->flush();
        $this->loginAs($this->kb->a);
        $this->sessionRequest('DELETE', '/api/notes/'.$note->getId().'/revisions');
        self::assertSame(200, $this->httpStatus(), $this->body());
        $this->sessionRequest('GET', '/api/curator-log');
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertStringNotContainsString($marker, $this->body());
        $this->in($this->kb->a);
        $conn = $this->em->getConnection();
        self::assertSame('KEEP-UNRELATED', $conn->fetchOne('SELECT description FROM curator_log WHERE id = ?', [$unrelated->getId()]));
        self::assertSame('KEEP-OTHER-PAYLOAD', $conn->fetchOne('SELECT proposed_body_md FROM edit_proposals WHERE id = ?', [$other->getId()]));
    }

    public function testForgetErasesActivityLinkedOnlyThroughAffectedNotes(): void
    {
        $marker = 'SYNTHETIC-AFFECTED-MARKER';
        $note = $this->kb->a->note('Safe', 'safe');
        $entry = (new \App\Entity\CuratorLogEntry('curator', 'tag_remove', $marker))
            ->withAffectedNotes([$note->getId()])->withOperatorVerdict($marker);
        $this->em->persist($entry);
        $this->em->flush();
        self::getContainer()->get(NoteRevisions::class)->forget($note->getId());
        $row = $this->em->getConnection()->fetchAssociative('SELECT description, operator_comment FROM curator_log WHERE id = ?', [$entry->getId()]);
        self::assertIsArray($row);
        self::assertStringNotContainsString($marker, json_encode($row));
    }

    public function testForgetRefusesHeldPayloadsWithoutDestroyingAnything(): void
    {
        $note = $this->kb->a->note('Title', 'original');
        self::getContainer()->get(NoteWriter::class)->update($note, null, 'safe', null, null);
        $this->proposal($note, 'HELD-MARKER', true);
        $this->loginAs($this->kb->a);
        $this->sessionRequest('DELETE', '/api/notes/'.$note->getId().'/revisions');
        self::assertSame(409, $this->httpStatus(), $this->body());
        $this->in($this->kb->a);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM note_revisions WHERE note_id = ?', [$note->getId()]));
    }

    public function testErasureFailureRollsBackRevisionDeletion(): void
    {
        $note = $this->kb->a->note('Title', 'original');
        self::getContainer()->get(NoteWriter::class)->update($note, null, 'safe', null, null);
        $this->proposal($note, 'MARKER');
        $conn = $this->em->getConnection();
        $conn->executeStatement("CREATE TRIGGER fail_erase BEFORE UPDATE ON edit_proposals BEGIN SELECT RAISE(ABORT, 'injected erasure failure'); END");
        try {
            self::getContainer()->get(NoteRevisions::class)->forget($note->getId());
            self::fail('Expected injected failure');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('injected erasure failure', $e->getMessage());
        }
        self::assertSame(1, (int) $conn->fetchOne('SELECT count(*) FROM note_revisions WHERE note_id = ?', [$note->getId()]));
    }
}
