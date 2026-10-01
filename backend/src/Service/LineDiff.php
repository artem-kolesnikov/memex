<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A line-oriented diff, and the patch that replays it.
 *
 * Exists for one caller: {@see NoteRevisions}, which stores a note's previous
 * states as REVERSE deltas against the state that followed them, rather than as
 * twenty full copies of the note. See that class for why the deltas point
 * backwards.
 *
 * ## Why write one rather than take a dependency
 *
 * `sebastian/diff` is already installed and is the obvious answer, and it is
 * the wrong one: it arrives as a dependency of PHPUnit, so it is in
 * `require-dev`. Using it from `src/` would make production behaviour depend on
 * a package a `composer install --no-dev` deploy does not install — the failure
 * landing on the write path of the feature that makes edits reversible.
 *
 * ## The algorithm
 *
 * Common prefix and suffix are trimmed first, which for the case this exists
 * for — an unattended pass rewording one paragraph of a long note — removes
 * essentially the whole problem before the interesting part starts.
 *
 * What remains goes through Myers' O(ND) greedy algorithm, where D is the
 * number of differing lines. That is the right shape here: D is small for a
 * real edit and the cost tracks it, rather than tracking the size of the note
 * the way a full LCS table would. A 97,000-character note is about a thousand
 * lines, and an O(N*M) table over that is a million cells computed to describe
 * a three-line change.
 *
 * {@see self::MAX_D} bounds it anyway. A diff is an optimisation, and an
 * optimisation that can spend unbounded time on a pathological input is not
 * one: past the bound this returns "replace everything", which is exactly what
 * was stored before this class existed. Correct, just larger.
 *
 * ## The operations
 *
 * A patch is a list of `[op, arg]` pairs, JSON-encodable because that is how it
 * is stored:
 *
 *   - `[self::COPY, n]`   — take the next n lines from the source unchanged
 *   - `[self::DROP, n]`   — skip the next n lines of the source
 *   - `[self::ADD, [..]]` — emit these lines, consuming nothing
 *
 * Splitting on "\n" and joining on "\n" round-trips any string exactly,
 * trailing newline included (it becomes a final empty element), so this is
 * lossless for content it has never been told anything about — which matters,
 * because the content is somebody's only copy.
 */
final class LineDiff
{
    public const COPY = 0;
    public const DROP = 1;
    public const ADD = 2;

    /**
     * The point at which computing a minimal diff stops being worth it.
     *
     * Reached only by a near-total rewrite, where the diff would be about the
     * size of the note anyway and there is nothing to win. Past it, the patch
     * becomes a whole-body replacement — correct, just larger, and exactly
     * what every revision was before this class existed.
     *
     * **The bound is set by MEMORY, not by time.** Backtracking needs the
     * frontier from every round, so the trace is O(D²) integers — and the
     * first version of this set the bound at 3000 on time-based reasoning and
     * exhausted a 128 MB limit on the first full-rewrite test, at about
     * D=2400. Measured, not argued. 600 keeps the trace to a few hundred
     * thousand entries, and an edit that changes six hundred lines is one
     * where the diff was never going to pay for itself.
     */
    private const MAX_D = 600;

    /**
     * A patch that turns `$from` into `$to`.
     *
     * @return list<array{0: int, 1: int|list<string>}>
     */
    public static function diff(string $from, string $to): array
    {
        if ($from === $to) {
            // Distinct from "no patch": an empty op list applied to a body
            // yields the empty string, so a no-change patch has to say so.
            return [[self::COPY, self::lineCount($from)]];
        }

        $a = explode("\n", $from);
        $b = explode("\n", $to);

        // Trim the matching ends. For the case this exists for this is where
        // almost all of the saving comes from.
        $head = 0;
        $lastA = count($a) - 1;
        $lastB = count($b) - 1;
        while ($head <= $lastA && $head <= $lastB && $a[$head] === $b[$head]) {
            ++$head;
        }
        $tail = 0;
        while (
            $lastA - $tail >= $head
            && $lastB - $tail >= $head
            && $a[$lastA - $tail] === $b[$lastB - $tail]
        ) {
            ++$tail;
        }

        $midA = array_slice($a, $head, count($a) - $head - $tail);
        $midB = array_slice($b, $head, count($b) - $head - $tail);

        $ops = [];
        if ($head > 0) {
            $ops[] = [self::COPY, $head];
        }
        foreach (self::myers($midA, $midB) as $op) {
            $ops[] = $op;
        }
        if ($tail > 0) {
            $ops[] = [self::COPY, $tail];
        }

        return self::coalesce($ops);
    }

    /**
     * Replay a patch. Throws rather than guessing if it does not fit.
     *
     * A patch that runs off the end of its source means the source is not the
     * text this patch was made against — a chain read in the wrong order, or a
     * row that has been corrupted. Both are cases where returning *something*
     * would be worse than failing: the caller is reconstructing somebody's
     * previous note, and half of one is not a previous note.
     *
     * @param list<array{0: int, 1: int|list<string>}> $ops
     *
     * @throws \RuntimeException
     */
    public static function apply(string $from, array $ops): string
    {
        $a = explode("\n", $from);
        $at = 0;
        $out = [];

        foreach ($ops as $op) {
            [$kind, $arg] = $op;
            switch ($kind) {
                case self::COPY:
                    $n = (int) $arg;
                    if ($at + $n > count($a)) {
                        throw new \RuntimeException('Patch does not fit its source: copy ran past the end');
                    }
                    for ($i = 0; $i < $n; ++$i) {
                        $out[] = $a[$at++];
                    }
                    break;
                case self::DROP:
                    $n = (int) $arg;
                    if ($at + $n > count($a)) {
                        throw new \RuntimeException('Patch does not fit its source: drop ran past the end');
                    }
                    $at += $n;
                    break;
                case self::ADD:
                    foreach ((array) $arg as $line) {
                        $out[] = (string) $line;
                    }
                    break;
                default:
                    throw new \RuntimeException('Unknown patch operation: '.var_export($kind, true));
            }
        }

        if ($at !== count($a)) {
            throw new \RuntimeException('Patch does not fit its source: '.(count($a) - $at).' line(s) unaccounted for');
        }

        return implode("\n", $out);
    }

