<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Note;
use App\Entity\NoteRevision;
use App\Entity\Tag;
use PHPUnit\Framework\TestCase;

/**
 * What a revision captures, and — the part that matters — when it decides a
 * note did not actually change.
 *
 * Unit tests in the strict sense: no kernel, no database. What they cannot
 * reach is the twenty-deep prune and the purge cascade, which are SQL against a
 * live schema and belong in `backend/tests/Database/`.
 *
 * `differsFrom` is worth this much attention because it is load-bearing in a
 * way that is easy to miss: the window is bounded at twenty, so every revision
 * recorded for a no-op edit *evicts a real one*. `NoteWriter::update()` is
 * reached with all-null fields by a proposal that only moved tags, and by a
 * merge that only re-synced links. Get this wrong and a fortnight of history
 * becomes twenty copies of the present, silently, exactly when it is needed.
 */
final class NoteRevisionTest extends TestCase
{
    private function note(string $title = 'Original', string $body = 'The body', array $tags = []): Note
    {
        $note = new Note(null, $title, $body, Note::SOURCE_MANUAL, null, Note::STATUS_VERIFIED);
        foreach ($tags as $name) {
            $note->addTag(new Tag($name));
        }

        return $note;
    }

    public function testCapturesTheStateAsItStands(): void
    {
        $note = $this->note('Title', 'Body', ['infra', 'memex']);

        $revision = NoteRevision::capture($note, Note::ACTOR_CURATOR);

        self::assertSame('Title', $revision->getTitle());
        self::assertSame('Body', $revision->getBodyMd());
        self::assertSame(['infra', 'memex'], $revision->getTags());
        self::assertSame(Note::ACTOR_CURATOR, $revision->getReplacedBy());
        // The age of the content, not the age of the row — the two answer
        // different questions and a restore reports the first.
        self::assertSame($note->getUpdatedAt(), $revision->getNoteUpdatedAt());
    }

    /**
     * The snapshot is taken before the mutation, so it must keep looking at the
     * old values after the caller has changed them. A capture that held a
     * reference rather than the values would compare the note to itself and
     * decide nothing ever changes.
     */
    public function testTheSnapshotIsNotAliveToLaterMutation(): void
    {
        $note = $this->note('Before', 'Old body');
        $revision = NoteRevision::capture($note, Note::ACTOR_HUMAN);

        $note->setTitle('After');
        $note->setBodyMd('New body');

        self::assertSame('Before', $revision->getTitle());
        self::assertSame('Old body', $revision->getBodyMd());
        self::assertTrue($revision->differsFrom($note));
    }

    public function testAnUntouchedNoteDoesNotDiffer(): void
    {
        $note = $this->note('Same', 'Same body', ['one', 'two']);
        $revision = NoteRevision::capture($note, Note::ACTOR_HUMAN);

        self::assertFalse($revision->differsFrom($note), 'nothing changed, so nothing is worth keeping');
    }

    public function testABodyChangeDiffers(): void
    {
        $note = $this->note('T', 'first');
        $revision = NoteRevision::capture($note, Note::ACTOR_CURATOR);
        $note->setBodyMd('second');

        self::assertTrue($revision->differsFrom($note));
    }

    public function testATitleChangeDiffers(): void
    {
        $note = $this->note('first', 'B');
        $revision = NoteRevision::capture($note, Note::ACTOR_CURATOR);
        $note->setTitle('second');

        self::assertTrue($revision->differsFrom($note));
    }

    public function testATagChangeDiffers(): void
    {
        $note = $this->note('T', 'B', ['infra']);
        $revision = NoteRevision::capture($note, Note::ACTOR_CURATOR);
        $note->addTag(new Tag('memex'));

        self::assertTrue($revision->differsFrom($note), 'retagging is an edit somebody may want back');
    }

    public function testLosingATagDiffers(): void
    {
        $note = $this->note('T', 'B', ['infra', 'memex']);
        $revision = NoteRevision::capture($note, Note::ACTOR_CURATOR);
        $note->clearTags();
        $note->addTag(new Tag('infra'));

        self::assertTrue($revision->differsFrom($note));
    }

    /**
     * The case that would fill the window with noise. Tag order is not
     * meaningful — `clearTags()` + re-add is how `NoteWriter::update()` applies
     * a full replacement list, and it can easily hand back the same set in a
     * different order. Comparing unsorted would call every such write a change.
     */
    public function testReorderedTagsAreNotAChange(): void
    {
        $note = $this->note('T', 'B', ['alpha', 'beta', 'gamma']);
        $revision = NoteRevision::capture($note, Note::ACTOR_CURATOR);

        $note->clearTags();
        foreach (['gamma', 'alpha', 'beta'] as $name) {
            $note->addTag(new Tag($name));
        }

        self::assertFalse($revision->differsFrom($note), 'the same tags in another order is not an edit');
    }

    /**
     * An empty body is a real state, and `Note::getBodyMd()` types it as a
     * string rather than null — so the comparison must not treat '' as absent.
     */
    public function testEmptyingTheBodyDiffers(): void
    {
        $note = $this->note('T', 'something');
        $revision = NoteRevision::capture($note, Note::ACTOR_CURATOR);
        $note->setBodyMd('');

        self::assertTrue($revision->differsFrom($note), 'deleting a body is the edit most worth undoing');
    }
}
