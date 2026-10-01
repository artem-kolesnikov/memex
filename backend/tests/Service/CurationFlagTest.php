<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CuratorLogEntry;
use App\Service\CurationFlags;
use App\Service\CurationQueue;
use PHPUnit\Framework\TestCase;

/**
 * Operator flags (2026-08-17): the two decisions in the feature that are pure,
 * and both of them are places the operator's own words can go missing.
 *
 * Unit tests in the strict sense — no kernel, no database, like McpInboxTest.
 * The rest of the feature is a database property (the queue's ordering, the
 * cooldown bypass, the one-open-flag-per-note index) and is verified live
 * against prod, as every backend change in this project has been.
 *
 * What is checked here:
 *
 * 1. **A flag cannot be raised without a comment.** A bare "look at this" ranks
 *    a note and says nothing, which is the failure mode the whole channel
 *    exists to avoid: the curator would re-derive an opinion the operator
 *    already holds, and probably a different one.
 * 2. **The comment reaches the candidate row.** Everything else about a flag —
 *    the sort, the cooldown exemption — is worthless if the words do not travel
 *    with it, and this shaping is the last step before the curator reads them.
 */
final class CurationFlagTest extends TestCase
{
    public function testACommentIsRequiredAndTrimmed(): void
    {
        self::assertSame('The deploy section is stale', CurationFlags::normalizeComment("  The deploy section is stale \n"));
    }

