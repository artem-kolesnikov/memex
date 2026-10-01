<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\CuratorLogEntry;
use App\Entity\Note;
use App\Service\NoteLimbo;
use App\Service\NoteWriter;
use App\Service\ReviewVerdicts;
use App\Tests\Support\KbFixture;

/**
 * The defects the 2026-08-21 multi-lens audit called release blockers, each
 * pinned by the case that reproduced it.
 *
 * They are together in one file on purpose. Every one of them was a guarantee
 * the code stated in a doc-comment and enforced nowhere — the audit's dominant
 * miss-pattern — so what these tests are really defending is the habit of
 * writing the assertion down next to the promise.
 */
final class AuditBlockerTest extends DatabaseTestCase
{
    private NoteWriter $writer;
    private NoteLimbo $limbo;
    private ReviewVerdicts $verdicts;
    private KbFixture $kb;

    private const COMMENTED = ['comment' => 'Agreed, it goes.', 'precedent' => false];

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = self::getContainer()->get(NoteWriter::class);
        $this->limbo = self::getContainer()->get(NoteLimbo::class);
        $this->verdicts = self::getContainer()->get(ReviewVerdicts::class);
        $this->kb = new KbFixture(self::getContainer());
    }

    // ---------------------------------------------------------------- R-1 --
    // A provenance URL is rendered as a clickable link in the note view. A
    // `javascript:` one there runs in the operator's session, on the click
    // that is supposed to VERIFY where an agent's note came from.

    /** @return array<string, array{string}> */
    public static function hostileSourceUrls(): array
    {
        return [
            'javascript scheme' => ['javascript:fetch("/api/tokens/1/role",{method:"PATCH"})'],
            'uppercased scheme' => ['JavaScript:alert(1)'],
            'whitespace-padded' => ["  javascript:alert(1)\t"],
            'data scheme' => ['data:text/html,<script>alert(1)</script>'],
            'vbscript scheme' => ['vbscript:msgbox(1)'],
            'file scheme' => ['file:///etc/passwd'],
            'not a url at all' => ['pick me'],
        ];
    }

    /** @dataProvider hostileSourceUrls */
    public function testANoteCannotBeBuiltWithASourceUrlThatIsNotHttp(string $hostile): void
    {
        // The constructor, not the controller: every note in the system is
        // built here, so this is the assertion that survives a new endpoint.
        $note = new Note(
            $this->kb->agentToken,
            'Planted',
            'Body.',
            Note::SOURCE_AGENT,
            $hostile,
            Note::STATUS_PENDING,
        );

        self::assertNull($note->getSourceUrl(), 'A '.$hostile.' provenance URL must not survive into a note');
    }

    public function testAnHttpSourceUrlIsKeptExactly(): void
    {
        $url = 'https://example.test/a/page?q=1#frag';
        $note = new Note(
            null,
            'Captured',
            'Body.',
            Note::SOURCE_SCRAPE,
            $url,
            Note::STATUS_VERIFIED,
        );

        self::assertSame($url, $note->getSourceUrl(), 'Real provenance must not be mangled by the guard');
    }

    public function testTheWriteThroughNoteWriterDropsAHostileSourceUrlToo(): void
    {
        $result = $this->writer->create(
            $this->kb->agentToken,
            'Planted',
            'Body.',
            Note::SOURCE_AGENT,
            'javascript:alert(1)',
            [],
            enrich: null,
            applyTags: false,
        );

        self::assertNull($result['note']->getSourceUrl());
    }

    // ---------------------------------------------------------------- H-1 --
    // The verdict row used to be built pointing at the note, then persisted
    // AFTER apply had already deleted it. Doctrine threw on the flush, the
    // operator got a 500 for a deletion that had committed, and the reasoning
    // they had just typed was lost.

    public function testApprovingACuratorFiledDeleteWithACommentSucceedsAndKeepsTheVerdict(): void
    {
        $note = $this->kb->note($this->writer, 'Wrong note', 'Body.');
        $id = $note->getId();
        $proposal = $this->writer->proposeDelete($note, $this->kb->curatorToken, 'Superseded.');

        $this->verdicts->approveProposal($proposal, self::COMMENTED, $this->reviewSnapshot($proposal));

        self::assertNull($this->em->getRepository(Note::class)->find($id), 'The delete still applies');
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM deleted_notes WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row, 'The note is retired, not destroyed');

        $entry = $this->latestLogEntry();
        self::assertNotNull($entry, 'The operator’s reasoning has to reach a row');
        self::assertSame('Agreed, it goes.', $entry->getOperatorComment());
        self::assertNull($entry->getNote(), 'A deleted note cannot be referenced by a row inserted after it went');
        self::assertSame('Wrong note', $entry->getNoteTitle(), 'The title copy is what survives');
    }

    public function testApprovingAnAgentFiledDeleteWithAnOperatorCommentSucceeds(): void
    {
        // The other half of the same trap: an agent-filed delete writes no log
        // row UNTIL the operator says something about it, which is exactly when
        // it broke.
        $note = $this->kb->note($this->writer, 'Agent’s target', 'Body.');
        $id = $note->getId();
        $proposal = $this->writer->proposeDelete($note, $this->kb->agentToken, 'Duplicate.');

        $this->verdicts->approveProposal($proposal, self::COMMENTED, $this->reviewSnapshot($proposal));

        self::assertNull($this->em->getRepository(Note::class)->find($id));
        self::assertSame('Agreed, it goes.', $this->latestLogEntry()?->getOperatorComment());
    }

    public function testApprovingACuratorFiledMergePointsTheVerdictAtTheSurvivingNote(): void
    {
        $absorb = $this->kb->note($this->writer, 'Duplicate', 'Same thing.');
        $keeper = $this->kb->note($this->writer, 'The keeper', 'Same thing, better.');
        $absorbId = $absorb->getId();
        $keeperId = $keeper->getId();
        $proposal = $this->writer->proposeMerge($absorb, $keeper, $this->kb->curatorToken, null, 'Same note twice.');

        $this->verdicts->approveProposal($proposal, self::COMMENTED, $this->reviewSnapshot($proposal));

        self::assertNull($this->em->getRepository(Note::class)->find($absorbId), 'The absorbed note is retired');
        self::assertNotNull($this->em->getRepository(Note::class)->find($keeperId), 'The keeper stays');

        $entry = $this->latestLogEntry();
        self::assertSame('Agreed, it goes.', $entry?->getOperatorComment());
        // Pointing at the keeper is what makes log_recent(note_id:) able to
        // find this verdict at all — the absorbed id is not a note any more.
        self::assertSame($keeperId, $entry?->getNote()?->getId());
    }

    public function testRejectingADeleteStillPointsTheVerdictAtTheNoteThatSurvives(): void
    {
        $note = $this->kb->note($this->writer, 'Keeps living', 'Body.');
        $id = $note->getId();
        $proposal = $this->writer->proposeDelete($note, $this->kb->curatorToken, 'Please remove.');

        $this->verdicts->rejectProposal($proposal, ['comment' => 'No — still cited.', 'precedent' => true]);

        self::assertNotNull($this->em->getRepository(Note::class)->find($id));
        self::assertSame($id, $this->latestLogEntry()?->getNote()?->getId(), 'A refused delete destroys nothing, so the reference is safe');
    }

    // ---------------------------------------------------------------- R-2 --
    // Purge is the one operation whose whole value is that nothing survives
    // it. Two things had to be true and were not.

    public function testPurgingAnIdThatIsALiveNoteLeavesItsHistoryAlone(): void
    {
        // The id is not in limbo — a mistyped id, a stale limbo list, or a
        // restore that just won the race. forget() used to run anyway and take
        // the live note's entire revision history with it, while the caller
        // was told 404, nothing happened.
        $note = $this->kb->note($this->writer, 'Live note', 'First body.');
        $id = $note->getId();
        $this->writer->update($note, null, 'Second body.', null, \App\Service\EmbeddingSpend::Metered, applyTags: false);
        $this->writer->update($note, null, 'Third body.', null, \App\Service\EmbeddingSpend::Metered, applyTags: false);
        $before = $this->revisionCount($id);
        self::assertGreaterThan(0, $before, 'Precondition: the note has history to lose');

        $purged = $this->limbo->purge($id);

        self::assertFalse($purged, 'Nothing was in limbo under that id');
        self::assertSame($before, $this->revisionCount($id), 'A live note’s history is not the purge’s to take');
        self::assertNotNull($this->em->getRepository(Note::class)->find($id));
    }

    public function testPurgingARealTombstoneStillDestroysEverything(): void
    {
        $note = $this->kb->note($this->writer, 'Leaked', 'A credential nobody should keep.');
        $id = $note->getId();
        $this->writer->update($note, null, 'Still the credential.', null, \App\Service\EmbeddingSpend::Metered, applyTags: false);
        $this->limbo->retire($note, Note::ACTOR_HUMAN, 'Contained a secret.');
        self::assertGreaterThan(0, $this->revisionCount($id), 'Precondition: history survives retirement by design');

        $purged = $this->limbo->purge($id);

        self::assertTrue($purged);
        self::assertSame(0, $this->revisionCount($id), 'Purge means nothing survives');
        $row = $this->em->getConnection()->fetchAssociative('SELECT body_md, summary, purged_at FROM deleted_notes WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row);
        self::assertNull($row['body_md']);
        self::assertNull($row['summary']);
        self::assertNotNull($row['purged_at'], 'The tombstone is permanent');
    }

    public function testAPurgedNoteCannotBeRestored(): void
    {
        $note = $this->kb->note($this->writer, 'Leaked', 'A credential nobody should keep.');
        $id = $note->getId();
        $this->limbo->retire($note, Note::ACTOR_HUMAN, 'Contained a secret.');
        $this->limbo->purge($id);

        self::assertNull($this->limbo->restore($id), 'Restore must not resurrect purged content');
        self::assertSame(
            1,
            (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM deleted_notes WHERE id = :id', ['id' => $id]),
            'A failed restore must not take the permanent tombstone with it'
        );
    }

    public function testPurgingAnAlreadyPurgedTombstoneStillLeavesNothingBehind(): void
    {
        // The unconditional forget() existed for this case, and it still has
        // to hold now that it is conditional on the id being a tombstone.
        $note = $this->kb->note($this->writer, 'Leaked', 'A credential.');
        $id = $note->getId();
        $this->limbo->retire($note, Note::ACTOR_HUMAN, null);
        $this->limbo->purge($id);

        self::assertFalse($this->limbo->purge($id), 'Already purged');
        self::assertSame(0, $this->revisionCount($id));
    }

    private function latestLogEntry(): ?CuratorLogEntry
    {
        return $this->em->getRepository(CuratorLogEntry::class)
            ->findOneBy([], ['id' => 'DESC']);
    }

    private function revisionCount(int $noteId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM note_revisions WHERE note_id = :id',
            ['id' => $noteId]
        );
    }
}
