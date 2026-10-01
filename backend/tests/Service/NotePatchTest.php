<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\NotePatch;
use App\Service\NotePatchException;
use PHPUnit\Framework\TestCase;

/**
 * Anchored edits, away from the database.
 *
 * Everything here is about REFUSING, because applying a replacement is the
 * easy half. The whole safety of this feature rests on "the anchor must appear
 * exactly once" — it is what makes a patch checkable when it is filed and
 * checkable again, independently, when it is approved.
 */
class NotePatchTest extends TestCase
{
    public function testAnAnchorIsReplacedWhereItAppears(): void
    {
        $body = "# Title\n\nMicrosoft is not live until he registers the app.\n\nMore text.";

        $result = NotePatch::apply($body, [
            ['find' => 'Microsoft is not live until he registers the app.', 'replace' => 'Microsoft went live on 2026-08-21.'],
        ]);

        self::assertSame("# Title\n\nMicrosoft went live on 2026-08-21.\n\nMore text.", $result);
    }

    public function testOperationsApplyInOrderAndEachSeesTheLast(): void
    {
        $result = NotePatch::apply('one two three', [
            ['find' => 'one', 'replace' => 'ONE'],
            // Anchors on text the first operation produced, which only works
            // if they are applied in sequence rather than against the original.
            ['find' => 'ONE two', 'replace' => 'ONE and TWO'],
        ]);

        self::assertSame('ONE and TWO three', $result);
    }

    public function testAnEmptyReplacementDeletesTheText(): void
    {
        self::assertSame('a c', NotePatch::apply('a b c', [['find' => 'b ', 'replace' => '']]));
    }

    /**
     * The failure that says "the note is not what you think it is". Usually
     * because somebody edited it after the patch was written, which is exactly
     * the case this whole design exists to notice.
     */
    public function testAnAnchorThatIsNotThereIsRefused(): void
    {
        $this->expectException(NotePatchException::class);
        $this->expectExceptionMessageMatches('/not in the note as it now stands/');

        NotePatch::apply('the body', [['find' => 'some other text', 'replace' => 'x']]);
    }

    /**
     * The failure that says "you have not told me which one". Silently taking
     * the first would edit a sentence the author never looked at.
     */
    public function testAnAmbiguousAnchorIsRefusedWithItsCount(): void
    {
        $this->expectException(NotePatchException::class);
        $this->expectExceptionMessageMatches('/appears 3 times/');

        NotePatch::apply('live. live. live.', [['find' => 'live.', 'replace' => 'gone.']]);
    }

    /** Nothing is applied unless everything fits — no half-patched note. */
    public function testAFailingOperationLeavesTheEarlierOnesUnapplied(): void
    {
        $body = 'alpha bravo';

        try {
            NotePatch::apply($body, [
                ['find' => 'alpha', 'replace' => 'ALPHA'],
                ['find' => 'charlie', 'replace' => 'CHARLIE'],
            ]);
            self::fail('Expected the second operation to be refused');
        } catch (NotePatchException) {
            // The caller keeps the original because apply() returns a new
            // string and never writes: the guarantee is that it either hands
            // back a complete body or throws.
            self::assertSame('alpha bravo', $body);
        }
    }

    public function testAPatchThatWouldEmptyTheNoteIsRefused(): void
    {
        $this->expectException(NotePatchException::class);
        $this->expectExceptionMessageMatches('/propose_delete/');

        NotePatch::apply('all of it', [['find' => 'all of it', 'replace' => '']]);
    }

    public function testAnEmptyAnchorIsRefusedBecauseItMatchesEverywhere(): void
    {
        $this->expectException(NotePatchException::class);
        $this->expectExceptionMessageMatches('/non-empty string/');

        NotePatch::parse([['find' => '', 'replace' => 'x']]);
    }

    public function testAnOperationThatChangesNothingIsRefused(): void
    {
        $this->expectException(NotePatchException::class);
        $this->expectExceptionMessageMatches('/would change nothing/');

        NotePatch::parse([['find' => 'same', 'replace' => 'same']]);
    }

    public function testAMissingReplaceIsRefusedRatherThanTreatedAsDeletion(): void
    {
        $this->expectException(NotePatchException::class);
        $this->expectExceptionMessageMatches('/`replace` must be a string/');

        NotePatch::parse([['find' => 'x']]);
    }

    public function testTheErrorQuotesTheAnchorBackButNotTheWholeNote(): void
    {
        $long = str_repeat('x', 500);

        try {
            NotePatch::apply('a note with private contents', [['find' => $long, 'replace' => 'y']]);
            self::fail('Expected a refusal');
        } catch (NotePatchException $e) {
            self::assertStringContainsString('…', $e->getMessage(), 'A long anchor is cut short');
            self::assertLessThan(300, mb_strlen($e->getMessage()));
            self::assertStringNotContainsString('private contents', $e->getMessage());
        }
    }

    public function testParseAcceptsAWellFormedPatch(): void
    {
        self::assertSame(
            [['find' => 'a', 'replace' => 'b']],
            NotePatch::parse([['find' => 'a', 'replace' => 'b']])
        );
    }

    /**
     * MCP clients cache the tool schema at connector creation, so a client that
     * connected before `patch` existed sends it stringified — it does not know
     * the parameter is an array. The caller did nothing wrong, and refusing
     * them would read as the feature being broken. Found the first time this
     * feature was used, by the session that built it.
     */
    public function testAStringifiedPatchFromAClientWithAStaleSchemaIsAccepted(): void
    {
        self::assertSame(
            [['find' => 'a', 'replace' => 'b']],
            NotePatch::parse('[{"find":"a","replace":"b"}]')
        );
    }

    public function testAStringThatIsNotAPatchStillFails(): void
    {
        $this->expectException(NotePatchException::class);
        $this->expectExceptionMessageMatches('/non-empty list/');

        NotePatch::parse('find this and replace it please');
    }

    public function testAnEmptyPatchIsNotAnEdit(): void
    {
        $this->expectException(NotePatchException::class);

        NotePatch::parse([]);
    }
}
