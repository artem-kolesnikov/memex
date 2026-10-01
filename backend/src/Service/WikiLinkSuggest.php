<?php

declare(strict_types=1);

namespace App\Service;

/**
 * "You mention a note you already have — link it."
 *
 * Pure string work against the vault's own titles: **no provider call, no
 * token, no cost.** That is what makes it the most valuable thing in an
 * enrichment pass rather than the least. A connected assistant is bad at this
 * for a structural reason — it cannot hold the whole title list in mind while
 * reading one note, so it links what it happens to remember — and the server
 * can compare every title against every note for nothing.
 *
 * It is the PHP twin of `frontend/src/lib/wikiLinkSuggest.ts`, which does the
 * same job for the editor while somebody is typing. Two implementations of one
 * rule is a shape this codebase has been bitten by (2026-08-23: one question
 * answered in three formats by three paths), so the rules are written out
 * here in the same order and the same words, and the tests below pin the ones
 * that matter. They are not shared because they cannot be: one runs in a
 * browser against an unsaved draft, the other on the server against a stored
 * note, and a shared implementation would mean shipping a PHP interpreter or
 * a Node process to the other side.
 *
 * ## What it refuses to link, and why each one bites
 *
 * - **Inside code.** A title that appears in a shell command is not a
 *   reference to a note; linkifying it corrupts a runbook somebody will paste.
 * - **Inside an existing link** of any kind, wiki or markdown — including one
 *   the pass itself added on an earlier run, which is what stops it proposing
 *   the same edit every night for ever.
 * - **Inside a bare URL**, where a title that happens to be a path segment
 *   would otherwise be rewritten into a broken address.
 * - **Part of a longer word.** "Hermes" must not fire inside "Hermesian", so
 *   the boundary is word-ish rather than whitespace — and it has to admit
 *   titles that end in punctuation, because `memex.tools` is a real title.
 * - **Its own title**, because a note does not link to itself.
 * - **Short titles.** Under four characters a title matches too much ordinary
 *   prose to be worth offering.
 *
 * ## Only the first occurrence
 *
 * Six mentions of one note become one link, at the first one. Linking every
 * occurrence turns a paragraph into a wall of brackets and adds nothing: the
 * graph edge exists after the first.
 */
final class WikiLinkSuggest
{
    /** Titles shorter than this match too much prose to be worth offering. */
    private const MIN_TITLE_LENGTH = 4;

    /** A cap on one note's worth of edits, so a glossary does not become a mesh. */
    private const MAX_LINKS_PER_NOTE = 10;

    /**
     * Anchored {find, replace} operations that turn the first mention of each
     * candidate title into a wiki-link, ready for `NoteWriter::propose(patch:)`.
     *
     * A patch rather than a rewritten body, and that is the whole reason this
     * is safe to run unattended: an anchored edit is resolved against the note
     * AS IT THEN STANDS when the operator approves it, so a pass that runs
     * tonight cannot revert an edit somebody makes tomorrow morning.
     *
     * @param array<int, array{id: int, title: string}> $candidates every note in the vault
     *
     * @return array<int, array{find: string, replace: string}>
     */
    public function patchFor(string $body, array $candidates, string $ownTitle): array
    {
        $protected = self::protectedRanges($body);
        $own = mb_strtolower(trim($ownTitle));

        // Longest first: when "memex" and "memex.tools" both appear, the more
        // specific title should claim the mention. Without this the short one
        // matches inside the long one's text and the long one then finds
        // nothing left to link.
        usort($candidates, static fn (array $a, array $b) => mb_strlen($b['title']) <=> mb_strlen($a['title']));

        $patch = [];
        $claimed = [];
        foreach ($candidates as $candidate) {
            $title = trim($candidate['title']);
            if (mb_strlen($title) < self::MIN_TITLE_LENGTH || mb_strtolower($title) === $own) {
                continue;
            }
            $hit = self::firstOccurrence($body, $title, $protected, $claimed);
            if ($hit === null) {
                continue;
            }
            [$index, $matched] = $hit;
            $claimed[] = [$index, $index + strlen($matched)];

            // The `find` must occur EXACTLY ONCE in the note or the server
            // refuses the patch, so it carries enough surrounding text to be
            // unique rather than being the bare title.
            $find = self::uniqueAnchor($body, $index, strlen($matched));
            if ($find === null) {
                continue;
            }
            $patch[] = [
                'find' => $find,
                'replace' => str_replace($matched, '[['.$matched.']]', $find),
            ];
            if (count($patch) >= self::MAX_LINKS_PER_NOTE) {
                break;
            }
        }

        return $patch;
    }

    /**
     * The shortest window around a hit that appears exactly once in the note.
     *
     * Needed because `find` is matched literally and must be unique: a note
     * mentioning "Hermes" twice would otherwise produce a patch the server
     * refuses, and refusing at propose time is better than a proposal that
     * fails when somebody approves it. Null when even a generous window is
     * ambiguous, which means this mention is simply not linked.
     */
    private static function uniqueAnchor(string $body, int $index, int $length): ?string
    {
        foreach ([0, 12, 30, 60] as $pad) {
            $from = max(0, $index - $pad);
            $to = min(strlen($body), $index + $length + $pad);
            $window = substr($body, $from, $to - $from);
            if (substr_count($body, $window) === 1) {
                return $window;
            }
        }

        return null;
    }

    /**
     * Spans where a match must NOT be linkified.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private static function protectedRanges(string $body): array
    {
        $ranges = [];
        $patterns = [
            '/^[ \t]{0,3}```.*?(?:^[ \t]{0,3}```|\z)/ms',  // fenced code
            '/`[^`\n]*`/',                                  // inline code
            '/\[\[[^\[\]]*\]\]/',                           // existing wiki-links
            '/!?\[[^\]\n]*\]\([^)\n]*\)/',                  // markdown links and images
            '~\bhttps?://\S+~',                             // bare URLs
        ];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $body, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }
            foreach ($matches[0] as [$text, $offset]) {
                $ranges[] = [$offset, $offset + strlen($text)];
            }
        }

        return $ranges;
    }

    /**
     * The first linkable occurrence of `$title`, or null.
     *
     * @param array<int, array{0: int, 1: int}> $protected
     * @param array<int, array{0: int, 1: int}> $claimed spans an earlier candidate already took
     *
     * @return array{0: int, 1: string}|null
     */
    private static function firstOccurrence(string $body, string $title, array $protected, array $claimed): ?array
    {
        // Whole-word, case-insensitive, and the boundary is `\w-` rather than
        // `\b` so a title ending in punctuation still matches: `\b` after the
        // `s` of "memex.tools" is satisfied by the following space, but `\b`
        // after a full stop is not.
        $pattern = '/(?<![\w-])'.preg_quote($title, '/').'(?![\w-])/iu';
        if (preg_match_all($pattern, $body, $matches, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }
        foreach ($matches[0] as [$text, $offset]) {
            if (!self::overlaps($protected, $offset, $offset + strlen($text))
                && !self::overlaps($claimed, $offset, $offset + strlen($text))) {
                return [$offset, $text];
            }
        }

        return null;
    }

    /** @param array<int, array{0: int, 1: int}> $ranges */
    private static function overlaps(array $ranges, int $from, int $to): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($from < $end && $to > $start) {
                return true;
            }
        }

        return false;
    }
}
