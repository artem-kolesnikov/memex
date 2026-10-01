<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;
use App\Service\NoteWriter;
use App\Storage\DataDir;
use App\Storage\VaultContext;
use App\Storage\VaultDatabase;
use App\Tests\Support\KbFixture;
use Doctrine\ORM\OptimisticLockException;

/**
 * Two writers, one note.
 *
 * There was no concurrency control in this backend at all — no version column,
 * no locks, no atomic claims — so `NoteWriter::update()` was a read-modify-
 * write flushed as an UPDATE by id, and the second flush won. In the narrow
 * case the losing edit was recorded NOWHERE: both writers snapshotted the same
 * pre-edit state, so `note_revisions` held two identical copies of the body
 * before either edit and nothing at all of the one that lost. The revision
 * system exists so that no version of a note is ever lost, and this was the
 * case it could not see.
 *
 * The odds argument does not apply here the way it usually would. An unattended
 * agent writing at the same time as its operator is not an edge case in this
 * product; it is the product.
 */
final class ConcurrentEditTest extends DatabaseTestCase
{
    private NoteWriter $writer;
    private KbFixture $kb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = self::getContainer()->get(NoteWriter::class);
        $this->kb = new KbFixture(self::getContainer());
    }

    public function testAWriteBumpsTheVersion(): void
    {
        $note = $this->kb->note($this->writer, 'Title', 'First body.');
        $before = $note->getVersion();

        $this->writer->update($note, null, 'Second body.', null, \App\Service\EmbeddingSpend::Metered, applyTags: false);

        self::assertGreaterThan($before, $note->getVersion(), 'The version is what a later writer checks against');
    }

    public function testTheSecondOfTwoConcurrentWritersIsRefusedRatherThanWinningSilently(): void
    {
        $note = $this->kb->note($this->writer, 'Title', 'Original body.');
        $id = $note->getId();

        // The other writer commits while we hold this entity — a curator token
        // on the operator's scheduled pass, or the operator in another tab —
        // through its own connection to the vault file: our in-memory note
        // still believes the version it loaded.
        $this->commitElsewhere(
            'UPDATE notes SET version = version + 1, body_md = :body WHERE id = :id',
            ['body' => 'The curator’s rewrite.', 'id' => $id]
        );

        // Our UPDATE now carries WHERE version = <what we loaded>, matches no
        // row, and is refused. Before the version column it matched by id and
        // won.
        $this->expectException(OptimisticLockException::class);
        $this->writer->update($note, null, 'Our edit, based on what we last saw.', null, \App\Service\EmbeddingSpend::Metered, applyTags: false);
    }

    public function testARefusedWriteChangesNothing(): void
    {
        $note = $this->kb->note($this->writer, 'Title', 'Original body.');
        $id = $note->getId();
        $this->commitElsewhere(
            'UPDATE notes SET version = version + 1, body_md = :body WHERE id = :id',
            ['body' => 'Written by somebody else.', 'id' => $id]
        );

        try {
            $this->writer->update($note, null, 'Our doomed edit.', null, \App\Service\EmbeddingSpend::Metered, applyTags: false);
            self::fail('Expected the stale write to be refused');
        } catch (OptimisticLockException) {
            // Expected.
        }

        $this->em->clear();
        self::assertSame(
            'Written by somebody else.',
            $this->em->getConnection()->fetchOne('SELECT body_md FROM notes WHERE id = :id', ['id' => $id]),
            'A refused write must not have partly landed'
        );
    }

    public function testTheVersionSurvivesARoundTripThroughLimbo(): void
    {
        // Restore re-inserts the row behind the ORM's back. A note that came
        // back with no version, or with one Doctrine disagreed about, would
        // fail its next ordinary edit — a fix that broke restore would be worse
        // than the defect.
        $note = $this->kb->note($this->writer, 'Title', 'Body.');
        $id = $note->getId();
        $limbo = self::getContainer()->get(\App\Service\NoteLimbo::class);
        $limbo->retire($note, Note::ACTOR_HUMAN, null);

        $restored = $limbo->restore($id);

        self::assertNotNull($restored);
        self::assertGreaterThan(0, $restored->getVersion());
        $this->kb->refresh();
        $this->writer->update($restored, null, 'An ordinary edit afterwards.', null, \App\Service\EmbeddingSpend::Metered, applyTags: false);
        self::assertSame('An ordinary edit afterwards.', $restored->getBodyMd());
    }

    /**
     * A write another request commits: its own connection to this vault's
     * file, opened the way every connection memex opens is.
     *
     * @param array<string, int|string> $params
     */
    private function commitElsewhere(string $sql, array $params): void
    {
        $container = self::getContainer();
        $path = $container->get(DataDir::class)->vaultPath($container->get(VaultContext::class)->current()->key);
        $other = $container->get(VaultDatabase::class)->open($path);
        try {
            $statement = $other->prepare($sql);
            foreach ($params as $name => $value) {
                $statement->bindValue(':'.$name, $value);
            }
            $statement->execute();
        } finally {
            $other->close();
        }
    }
}
