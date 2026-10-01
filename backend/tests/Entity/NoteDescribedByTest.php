<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Note;
use PHPUnit\Framework\TestCase;

/**
 * Who wrote the description (2026-08-20).
 *
 * memex's promise is that it runs no AI of its own by default: the user's own
 * assistant describes what it saves, and the server only fills a blank when
 * nothing else did. The note page states which of the two happened, and a
 * reader has no way to check it beyond believing the field — so the field has
 * to be right in the awkward cases, not just the happy one.
 *
 * The case that matters most is the one below about clearing: a note whose
 * description is gone but which still names a describer would tell the reader
 * that an assistant wrote something the note does not contain.
 */
final class NoteDescribedByTest extends TestCase
{
    private static function note(): Note
    {
        return new Note(null, 'A note', 'Body.', Note::SOURCE_MANUAL, null, Note::STATUS_VERIFIED);
    }

    public function testANoteStartsWithNoDescriptionAndNoDescriber(): void
    {
        $note = self::note();

        self::assertNull($note->getSummary());
        self::assertNull($note->getSummaryBy());
    }

    public function testTheDescriberIsKeptBesideTheDescription(): void
    {
        $note = self::note();
        $note->setSummary('What this note is about.', 'oauth: Claude');

        self::assertSame('What this note is about.', $note->getSummary());
        self::assertSame('oauth: Claude', $note->getSummaryBy());
    }

    public function testClearingTheDescriptionClearsWhoWroteIt(): void
    {
        // The web editor clears a summary to ask for a fresh one, and every
        // enrichment path can null it. A note left claiming a describer it no
        // longer has would attribute text that is not there — the exact
        // failure this column exists to prevent.
        $note = self::note();
        $note->setSummary('Written by an assistant.', 'oauth: Claude');
        $note->setSummary(null);

        self::assertNull($note->getSummary());
        self::assertNull($note->getSummaryBy(), 'a note with no description cannot have a describer');
    }

    public function testMemexIsRecordedLikeAnyOtherAuthor(): void
    {
        // Not a special case in the storage, only in what it means: this is the
        // one write where the server described the note itself.
        $note = self::note();
        $note->setSummary('Generated server-side.', Note::SUMMARY_BY_MEMEX);

        self::assertSame('memex', $note->getSummaryBy());
    }

    public function testAnUnattributedDescriptionStaysUnattributedRatherThanGuessing(): void
    {
        // Notes described before this column existed are NULL, and nothing
        // backfills them. A caller that omits the author gets the same
        // treatment: the page then shows "unrecorded" instead of naming
        // somebody who may not have done it.
        $note = self::note();
        $note->setSummary('From somewhere.');

        self::assertNull($note->getSummaryBy());
    }

    public function testALongDescriberNameIsCutToWhatTheColumnHolds(): void
    {
        // Token names are user-chosen and the column is VARCHAR(120); an
        // over-long one must not fail the whole write at flush time.
        $note = self::note();
        $note->setSummary('x', str_repeat('n', 400));

        self::assertSame(120, mb_strlen((string) $note->getSummaryBy()));
    }
}
