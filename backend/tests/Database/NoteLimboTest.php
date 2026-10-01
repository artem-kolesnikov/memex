<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;
use App\Service\NoteLimbo;
use App\Service\NoteWriter;
use App\Tests\Support\KbFixture;
use App\Tests\Support\Tenant;

/**
 * Retire, restore, purge — the only place a note is destroyed, and the
 * mechanism the whole "deletion is reversible" guarantee rests on.
 */
final class NoteLimboTest extends DatabaseTestCase
{
    private NoteWriter $writer;
    private NoteLimbo $limbo;
    private KbFixture $kb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = self::getContainer()->get(NoteWriter::class);
        $this->limbo = self::getContainer()->get(NoteLimbo::class);
        $this->kb = new KbFixture(self::getContainer());
    }

    public function testRetiringMovesTheRowOutOfNotesAndIntoLimbo(): void
    {
        $note = $this->kb->note($this->writer, 'A note', 'Body worth keeping.', ['alpha'], summary: 'Described.');
        $id = $note->getId();

        $this->limbo->retire($note, Note::ACTOR_HUMAN, 'No longer true.');

        self::assertNull($this->em->getRepository(Note::class)->find($id), 'Deleted rows leave the notes table on purpose');
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM deleted_notes WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row);
        self::assertSame('A note', $row['title']);
        self::assertSame('Body worth keeping.', $row['body_md']);
        self::assertSame('Described.', $row['summary']);
        self::assertSame('No longer true.', $row['deleted_reason']);
        self::assertSame(Note::ACTOR_HUMAN, $row['deleted_by']);
        self::assertSame(['alpha'], json_decode((string) $row['tags'], true, 8, JSON_THROW_ON_ERROR));
    }

    public function testRetiringCascadesTheEmbeddingAway(): void
    {
        $note = $this->kb->note($this->writer, 'A note', 'Body.', []);
        $id = $note->getId();
        self::assertSame(1, $this->embeddingCount($id), 'Precondition: enrichment stored a vector');

        $this->limbo->retire($note, Note::ACTOR_HUMAN, null);

        self::assertSame(0, $this->embeddingCount($id));
    }

    public function testRestoreBringsTheNoteBackAtItsOriginalIdWithItsTags(): void
    {
        // The id is the contract: inbound links, log rows and anything the
        // operator bookmarked all name notes by id.
        $note = $this->kb->note($this->writer, 'A note', 'Body worth keeping.', ['alpha', 'beta'], summary: 'Described.');
        $id = $note->getId();
        $this->limbo->retire($note, Note::ACTOR_HUMAN, 'Mistake.');

        $restored = $this->limbo->restore($id);

        self::assertInstanceOf(Note::class, $restored);
        self::assertSame($id, $restored->getId());
        self::assertSame('A note', $restored->getTitle());
        self::assertSame('Body worth keeping.', $restored->getBodyMd());
        self::assertSame('Described.', $restored->getSummary());
        self::assertSame(['alpha', 'beta'], $this->tagNames($restored));
        self::assertSame(0, $this->limboCount($id), 'A restored note leaves limbo');
    }

    public function testARestoredNoteStillKnowsWhoWroteIt(): void
    {
        // `last_actor` — the KIND — was carried through limbo from the start,
        // and the two columns naming WHO were added later and not added to
        // NoteLimbo::CARRIED with them. So a restored note came back saying
        // "an assistant wrote this" with no assistant to name. A restore is
        // meant to be faithful, not an approximation authored by whoever
        // pressed restore.
        $note = $this->kb->note($this->writer, 'A note', 'Body.', []);
        $this->writer->update(
            $note,
            null,
            'Rewritten by the curator.',
            null,
            \App\Service\EmbeddingSpend::Metered,
            actor: Note::ACTOR_CURATOR,
            actorToken: $this->kb->curatorToken,
        );
        $id = $note->getId();
        $tokenId = $this->kb->curatorToken->getId();
        $this->limbo->retire($note, Note::ACTOR_CURATOR, 'Superseded.');

        $restored = $this->limbo->restore($id);

        self::assertInstanceOf(Note::class, $restored);
        self::assertSame(Note::ACTOR_CURATOR, $restored->getLastActor());
        self::assertSame($tokenId, $restored->getLastActorToken()?->getId(), 'The assistant survives a round trip through limbo');
    }

    public function testARestoredNoteAPersonWroteStillNamesThatPerson(): void
    {
        $note = $this->kb->note($this->writer, 'A note', 'Body.', []);
        $id = $note->getId();
        self::assertTrue($note->isLastWrittenByOwner(), 'Precondition: a person is recorded');
        $this->limbo->retire($note, Note::ACTOR_HUMAN, 'Mistake.');

        $restored = $this->limbo->restore($id);

        self::assertInstanceOf(Note::class, $restored);
        self::assertSame(Note::ACTOR_HUMAN, $restored->getLastActor());
        self::assertTrue($restored->isLastWrittenByOwner());
    }

    public function testRestoreRecreatesTagsGarbageCollectedWhileTheNoteSatInLimbo(): void
    {
        $note = $this->kb->note($this->writer, 'A note', 'Body.', ['orphan']);
        $id = $note->getId();

        $this->limbo->retire($note, Note::ACTOR_HUMAN, null);
        // What the delete paths do next: the tag now belongs to nothing.
        $this->writer->gcTags();
        self::assertSame(0, $this->tagCount('orphan'));

        $restored = $this->limbo->restore($id);

        self::assertInstanceOf(Note::class, $restored);
        self::assertSame(['orphan'], $this->tagNames($restored), 'A note must not come back partly untagged');
    }

    public function testRestoreLeavesTheSequenceAheadOfTheRestoredId(): void
    {
        // The restored row is re-inserted at its old id. If a note written
        // while it sat in limbo could take that id, the restore collides on
        // the primary key; ids are never reused.
        $note = $this->kb->note($this->writer, 'A note', 'Body.', []);
        $id = $note->getId();
        $this->limbo->retire($note, Note::ACTOR_HUMAN, null);
        $during = $this->kb->note($this->writer, 'Written while it was in limbo', 'Body.', []);
        self::assertGreaterThan($id, $during->getId());
        self::assertNotNull($this->limbo->restore($id));
        $this->kb->refresh();

        $next = $this->kb->note($this->writer, 'Written afterwards', 'Body.', []);

        self::assertGreaterThan($during->getId(), $next->getId());
    }

    public function testPurgeDropsTheContentAndKeepsTheTombstone(): void
    {
        $note = $this->kb->note($this->writer, 'A note', 'A leaked credential.', [], summary: 'Described.');
        $id = $note->getId();
        $this->limbo->retire($note, Note::ACTOR_HUMAN, 'Contained a secret.');

        self::assertTrue($this->limbo->purge($id));

        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM deleted_notes WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row, 'The tombstone stays forever');
        self::assertNull($row['body_md']);
        self::assertNull($row['summary']);
        self::assertNotNull($row['purged_at']);
        self::assertSame('A note', $row['title'], 'The title survives so a re-import can say what happened');
        // The REASON no longer survives an operator purge, and that changed
        // deliberately on 2026-08-21. It used to,
        // on the same "so a re-import can say what happened" argument as the
        // title — but the operator writes that sentence at the exact moment
        // they have decided something must not be retained, which makes it the
        // likeliest field in the row to name the secret. The title is short,
        // deliberate, and load-bearing for the tombstone's own purpose; the
        // reason is free prose and is not. Routine 30-day expiry still keeps
        // it — see testExpirySweepKeepsTheCircumstancesItIsNotAnEmergency.
        self::assertNull($row['deleted_reason'], 'An operator purge destroys the reasoning too');
    }

    public function testExpirySweepKeepsTheCircumstancesItIsNotAnEmergency(): void
    {
        // The other half of the distinction above. A note whose 30 days ran out
        // loses its content, but why it was deleted is curation history — and
        // it is what the import screen shows when the same title comes back in
        // an archive.
        $note = $this->kb->note($this->writer, 'A note', 'Body.', [], summary: 'Described.');
        $id = $note->getId();
        $this->limbo->retire($note, Note::ACTOR_HUMAN, 'Superseded by the newer write-up.');
        $this->em->getConnection()->executeStatement(
            'UPDATE deleted_notes SET purge_after = :past WHERE id = :id',
            ['past' => self::yesterday(), 'id' => $id]
        );

        self::assertSame(1, $this->limbo->purgeExpired());

        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM deleted_notes WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row);
        self::assertNull($row['body_md'], 'The content still goes');
        self::assertNull($row['summary']);
        self::assertSame('Superseded by the newer write-up.', $row['deleted_reason']);
    }

    public function testAPurgedNoteCannotBeRestored(): void
    {
        $note = $this->kb->note($this->writer, 'A note', 'Body.', []);
        $id = $note->getId();
        $this->limbo->retire($note, Note::ACTOR_HUMAN, null);
        $this->limbo->purge($id);

        self::assertNull($this->limbo->restore($id), 'A tombstone has no body to restore');
    }

    public function testTheSweepOnlyTakesNotesPastTheirLimboPeriod(): void
    {
        $fresh = $this->kb->note($this->writer, 'Retired today', 'Body.', []);
        $old = $this->kb->note($this->writer, 'Retired long ago', 'Body.', []);
        $oldId = $old->getId();
        $this->limbo->retire($fresh, Note::ACTOR_HUMAN, null);
        $this->limbo->retire($old, Note::ACTOR_HUMAN, null);
        $this->em->getConnection()->executeStatement(
            'UPDATE deleted_notes SET purge_after = :past WHERE id = :id',
            ['past' => self::yesterday(), 'id' => $oldId]
        );

        self::assertSame(1, $this->limbo->expiredCount());
        self::assertSame(1, $this->limbo->purgeExpired());
        self::assertSame(0, $this->limbo->expiredCount());
        self::assertNotNull(
            $this->em->getConnection()->fetchOne('SELECT body_md FROM deleted_notes WHERE id = :id', ['id' => $fresh->getId()]),
            'A note still inside its 30 days keeps its content'
        );
    }

    public function testRestoreRefusesANoteBelongingToAnotherTeam(): void
    {
        // Multi-tenancy is correctness: the id alone must never be enough.
        $note = $this->kb->note($this->writer, 'A note', 'Body.', []);
        $id = $note->getId();
        $this->limbo->retire($note, Note::ACTOR_HUMAN, null);

        (new Tenant(self::getContainer(), 'Other'))->enter();
        self::assertNull($this->limbo->restore($id));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM notes'));

        $this->kb->tenant->enter();
        self::assertSame(1, $this->limboCount($id), 'The refused restore left the row where it was');
    }

    private static function yesterday(): string
    {
        return (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');
    }

    private function embeddingCount(int $noteId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM note_embeddings WHERE note_id = :id',
            ['id' => $noteId]
        );
    }

    private function limboCount(int $noteId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM deleted_notes WHERE id = :id',
            ['id' => $noteId]
        );
    }

    private function tagCount(string $name): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM tags WHERE name = :name',
            ['name' => $name]
        );
    }

    /** @return string[] */
    private function tagNames(Note $note): array
    {
        $names = array_map(static fn ($tag) => $tag->getName(), $note->getTags()->toArray());
        sort($names);

        return $names;
    }
}