    public function testWhitespaceOnlyIsAnEmptyBoxAndIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // Not "invalid input": the message has to tell the operator what the
        // box is for, because a flag without one is useless to the curator.
        $this->expectExceptionMessageMatches('/what is wrong with this note/');
        CurationFlags::normalizeComment("   \n\t ");
    }

    public function testAMissingCommentIsRefusedRatherThanStoredEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CurationFlags::normalizeComment(null);
    }

    public function testAnOverlongCommentIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CurationFlags::normalizeComment(str_repeat('a', 5001));
    }

    public function testAFlaggedCandidateCarriesTheOperatorsWords(): void
    {
        $candidate = CurationQueue::candidateRow([
            'id' => 42,
            'title' => 'Disaster Recovery',
            'status' => 'verified',
            'updated_at' => '2026-08-10 09:00:00',
            'last_at' => '2026-08-12 03:00:00',
            'defects' => 1,
            'flag_comment' => 'The restore steps name the old box. Fix from the runbook notes.',
            'flag_by' => 'Jane',
            'flag_at' => '2026-08-17 21:30:00',
            'flag_reworded_at' => null,
            'operator_flag' => true,
            'untagged' => false,
            'no_summary' => true,
        ], ['operator_flag', 'untagged', 'no_summary']);

        self::assertSame(
            'The restore steps name the old box. Fix from the runbook notes.',
            $candidate['operator_flag']['comment'],
            'the comment IS the feature — a candidate that ranks first and says nothing '
            .'is worse than no flag at all'
        );
        self::assertSame('Jane', $candidate['operator_flag']['flagged_by']);
        self::assertSame('2026-08-17T21:30:00+00:00', $candidate['operator_flag']['flagged_at']);
        self::assertNull($candidate['operator_flag']['reworded_at']);
        // `operator_flag` rides in `reasons` too, so a caller listing why a note
        // is here names the operator rather than silently omitting them.
        self::assertSame(['operator_flag', 'no_summary'], $candidate['reasons']);
        // …and NOT in the defect count: a request is not a fault, and folding
        // it in would make "1 defect" mean two different things.
        self::assertSame(1, $candidate['defects']);
    }

    public function testAnUnflaggedCandidateHasNoFlagKeyAtAll(): void
    {
        $candidate = CurationQueue::candidateRow([
            'id' => 7,
            'title' => 'Some note',
            'status' => 'verified',
            'updated_at' => '2026-08-10 09:00:00',
            'last_at' => null,
            'defects' => 2,
            'flag_comment' => null,
            'flag_by' => null,
            'flag_at' => null,
            'flag_reworded_at' => null,
            'operator_flag' => false,
            'untagged' => true,
            'no_summary' => true,
        ], ['operator_flag', 'untagged', 'no_summary']);

        // Absent, not null: "nobody has said anything about this note" is what a
        // missing key reads as, where a null invites a caller to render an empty
        // comment box under a heading that promises one.
        self::assertArrayNotHasKey('operator_flag', $candidate);
        self::assertSame(['untagged', 'no_summary'], $candidate['reasons']);
        self::assertNull($candidate['last_curated_at'], 'null last_at means never curated');
    }

    /**
     * Found live, an hour after this shipped: the operator flagged a note and
     * the queue reported `last_curated_at` equal to the second they pressed the
     * button. Every "last curated" query here is `MAX(created_at)` over
     * `curator_log` rows for the note, and `flag-raised` is one — so asking for
     * attention read as receiving it. The open flag still came back (flags
     * ignore the cooldown, which is the only reason the bug did not also hide
     * the note it was raised about), but `blast_radius` reads the same
     * timestamp as "already handled since the neighbour moved".
     *
     * The rule this pins: a request is excluded, an ANSWER is not. The
     * curator resolving a flag is work and is exactly the record of the note
     * having been dealt with.
     */
    public function testAskingForCurationIsNotCuration(): void
    {
        self::assertContains(CuratorLogEntry::ACTION_FLAG_RAISED, CuratorLogEntry::NOT_CURATION_ACTIONS);
        self::assertNotContains(CuratorLogEntry::ACTION_FLAG_RESOLVED, CuratorLogEntry::NOT_CURATION_ACTIONS);
        self::assertSame("cl.action NOT IN ('flag-raised', 'tag-removed', 'tag-merged', 'enrichment-run')", CuratorLogEntry::curationWorkOnly('cl'));
        // Both flag actions stay in the log's own filter vocabulary — they are
        // hidden from "when was this curated", never from the operator's read
        // of what happened.
        self::assertContains(CuratorLogEntry::ACTION_FLAG_RAISED, CuratorLogEntry::ACTIONS);
        self::assertContains(CuratorLogEntry::ACTION_FLAG_RESOLVED, CuratorLogEntry::ACTIONS);
    }

    /**
     * The gap the first real run walked straight into (2026-08-17): 27 flags
     * saying "delete this note" were resolved into ONE held changeset, which is
     * the complete action a curator has — it cannot delete, only file. Had the
     * operator rejected that changeset, 27 notes would have come back unflagged
     * with nothing done and nothing anywhere to say so.
     *
     * The rule is now "suppress, do not resolve": the note leaves the queue
     * while the verdict is outstanding and keeps its flag. Both verdicts then
     * do the right thing unaided — approval takes the note and cascades the
     * flag away, rejection returns a still-flagged note to the queue.
     *
     * One template renders both call sites, so the queue's correlated form and
     * the verb's parameterised guard cannot drift into disagreeing about what
     * "under review" means — which would show up as a note the queue hides and
     * the verb still lets you close.
     */
    public function testBothDirectionsOfAHeldMergeCountAsUnderReview(): void
    {
        $correlated = sprintf(CurationFlags::AWAITING_DESTRUCTIVE_REVIEW_SQL, 'n.id');
        $parameterised = sprintf(CurationFlags::AWAITING_DESTRUCTIVE_REVIEW_SQL, ':id');

        foreach ([$correlated, $parameterised] as $sql) {
            self::assertStringContainsString("ep.status = 'held'", $sql, 'applied and rejected proposals are history, not review');
            self::assertStringContainsString("ep.type IN ('delete', 'merge')", $sql);
            // The keeper of a merge is as unstable as the note being absorbed:
            // its body is replaced on approval, so curating it now is waste.
            self::assertStringContainsString('ep.merge_into_note_id', $sql);
        }
        self::assertStringContainsString('ep.note_id = n.id', $correlated);
        self::assertStringContainsString('ep.note_id = :id', $parameterised);
        // Edits are deliberately NOT here — a held edit leaves the note exactly
        // as it is, so there is nothing about it the curator should not work on.
        self::assertStringNotContainsString("'edit'", $correlated);
    }

    public function testARewordedFlagSaysWhenItWasRewritten(): void
    {
        $candidate = CurationQueue::candidateRow([
            'id' => 9,
            'title' => 'Runbook',
            'status' => 'pending',
            'updated_at' => '2026-08-16 09:00:00',
            'last_at' => null,
            'defects' => 0,
            'flag_comment' => 'Second thoughts: only the TLS section needs work.',
            'flag_by' => 'Jane',
            'flag_at' => '2026-08-15 10:00:00',
            'flag_reworded_at' => '2026-08-17 08:00:00',
            'operator_flag' => true,
        ], ['operator_flag']);

        // The pair matters to a curator deciding what the instruction asks for:
        // flagged two days ago, rewritten this morning, means the operator has
        // looked again since — and the newer words are the ones that bind.
        self::assertSame('2026-08-15T10:00:00+00:00', $candidate['operator_flag']['flagged_at']);
        self::assertSame('2026-08-17T08:00:00+00:00', $candidate['operator_flag']['reworded_at']);
        // A flagged PENDING note is deliberately reachable: the operator
        // pointing at a note is not the queue guessing about unreviewed work.
        self::assertSame('pending', $candidate['status']);
    }
}
