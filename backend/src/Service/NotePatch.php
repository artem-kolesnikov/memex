<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Changing part of a note without resending all of it.
 *
 * ## Why this exists
 *
 * Until 2026-08-22 an edit was a **full replacement body**, and nothing else.
 * That is fine for a note somebody just wrote and holds in front of them, and
 * it fails in three ways on the notes that matter most — the long canonical
 * records this knowledge base is built around:
 *
 * 1. **It caused the overwrite hazard.** Two
 *    proposals held on one note each carry a whole body built from the note as
 *    it stood when their author read it, so approving the second silently
 *    reverts the first, and nothing in the inbox says the two overlap. A patch
 *    resolved at APPROVAL time composes instead: two changes to different
 *    sentences both land, and one whose anchor has since moved fails loudly
 *    rather than quietly winning.
 * 2. **It made the `live_state_notice` impractical exactly where it is aimed.**
 *    That notice asks an agent to file a full replacement, and the notes
 *    carrying it run to tens of thousands of characters. The instruction was
 *    hardest to follow on precisely the notes it protects.
 * 3. **It forced a regeneration where a correction would do.** An agent
 *    reproducing 40,000 characters to fix three sentences can corrupt a
 *    sentence it was not even editing, and nobody would find out for months.
 *
 * ## The contract
 *
 * Operations are `{find, replace}` pairs applied **in order**, each against the
 * result of the last. `find` is matched **literally** — no regular expressions,
 * because an anchor a person cannot read is an anchor nobody can review — and
 * must appear **exactly once**. Not zero times, which means the note is not
 * what the author thought; not twice, which means the author does not know
 * which one they are changing. Both are refused with the count, because the
 * fix is the same either way: quote more of the surrounding text.
 *
 * That "exactly once" rule is the whole safety property. It is what lets a
 * patch be applied twice — at proposal time as a check, and again at approval
 * time for real — and give the same answer or a clear refusal.
 *
 * An empty `find` is refused: it matches at every position. `replace` may be
 * empty, which is how a sentence is deleted.
 */
final class NotePatch
{
    /** Anchors longer than this are a pasted body, not an anchor. */
    private const MAX_ANCHOR = 20000;

    /** Enough for a rewrite in pieces; past it, send a body. */
    private const MAX_OPERATIONS = 50;

    /** The places an operation can name besides a `find`. */
    private const PLACES = ['append', 'prepend', 'before'];

    /**
     * How much of an anchor to quote back in an error. Long enough to find the
     * problem, short enough that the message stays a message.
     */
    private const EXCERPT = 60;

    /**
     * Read the wire form into operations, refusing anything malformed before it
     * reaches a note.
     *
     * Besides `{find, replace}`, an operation can name its place by the note's
     * shape (operator, 2026-10-17, from Basic Memory's `edit_note`), so adding a
     * paragraph needs no quoted sentence:
     *
     * - `{append}` / `{prepend}`: at the end or the start of the note;
     * - `{section, append}` / `{section, prepend}`: at the end of a section, or
     *   straight under its heading;
     * - `{section, replace}`: the section's text under its heading;
     * - `{section, before}`: above its heading, as a new section would go;
     * - `{find, replace, count}`: every one of `count` occurrences.
     *
     * {@see self::resolve()} turns these into plain `{find, replace}` anchors
     * before anything is stored, so a held edit is checked and applied at
     * approval exactly as one written by hand.
     *
     * @param mixed $raw the `patch` field as it arrived
     * @return array<int, array<string, mixed>>
     * @throws NotePatchException
     */
    public static function parse(mixed $raw): array
    {
        // A JSON string is accepted as well as a list, because MCP clients
        // CACHE the tool schema at connector creation (found 2026-08-20 with
        // ChatGPT) — so a client connected before `patch` existed does not know
        // it is an array and sends the caller's value stringified. Refusing
        // that would read as "the feature is broken" to the one caller who did
        // everything right. Parsed strictly: a string that is not a JSON list
        // still fails, and fails saying so.
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : $raw;
        }
        if (!is_array($raw) || $raw === []) {
            throw new NotePatchException('patch must be a non-empty list of operations: {find, replace}, {append}, {prepend}, or a {section} with {replace} or {before}.');
        }
        if (count($raw) > self::MAX_OPERATIONS) {
            throw new NotePatchException('A patch may carry at most '.self::MAX_OPERATIONS.' operations; this one carries '.count($raw).'.');
        }

