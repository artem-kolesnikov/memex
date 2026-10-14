<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\NotePatch;
use App\Service\SurgicalEdit;
use PHPUnit\Framework\TestCase;

/**
 * A held edit narrowed to the words it changes, away from the database.
 *
 * The property everything rests on: the narrowed operations, applied to the
 * note the edit was written against, give back the agent's text to the
 * character. Narrowing may only change what happens when the note changed
 * while the edit waited.
 */
class SurgicalEditTest extends TestCase
{
    private const RECORD = "# Garden club\n\n"
        ."## Decided\n\n"
        ."- **2026-03-02, interface:** the team chose the compact layout over the spacious one, with a cool canvas and dense rows. "
        ."The reader moves to it in its own later stage. The concept and the tokens are in the design folder.\n"
        ."- Meetings are on the first Tuesday, in the library hall.\n"
        ."- Dues are collected in March.\n\n"
        ."## Open\n\n"
        ."- Where the spring sale is held.\n"
        ."- Who keeps the seed library.\n";

    public function testAWholeBodyThatAddsASentenceBecomesThatSentence(): void
    {
        $after = str_replace('in the design folder.', 'in the design folder. The palette lives in one tokens file.', self::RECORD);

        $operations = SurgicalEdit::narrow(self::RECORD, $after);

        self::assertNotNull($operations);
        self::assertCount(1, $operations);
        self::assertSame($after, NotePatch::apply(self::RECORD, $operations));
        self::assertStringNotContainsString('compact layout', $operations[0]['find'], 'The anchor quotes the end of the paragraph, not all of it');
        self::assertStringContainsString('The palette lives in one tokens file.', $operations[0]['replace']);
    }

    public function testAParagraphSizedPatchIsNarrowedToTheWordItChanges(): void
    {
        $paragraph = '- **2026-03-02, interface:** the team chose the compact layout over the spacious one, with a cool canvas and dense rows. '
            .'The reader moves to it in its own later stage. The concept and the tokens are in the design folder.';
        $after = NotePatch::apply(self::RECORD, [['find' => $paragraph, 'replace' => str_replace('cool canvas', 'warm canvas', $paragraph)]]);

        $operations = SurgicalEdit::narrow(self::RECORD, $after);

        self::assertNotNull($operations);
        self::assertCount(1, $operations);
        self::assertStringContainsString('warm', $operations[0]['replace']);
        self::assertStringNotContainsString('design folder', $operations[0]['find'], 'A change to one word does not claim the rest of its paragraph');
        self::assertSame($after, NotePatch::apply(self::RECORD, $operations));
    }

    public function testChangesInTwoPlacesBecomeTwoOperationsThatEachApplyOnTheirOwn(): void
    {
        $after = str_replace(['first Tuesday', 'seed library'], ['first Thursday', 'seed exchange'], self::RECORD);

        $operations = SurgicalEdit::narrow(self::RECORD, $after);

        self::assertNotNull($operations);
        self::assertCount(2, $operations);
        self::assertSame($after, NotePatch::apply(self::RECORD, $operations));
        // The point of narrowing: with the rest of the note changed meanwhile,
        // each still lands where it was aimed.
        $meanwhile = str_replace('Dues are collected in March.', 'Dues are collected in April.', self::RECORD);
        self::assertSame(
            str_replace('Dues are collected in March.', 'Dues are collected in April.', $after),
            NotePatch::apply($meanwhile, $operations),
        );
    }

    public function testEveryAnchorIsFoundExactlyOnceEvenWhereTheWordRepeats(): void
    {
        $before = "- Who brings the tea.\n- Who brings the cups.\n- Who brings the tea urn.\n";
        $after = "- Who brings the tea.\n- Who brings the plates.\n- Who brings the tea urn.\n";

        $operations = SurgicalEdit::narrow($before, $after);

        self::assertNotNull($operations);
        self::assertSame($after, NotePatch::apply($before, $operations), 'NotePatch refuses an anchor found twice, so this passing means every anchor was unique');
    }

    public function testARewriteIsStoredAsSent(): void
    {
        $after = "# Garden club\n\nEverything above was decided again at the March meeting; see the minutes.\n";

        self::assertNull(SurgicalEdit::narrow(self::RECORD, $after));
    }

    public function testAnUnchangedBodyHasNothingToNarrow(): void
    {
        self::assertNull(SurgicalEdit::narrow(self::RECORD, self::RECORD));
    }

    public function testParagraphBreaksAndWindowsLineEndingsAreRebuiltExactly(): void
    {
        $cases = [
            [self::RECORD, str_replace("- Dues are collected in March.\n", "- Dues are collected in March.\n\n", self::RECORD)],
            [str_replace("\n", "\r\n", self::RECORD), str_replace(["\n", 'Tuesday'], ["\r\n", 'Thursday'], self::RECORD)],
            [self::RECORD, str_replace('- Who keeps', '  - Who keeps', self::RECORD)],
            [self::RECORD, self::RECORD.'- A new question at the end.'],
        ];
        foreach ($cases as [$before, $after]) {
            $operations = SurgicalEdit::narrow($before, $after);
            if ($operations !== null) {
                self::assertSame($after, NotePatch::apply($before, $operations));
            }
        }
        self::assertNotNull(SurgicalEdit::narrow($cases[0][0], $cases[0][1]), 'An added blank line is narrowed, not stored as a whole body');
    }

    /** A long record changed at both ends, the shape a status update takes. */
    public function testALongNoteChangedAtBothEndsIsStillNarrowed(): void
    {
        $lines = [];
        for ($i = 0; $i < 700; $i++) {
            $lines[] = "- Item {$i}: the team recorded decision number {$i} about stage ".($i % 11).' and it holds. Another sentence follows for item '.$i.".\n";
        }
        $before = implode('', $lines);
        $after = str_replace(['decision number 3 ', 'decision number 690 '], ['decision No. 3 ', 'decision No. 690 '], $before);

        $started = microtime(true);
        $operations = SurgicalEdit::narrow($before, $after);

        self::assertNotNull($operations);
        self::assertCount(2, $operations);
        self::assertSame($after, NotePatch::apply($before, $operations));
        self::assertLessThan(2.0, microtime(true) - $started, 'Narrowing an 80,000-character note takes well under a second');
    }

    /**
     * Random edits to a real-shaped note: whatever narrowing returns must
     * rebuild the edit exactly. A wrong offset anywhere shows up here.
     */
    public function testRandomEditsAreAlwaysRebuiltExactly(): void
    {
        mt_srand(20261013);
        $words = preg_split('/(\s+)/', self::RECORD, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $narrowed = 0;
        for ($round = 0; $round < 300; $round++) {
            $edited = $words;
            for ($k = 0, $edits = mt_rand(1, 4); $k < $edits; $k++) {
                $at = mt_rand(0, count($edited) - 1);
                $edited[$at] = match (mt_rand(0, 3)) {
                    0 => 'changed',
                    1 => '',
                    2 => $edited[$at].' inserted words',
                    default => $edited[$at]."\n",
                };
            }
            $after = implode('', $edited);
            if (trim($after) === '') {
                continue;
            }
            $operations = SurgicalEdit::narrow(self::RECORD, $after);
            if ($operations === null) {
                continue;
            }
            $narrowed++;
            self::assertSame($after, NotePatch::apply(self::RECORD, $operations), 'round '.$round);
        }
        self::assertGreaterThan(200, $narrowed, 'Most small edits are narrowed rather than stored as sent');
    }
}
