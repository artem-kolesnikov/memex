<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EditProposal;

/**
 * What KIND of work a held item is, so the inbox can be filtered to one kind
 * and decided in bulk.
 *
 * The operator's case: an assistant works the enrichment backlog and files
 * sixty held summaries. Reading sixty of them one at a time is not review, it
 * is attrition — but approving sixty items of MIXED kinds without reading them
 * is how a deletion goes through inside a pile of summaries. Filtering to one
 * kind first is what makes a bulk decision an informed one, so the filter is
 * not a convenience here; it is the safety property.
 *
 * An item carries a SET of kinds, not one label: an edit that writes a summary
 * and tags is genuinely both, and forcing it into a single bucket would hide it
 * from one of the two filters that should find it.
 */
final class ReviewKinds
{
    public const SUMMARY = 'summary';
    public const TAGS = 'tags';
    public const CONTENT = 'content';
    public const DELETION = 'deletion';
    public const MERGE = 'merge';
    public const NEW_NOTE = 'new_note';
    public const REPORT = 'report';

    /** @return string[] every kind the UI offers as a filter, in the order it offers them */
    public static function all(): array
    {
        return [self::SUMMARY, self::TAGS, self::CONTENT, self::NEW_NOTE, self::REPORT, self::DELETION, self::MERGE];
    }

    /**
     * The kinds of one held proposal.
     *
     * `content` means the title or the body — the part of a note a person
     * wrote. It is deliberately NOT split further: the distinction that matters
     * for a bulk decision is "this changes what the note says" versus "this
     * describes or files it", and a title change is the first kind.
     *
     * @param string[]|null $tags
     * @return string[]
     */
    public static function of(
        string $type,
        ?string $title = null,
        ?string $bodyMd = null,
        ?array $tags = null,
        ?string $summary = null,
    ): array {
        if ($type === EditProposal::TYPE_DELETE) {
            return [self::DELETION];
        }
        if ($type === EditProposal::TYPE_MERGE) {
            return [self::MERGE];
        }
        if ($type === EditProposal::TYPE_CREATE) {
            return [self::NEW_NOTE];
        }
        // Its own kind, and the reason is the safety property above: a report
        // changes nothing, so filed under `content` it would ride along in a
        // bulk approval of real edits and be reported to the operator as
        // applied. The direction is reversed from a deletion hiding in a pile
        // of summaries, and the failure is the same one.
        if ($type === EditProposal::TYPE_REPORT) {
            return [self::REPORT];
        }

        $kinds = [];
        if ($summary !== null) {
            $kinds[] = self::SUMMARY;
        }
        if ($tags !== null) {
            $kinds[] = self::TAGS;
        }
        if ($title !== null || $bodyMd !== null) {
            $kinds[] = self::CONTENT;
        }

        // An EDIT that changes nothing cannot be filed: a proposal carrying no
        // change and no comment is refused, and one carrying only a comment is
        // a report, which left above. So an empty set here would mean a row no
        // filter can reach and no bulk action can touch. Calling it content is
        // the safe reading: it lands in the bucket the operator reads most
        // carefully.
        return $kinds === [] ? [self::CONTENT] : $kinds;
    }

    /** @return string[] the kinds of a whole proposal entity */
    public static function ofProposal(EditProposal $proposal): array
    {
        return self::of(
            $proposal->getType(),
            $proposal->getProposedTitle(),
            // A patched body is a content change, and it has to say so or the
            // inbox filter that exists to stop a rewrite being approved among
            // twenty summaries would not see it.
            $proposal->getProposedBodyMd() ?? ($proposal->getProposedPatch() !== null ? '' : null),
            $proposal->getProposedTags(),
            $proposal->getProposedSummary(),
        );
    }
}