        $operations = [];
        foreach (array_values($raw) as $i => $op) {
            $at = 'Operation '.($i + 1).': ';
            if (!is_array($op)) {
                throw new NotePatchException($at.'each operation must be an object: {find, replace}, {append}, {prepend}, or a {section} with {replace} or {before}.');
            }
            $operations[] = array_key_exists('find', $op) ? self::literal($op, $at) : self::placed($op, $at);
        }

        return $operations;
    }

    /**
     * @param array<mixed> $op
     * @return array{find: string, replace: string, count?: int}
     */
    private static function literal(array $op, string $at): array
    {
        $find = $op['find'];
        $replace = $op['replace'] ?? null;
        if (!is_string($find) || $find === '') {
            // An empty anchor matches at every position, so it is not a
            // question with an answer.
            throw new NotePatchException($at.'`find` must be a non-empty string — the exact text to replace.');
        }
        if (!is_string($replace)) {
            throw new NotePatchException($at.'`replace` must be a string (send "" to delete the text).');
        }
        foreach (self::PLACES as $place) {
            if (array_key_exists($place, $op)) {
                throw new NotePatchException($at.'`find` names its own place, so it goes without `'.$place.'`; send them as two operations.');
            }
        }
        if (mb_strlen($find) > self::MAX_ANCHOR) {
            throw new NotePatchException($at.'`find` is longer than '.self::MAX_ANCHOR.' characters. An anchor that long is a body — send `body_md` instead.');
        }
        if ($find === $replace) {
            // Not harmless: a proposal claiming a change it does not make
            // is reviewed and approved as though it did something.
            throw new NotePatchException($at.'`find` and `replace` are identical, so this operation would change nothing.');
        }
        $count = $op['count'] ?? 1;
        if (!is_int($count) || $count < 1) {
            throw new NotePatchException($at.'`count` must be a whole number from 1: how many times `find` occurs, every one of which is replaced.');
        }
        $section = $op['section'] ?? null;
        if ($section !== null && (!is_string($section) || trim($section) === '')) {
            throw new NotePatchException($at.'`section` must be a heading from the note, such as "Sources".');
        }

        return ['find' => $find, 'replace' => $replace]
            + ($count > 1 ? ['count' => $count] : [])
            + ($section === null ? [] : ['section' => $section]);
    }

    /**
     * @param array<mixed> $op
     * @return array<string, string>
     */
    private static function placed(array $op, string $at): array
    {
        $section = $op['section'] ?? null;
        if ($section !== null && (!is_string($section) || trim($section) === '')) {
            throw new NotePatchException($at.'`section` must be a heading from the note, such as "Sources".');
        }
        $places = array_values(array_filter(self::PLACES, static fn (string $place): bool => array_key_exists($place, $op)));
        if (count($places) > 1) {
            throw new NotePatchException($at.'an operation does one thing; send `'.implode('`, `', $places).'` as separate operations.');
        }
        if ($places === []) {
            if ($section === null || !array_key_exists('replace', $op)) {
                throw new NotePatchException($at.'each operation needs `find` and `replace`, or `append`, `prepend`, or a `section` with `replace` or `before`.');
            }
            if (!is_string($op['replace'])) {
                throw new NotePatchException($at.'`replace` must be a string: the section\'s new text under its heading ("" empties it).');
            }

            return ['section' => $section, 'replace' => $op['replace']];
        }
        $place = $places[0];
        $text = $op[$place];
        if (!is_string($text) || trim($text) === '') {
            throw new NotePatchException($at.'`'.$place.'` must be the text to add, and it is empty.');
        }
        if (array_key_exists('replace', $op)) {
            throw new NotePatchException($at.'`replace` goes with `find` or a `section`, not with `'.$place.'`.');
        }
        if ($place === 'before' && $section === null) {
            throw new NotePatchException($at.'`before` needs the `section` whose heading the text goes above.');
        }

        return [$place => $text] + ($section === null ? [] : ['section' => $section]);
    }

    /**
     * Apply operations to a body, or explain exactly which one did not fit.
     *
     * Nothing is applied unless every operation fits: the caller gets a whole
     * new body or an exception, never a note half-patched by the operations
     * that happened to come first.
     *
     * @param array<int, array<string, mixed>> $operations
     * @throws NotePatchException
     */
    public static function apply(string $body, array $operations): string
    {
        $result = $body;
        foreach ($operations as $i => $op) {
            $result = self::applyOne($result, $op, $i);
        }

        if (trim($result) === '') {
            throw new NotePatchException('That patch would empty the note. Deleting a note is `propose_delete`, which keeps it restorable.');
        }

        return $result;
    }

    /**
     * The same operations as plain `{find, replace}` anchors, each occurring
     * exactly once where it is applied, so applying them gives what applying
     * the originals gives. An anchor already written that way is kept as sent.
     *
     * @param array<int, array<string, mixed>> $operations
     * @return array<int, array{find: string, replace: string}>
     * @throws NotePatchException
     */
    public static function resolve(string $body, array $operations): array
    {
        $resolved = [];
        $current = $body;
        foreach ($operations as $i => $op) {
            if (isset($op['find']) && !isset($op['count']) && !isset($op['section'])) {
                $current = self::applyOne($current, $op, $i);
                $resolved[] = ['find' => $op['find'], 'replace' => $op['replace']];
                continue;
            }
            if (isset($op['find']) && !isset($op['section'])) {
                // One anchor per occurrence, left to right, each made unique
                // against the text as the ones before it left it.
                self::applyOne($current, $op, $i);
                $positions = [];
                for ($from = 0; ($pos = strpos($current, $op['find'], $from)) !== false; $from = $pos + strlen($op['find'])) {
                    $positions[] = $pos;
                }
                $shift = 0;
                foreach ($positions as $pos) {
                    $next = substr_replace($current, $op['replace'], $pos + $shift, strlen($op['find']));
                    $resolved[] = self::between($current, $next);
                    $current = $next;
                    $shift += strlen($op['replace']) - strlen($op['find']);
                }
                continue;
            }
            $next = self::applyOne($current, $op, $i);
            if ($next === $current) {
                throw new NotePatchException('Operation '.($i + 1).': the section already reads that way, so this operation would change nothing.');
            }
            $resolved[] = self::between($current, $next);
            $current = $next;
        }

        return $resolved;
    }

    /** Whether any operation names its place other than by a `find` used once. */
    public static function placesByShape(array $operations): bool
    {
        foreach ($operations as $op) {
            if (!isset($op['find']) || isset($op['count']) || isset($op['section'])) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $op */
    private static function applyOne(string $body, array $op, int $index): string
    {
        if (isset($op['find'])) {
            $expected = $op['count'] ?? 1;
            // With a section, `find` is looked for in that section alone, so
            // text that recurs elsewhere in the note needs no more quoting.
            [$lead, $within, $rest] = ['', $body, ''];
            if (isset($op['section'])) {
                $section = self::section($body, $op['section'], $index);
                [$lead, $within, $rest] = [
                    substr($body, 0, $section['start']),
                    substr($body, $section['start'], $section['end'] - $section['start']),
                    substr($body, $section['end']),
                ];
            }
            $count = substr_count($within, $op['find']);
            if ($count !== $expected) {
                throw new NotePatchException(self::explain($index, $op['find'], $count, $expected, isset($op['section'])));
            }

            return $lead.str_replace($op['find'], $op['replace'], $within).$rest;
        }

        $text = trim((string) ($op['append'] ?? $op['prepend'] ?? $op['before'] ?? $op['replace']), "\r\n");
        $endsWithNewline = str_ends_with($body, "\n");
        if (!isset($op['section'])) {
            return isset($op['append'])
                ? rtrim($body)."\n\n".$text.($endsWithNewline ? "\n" : '')
                : $text."\n\n".ltrim($body, "\r\n");
        }

        $section = self::section($body, $op['section'], $index);
        $lead = substr($body, 0, $section['start']);
        if (isset($op['before'])) {
            $lead = rtrim($lead, "\r\n");

            return ($lead === '' ? '' : $lead."\n\n").$text."\n\n".substr($body, $section['start']);
        }
        $heading = rtrim(substr($body, $section['start'], $section['body_start'] - $section['start']), "\r\n");
        $own = trim(substr($body, $section['body_start'], $section['end'] - $section['body_start']), "\r\n");
        $rest = substr($body, $section['end']);
        $content = match (true) {
            isset($op['append']) => $own === '' ? $text : $own."\n\n".$text,
            isset($op['prepend']) => $own === '' ? $text : $text."\n\n".$own,
            default => $text,
        };
        $tail = $rest === '' ? ($endsWithNewline ? "\n" : '') : "\n\n";

        return $lead.$heading.($content === '' ? '' : "\n\n".$content).$tail.$rest;
    }

    /** @return array{level: int, heading: string, start: int, body_start: int, end: int} */
    private static function section(string $body, string $heading, int $index): array
    {
        try {
            return NoteSections::locate($body, $heading);
        } catch (\InvalidArgumentException $e) {
            throw new NotePatchException('Operation '.($index + 1).': '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * One `{find, replace}` that turns `$before` into `$after`: the lines the
     * two differ in, widened a line at a time until the text occurs once.
     *
     * @return array{find: string, replace: string}
     */
    private static function between(string $before, string $after): array
    {
        $length = strlen($before);
        $shared = min($length, strlen($after));
        $prefix = 0;
        while ($prefix < $shared && $before[$prefix] === $after[$prefix]) {
            ++$prefix;
        }
        $suffix = 0;
        while ($suffix < $shared - $prefix && $before[$length - 1 - $suffix] === $after[strlen($after) - 1 - $suffix]) {
            ++$suffix;
        }
        $start = self::lineStart($before, $prefix);
        $end = self::lineEnd($before, $length - $suffix);
        while ($start === $end || substr_count($before, substr($before, $start, $end - $start)) !== 1) {
            if ($start === 0 && $end === $length) {
                break;
            }
            $start = $start > 0 ? self::lineStart($before, $start - 1) : 0;
            $end = $end < $length ? self::lineEnd($before, $end + 1) : $length;
        }
        $grown = strlen($after) - $length;

        return ['find' => substr($before, $start, $end - $start), 'replace' => substr($after, $start, $end - $start + $grown)];
    }

    private static function lineStart(string $text, int $at): int
    {
        $newline = $at > 0 ? strrpos($text, "\n", $at - strlen($text) - 1) : false;

        return $newline === false ? 0 : $newline + 1;
    }

    private static function lineEnd(string $text, int $at): int
    {
        $newline = strpos($text, "\n", min($at, strlen($text)));

        return $newline === false ? strlen($text) : $newline;
    }

    /**
     * The refusal, worded so the fix is obvious. Both failures have the same
     * remedy — quote more of the note — and the count is what tells them apart.
     */
    private static function explain(int $index, string $find, int $count, int $expected = 1, bool $inSection = false): string
    {
        $excerpt = mb_strlen($find) > self::EXCERPT ? mb_substr($find, 0, self::EXCERPT).'…' : $find;
        $at = 'Operation '.($index + 1).': ';
        $where = $inSection ? 'the section' : 'the note';

        if ($expected > 1) {
            return $at.'that text appears '.$count.' times in '.$where.', not the '.$expected.' `count` says — “'.$excerpt.'”. '
                .'Read the note again: `count` is how many there are, and every one is replaced.';
        }
        if ($count === 0) {
            return $at.'that text is not in '.$where.' as it now stands — “'.$excerpt.'”. '
                .'Read the note again and anchor on what is there; it may have been edited since you read it.';
        }

        return $at.'that text appears '.$count.' times in '.$where.' — “'.$excerpt.'”. '
            .'Include enough of the surrounding text to name the one you mean.';
    }
}
