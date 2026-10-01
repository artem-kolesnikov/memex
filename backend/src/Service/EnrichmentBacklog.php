<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The one definition of "this note still wants describing, and is free to be
 * worked right now".
 *
 * ## Who reads it, and why there is only one of them left
 *
 * `needs_enrichment` — the list a connected assistant is handed. Until
 * 2026-08-23 memex's own scheduled pass read the same queue, and the two
 * having separate copies of the predicate is what let them duplicate each
 * other's work and each other's spend.
 *
 * **The pass is gone** (operator, 2026-08-23). Enrichment fires on SAVE when a
 * vault has switched it on: a note that arrives without a description gets one,
 * and gets filed under tags its vault's vocabulary already contains, in the
 * request that created it. Nothing needs a timer to do that. What is left for
 * this queue is the case a save cannot reach — content that was already there
 * when enrichment was switched on, most of all an imported vault, which
 * {@see \App\Controller\ImportController} deliberately creates without inline
 * enrichment because doing it in the request would hang. That backlog is the
 * assistant's to work, through the connection its owner already made.
 *
 * ## The exclusion that stays, and the one that went with the pass
 *
 * **Work in flight still hides a note.** Any HELD proposal on it, whoever
 * filed it. Without this an assistant that
 * describes a note is handed it again on its next call, because a held
 * proposal is not a summary: the note still lacks exactly what put it in the
 * queue. That was measured, and it is the half of the 2026-08-23 fix worth
 * keeping. Merges count in both directions, matching the reasoning
 * {@see CurationFlags::AWAITING_DESTRUCTIVE_REVIEW_SQL} has used since
 * 2026-08-08: neither note in a merge is a sensible thing to curate.
 *
 * **The cooldown went.** A seven-day rest after a pass had one job — stopping
 * an unattended runner re-buying a rejected proposal on its next tick — and
 * there is no unattended runner. A person driving an assistant is present, can
 * see what happened last time, and should not have their own knowledge base
 * withheld from them for a week.
 *
 * ## What is deliberately NOT here
 *
 * The website's *Not described yet* filter keeps asking
 * {@see CurationQueue::NO_SUMMARY_SQL} alone. That control answers "which of
 * my notes have no description", a question about the notes; this class
 * answers "what should a worker pick up", a question about a queue. A browse
 * filter that hid notes because something was pending would be lying about the
 * collection.
 */
final class EnrichmentBacklog
{
    /**
     * "Somebody is already waiting on a verdict about this note."
     *
     * A printf template over the note reference (`%1$s`) so the correlated
     * form used inside a query and any parameterised form stay one statement.
     * Broader than {@see CurationFlags::AWAITING_DESTRUCTIVE_REVIEW_SQL}, which
     * asks only about deletes and merges: enrichment has to stand aside for an
     * ordinary held EDIT too, because that is the one carrying the description
     * it was about to write.
     */
    public const AWAITING_REVIEW_SQL = 'EXISTS (
                SELECT 1 FROM edit_proposals ep
                WHERE ep.status = \'held\'
                  AND (ep.note_id = %1$s OR ep.merge_into_note_id = %1$s)
            )';

    /** The whole predicate, over a `notes` row aliased `n`. Binds nothing. */
    public static function sql(): string
    {
        return '('.self::wants().' AND NOT '.self::awaitingReview().')';
    }

    /**
     * Notes that want describing but are held back because work on them is
     * already waiting.
     *
     * Reported rather than silently subtracted: an assistant told the backlog
     * is empty, while twenty descriptions sit unread in the operator's inbox,
     * would tell them their knowledge base is fully described. Binds nothing.
     */
    public static function restingSql(): string
    {
        return 'SELECT COUNT(*) AS awaiting_review
            FROM notes n
            WHERE '.self::wants().' AND '.self::awaitingReview();
    }

    /** What a note LACKS: nobody has described it, or nobody has filed it. */
    private static function wants(): string
    {
        return '(('.CurationQueue::NO_SUMMARY_SQL.') OR '.CurationQueue::UNTAGGED_SQL.')';
    }

    private static function awaitingReview(): string
    {
        return sprintf(self::AWAITING_REVIEW_SQL, 'n.id');
    }
}
