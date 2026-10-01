<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\EditProposal;
use App\Service\ReviewKinds;
use PHPUnit\Framework\TestCase;

/**
 * Classifying held work, so the inbox can be filtered before it is decided in
 * bulk (2026-08-19).
 *
 * This is a safety property, not a convenience. Approving sixty held items
 * without reading them is a decision the operator is entitled to make; doing it
 * to a MIXED pile is how a deletion goes through hidden among summaries. The
 * filter is what makes the bulk decision an informed one, so a misclassified
 * item is the failure that matters here — particularly one that lands in a
 * gentler bucket than it deserves.
 */
final class ReviewKindsTest extends TestCase
{
    public function testDestructiveWorkIsNeverClassifiedAsAnythingElse(): void
    {
        // Even carrying a body, which a merge does: `merged_body_md` is stored
        // in the same column an edit's replacement body uses. Reading the
        // fields without the type would call this an ordinary content edit and
        // sweep it into a bulk approval of edits.
        self::assertSame([ReviewKinds::DELETION], ReviewKinds::of(EditProposal::TYPE_DELETE));
        self::assertSame([ReviewKinds::MERGE], ReviewKinds::of(EditProposal::TYPE_MERGE, null, 'the merged body'));
    }

    public function testAnEnrichmentIsBothOfTheThingsItTouches(): void
    {
        // One label would hide it from one of the two filters that should find
        // it, and the operator filtering to "tags" would never see it.
        self::assertSame(
            [ReviewKinds::SUMMARY, ReviewKinds::TAGS],
            ReviewKinds::of(EditProposal::TYPE_EDIT, null, null, ['infra'], 'A description.')
        );
    }

    public function testASummaryAloneIsASummary(): void
    {
        self::assertSame(
            [ReviewKinds::SUMMARY],
            ReviewKinds::of(EditProposal::TYPE_EDIT, null, null, null, 'A description.')
        );
    }

    public function testATitleChangeCountsAsContentNotAsFiling(): void
    {
        // A title is what the note SAYS, and it is a link target besides.
        // Grouping it with tags would put renames in the bucket the operator
        // skims.
        self::assertSame([ReviewKinds::CONTENT], ReviewKinds::of(EditProposal::TYPE_EDIT, 'New title'));
        self::assertSame([ReviewKinds::CONTENT], ReviewKinds::of(EditProposal::TYPE_EDIT, null, 'new body'));
    }

    public function testAnEditThatTouchesEverythingCarriesContent(): void
    {
        $kinds = ReviewKinds::of(EditProposal::TYPE_EDIT, 'T', 'B', ['x'], 'S');

        self::assertContains(ReviewKinds::CONTENT, $kinds, 'or a rewrite hides from the content filter');
        self::assertContains(ReviewKinds::SUMMARY, $kinds);
        self::assertContains(ReviewKinds::TAGS, $kinds);
    }

    public function testAnItemIsNeverUnclassifiable(): void
    {
        // A row no filter reaches is a row no bulk action can touch and no
        // filtered view can show — it would sit in the inbox looking like a
        // glitch. Nothing can file such an edit today; this is the guard for
        // when something can.
        self::assertSame([ReviewKinds::CONTENT], ReviewKinds::of(EditProposal::TYPE_EDIT));
    }

    public function testEveryKindTheClassifierProducesIsOfferedAsAFilter(): void
    {
        $produced = [
            ReviewKinds::of(EditProposal::TYPE_DELETE),
            ReviewKinds::of(EditProposal::TYPE_MERGE),
            ReviewKinds::of(EditProposal::TYPE_CREATE),
            ReviewKinds::of(EditProposal::TYPE_EDIT, 'T', 'B', ['x'], 'S'),
        ];
        foreach (array_merge(...$produced) as $kind) {
            self::assertContains($kind, ReviewKinds::all(),
                'a kind the classifier emits but the filter never offers is a bucket nobody can select');
        }
    }
}