    /**
     * Myers' greedy O(ND) edit script over two line arrays.
     *
     * Walks the edit graph one D at a time, keeping the furthest-reaching path
     * on each diagonal, and records the frontier entering each round so the
     * script can be recovered by walking back through them. Standard; what is
     * ours is the bound and the fact that we can reason about it.
     *
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<array{0: int, 1: int|list<string>}>
     */
    private static function myers(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        if ($n === 0) {
            return $m === 0 ? [] : [[self::ADD, $b]];
        }
        if ($m === 0) {
            return [[self::DROP, $n]];
        }

        $max = min($n + $m, self::MAX_D);
        $v = [1 => 0];
        $trace = [];

        for ($d = 0; $d <= $max; ++$d) {
            // The frontier as it stands ENTERING round $d. Backtracking reads
            // it the same way, which is the invariant the two share.
            $trace[] = $v;
            for ($k = -$d; $k <= $d; $k += 2) {
                // Which neighbour reached further: down (an insertion) or
                // right (a deletion).
                $x = self::goesDown($v, $k, $d) ? self::at($v, $k + 1) : self::at($v, $k - 1) + 1;
                $y = $x - $k;
                // The snake: every line the two sides already agree on.
                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    ++$x;
                    ++$y;
                }
                $v[$k] = $x;
                if ($x >= $n && $y >= $m) {
                    return self::backtrack($trace, $b, $n, $m);
                }
            }
        }

        // Past the bound: correct, just not minimal. This is what every
        // revision was stored as before diffs existed.
        return [[self::DROP, $n], [self::ADD, $b]];
    }

    /**
     * Whether the path onto diagonal `$k` in round `$d` arrived by moving DOWN
     * (an insertion from `$b`) rather than right (a deletion from `$a`).
     *
     * Extracted because the forward walk and the backward walk have to answer
     * it identically — two copies of the same expression is the shape this
     * algorithm goes subtly wrong in, and did here on the first attempt.
     *
     * @param array<int, int> $v
     */
    private static function goesDown(array $v, int $k, int $d): bool
    {
        return $k === -$d || ($k !== $d && self::at($v, $k - 1) < self::at($v, $k + 1));
    }

    /** @param array<int, int> $v */
    private static function at(array $v, int $k): int
    {
        return $v[$k] ?? PHP_INT_MIN;
    }

    /**
     * Recover the edit script by walking the recorded frontiers backwards.
     *
     * From the end point, each round says which single move produced it and
     * how long the diagonal run before that move was. Ops come out reversed
     * and one line at a time; `coalesce()` puts them back together.
     *
     * @param list<array<int, int>> $trace
     * @param list<string>          $b
     *
     * @return list<array{0: int, 1: int|list<string>}>
     */
    private static function backtrack(array $trace, array $b, int $n, int $m): array
    {
        $ops = [];
        $x = $n;
        $y = $m;

        for ($d = count($trace) - 1; $d >= 0; --$d) {
            $v = $trace[$d];
            // Derived from where we ARE, not carried from where we finished.
            // Carrying it is the bug that made the first version of this emit
            // patches which did not fit their own source.
            $k = $x - $y;

            if ($d === 0) {
                $prevX = 0;
                $prevY = 0;
            } else {
                $prevK = self::goesDown($v, $k, $d) ? $k + 1 : $k - 1;
                $prevX = self::at($v, $prevK);
                $prevY = $prevX - $prevK;
            }

            // Walk the diagonal back to the move that started it.
            while ($x > $prevX && $y > $prevY) {
                $ops[] = [self::COPY, 1];
                --$x;
                --$y;
            }

            if ($d > 0) {
                // One move: down consumed a line of $b, right consumed one of $a.
                $ops[] = $x === $prevX ? [self::ADD, [$b[$prevY]]] : [self::DROP, 1];
            }

            $x = $prevX;
            $y = $prevY;
        }

        return array_reverse($ops);
    }

    /**
     * Merge neighbouring operations of the same kind.
     *
     * Backtracking emits one op per edited line, so a ten-line insertion
     * arrives as ten `ADD`s. Without this the patch for a paragraph rewrite is
     * mostly JSON punctuation, which would undo a fair share of the saving this
     * class exists for.
     *
     * @param list<array{0: int, 1: int|list<string>}> $ops
     *
     * @return list<array{0: int, 1: int|list<string>}>
     */
    private static function coalesce(array $ops): array
    {
        $out = [];
        foreach ($ops as $op) {
            $last = $out === [] ? null : $out[count($out) - 1];
            if ($last !== null && $last[0] === $op[0]) {
                if ($op[0] === self::ADD) {
                    $out[count($out) - 1][1] = array_merge((array) $last[1], (array) $op[1]);
                    continue;
                }
                $out[count($out) - 1][1] = (int) $last[1] + (int) $op[1];
                continue;
            }
            $out[] = $op;
        }

        return $out;
    }

    private static function lineCount(string $s): int
    {
        return substr_count($s, "\n") + 1;
    }
}
