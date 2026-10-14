<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A held edit narrowed to the words it changes (operator, 2026-10-13).
 *
 * An agent may send a whole body, or a find/replace that quotes a paragraph to
 * change a word in it. Stored that way, approving it puts back everything it
 * quoted: a whole body reverts what changed in the note while it waited, and a
 * paragraph-sized anchor stops fitting as soon as anyone changes any word of
 * that paragraph. Narrowed, the edit is the words it changes with just enough
 * of the text around them to be found exactly once, so approval lays only
 * those words onto the note as it then stands.
 *
 * Narrowing never changes what the edit does to the note it was written
 * against: the operations must rebuild the agent's text to the character, and
 * an edit whose operations do not is stored as sent. So is a rewrite, where
 * more than half of the note's sentences changed: as a list of replacements it
 * would read worse, not better.
 */
final class SurgicalEdit
{
    /** A sentence and the spaces after it, or a line and its line break. */
    private const SENTENCE = '/[^\n]*?(?:[.!?…]+["\'”’)\]]*[ \t]+(?=\S)|\n|\z)/u';

    /**
     * A line break with the indentation after it; a word or a punctuation mark
     * with the spaces in front of it; or trailing spaces.
     */
    private const WORD = '/\n+[^\S\n]*|[^\S\n]*(?:[\p{L}\p{N}\p{M}]+|[^\s\p{L}\p{N}\p{M}])|[^\S\n]+/u';

    /** Shorter anchors are found once today and twice after the next edit. */
    private const MIN_ANCHOR = 24;

    private const REWRITE = 0.5;

    /** Words between two changes in one sentence below which they are one change. */
    private const JOIN_GAP = 2;

    /** NotePatch's own ceiling: past it, the edit is a body. */
    private const MAX_OPERATIONS = 50;

    /** The largest comparison table built, about 4 MB. */
    private const MAX_CELLS = 250_000;

    /**
     * The operations that turn $before into $after, each as narrow as the
     * change allows, or null when the edit is better stored as sent.
     *
     * @return array<int, array{find: string, replace: string}>|null
     */
    public static function narrow(string $before, string $after): ?array
    {
        if ($before === $after) {
            return null;
        }
        $old = self::split(self::SENTENCE, $before);
        $new = self::split(self::SENTENCE, $after);
        if ($old === null || $new === null) {
            return null;
        }
        $sentenceHunks = self::diff($old, $new);
        if ($sentenceHunks === null || $sentenceHunks === []) {
            return null;
        }
        $counted = array_filter($old, static fn (string $s): bool => trim($s) !== '');
        $touched = 0;
        foreach ($sentenceHunks as [$from, $to]) {
            for ($i = $from; $i < $to; $i++) {
                $touched += trim($old[$i]) !== '' ? 1 : 0;
            }
        }
        if ($counted !== [] && $touched / count($counted) > self::REWRITE) {
            return null;
        }

        // The note as words, and where each sentence's words begin.
        $units = [];
        $sentenceOf = [];
        $startOf = [];
        foreach ($old as $i => $sentence) {
            $startOf[$i] = count($units);
            $words = self::split(self::WORD, $sentence);
            if ($words === null) {
                return null;
            }
            array_push($units, ...$words);
            array_push($sentenceOf, ...array_fill(0, count($words), $i));
        }
        $startOf[count($old)] = count($units);

        $hunks = [];
        foreach ($sentenceHunks as [$from, $to, $newFrom, $newTo]) {
            $removed = self::wordsOf(array_slice($old, $from, $to - $from));
            $added = self::wordsOf(array_slice($new, $newFrom, $newTo - $newFrom));
            if ($removed === null || $added === null) {
                return null;
            }
            $wordHunks = self::diff($removed, $added) ?? [[0, count($removed), 0, count($added)]];
            // Changes a word or two apart are one change to read.
            $joined = [];
            foreach ($wordHunks as $hunk) {
                $last = array_key_last($joined);
                if ($last !== null && $hunk[0] - $joined[$last][1] <= self::JOIN_GAP) {
                    $joined[$last] = [$joined[$last][0], $hunk[1], $joined[$last][2], $hunk[3]];
                } else {
                    $joined[] = $hunk;
                }
            }
            foreach ($joined as [$x, $xEnd, $y, $yEnd]) {
                $hunks[] = [$startOf[$from] + $x, $startOf[$from] + $xEnd, array_slice($added, $y, $yEnd - $y)];
            }
        }
        if (count($hunks) > self::MAX_OPERATIONS) {
            return null;
        }

        // Last change first, so the text left of each change is still the
        // note's own when its anchor is chosen, and each anchor is unique in
        // the text as the operations before it have left it.
        $current = $units;
        $operations = [];
        foreach (array_reverse($hunks) as [$from, $to, $insert]) {
            $text = implode('', $current);
            $sentence = $sentenceOf[$from < $to ? $from : max(0, $from - 1)] ?? 0;
            $low = $from;
            $high = $to;
            // The anchor quotes what the change follows, which is how a person
            // would say where it goes, then what comes after it. It grows to a
            // safe length only within its own sentence, so it does not reach
            // into words somebody else may be changing; past the sentence, and
            // past the line, only when it is not yet unique.
            while (true) {
                $find = implode('', array_slice($current, $low, $high - $low));
                $unique = $find !== '' && substr_count($text, $find) === 1;
                $leftInLine = $low > 0 && !str_contains($current[$low - 1], "\n");
                $rightInLine = $high < count($current) && !str_contains($current[$high], "\n");
                $leftInSentence = $leftInLine && $sentenceOf[$low - 1] === $sentence;
                $rightInSentence = $rightInLine && $sentenceOf[$high] === $sentence;
                if ($unique && (mb_strlen(trim($find)) >= self::MIN_ANCHOR || (!$leftInSentence && !$rightInSentence))) {
                    break;
                }
                if ($leftInSentence || (!$unique && $leftInLine)) {
                    $low--;
                } elseif ($rightInSentence || (!$unique && $rightInLine)) {
                    $high++;
                } elseif ($low > 0) {
                    $low--;
                } elseif ($high < count($current)) {
                    $high++;
                } else {
                    return null;
                }
            }
            $kept = array_merge(array_slice($sentenceOf, $low, $from - $low), array_fill(0, count($insert), $sentence), array_slice($sentenceOf, $to, $high - $to));
            $replacement = array_merge(array_slice($current, $low, $from - $low), $insert, array_slice($current, $to, $high - $to));
            $operations[] = ['find' => $find, 'replace' => implode('', $replacement)];
            array_splice($current, $low, $high - $low, $replacement);
            array_splice($sentenceOf, $low, $high - $low, $kept);
        }

        try {
            return NotePatch::apply($before, $operations) === $after ? $operations : null;
        } catch (NotePatchException) {
            return null;
        }
    }

    /**
     * The sentences of $body a held edit claims: the ones it changes, the one
     * it adds text after, and any its anchors quote (operator, 2026-10-14).
     * While it waits, another connection's edit to one of them is refused, so
     * every waiting edit on a note can be approved or rejected on its own.
     *
     * Operations that no longer fit claim nothing: they cannot be approved,
     * and their author's next edit replaces them. A whole body that was not
     * narrowed is a rewrite and claims every sentence.
     *
     * @param array<int, array{find: string, replace: string}>|null $operations
     * @return array<int, int> sentence indices, ascending
     */
    public static function claims(string $body, ?array $operations, ?string $wholeBody = null): array
    {
        $sentences = self::split(self::SENTENCE, $body) ?? [$body];
        if ($operations === null) {
            return $wholeBody === null || $wholeBody === $body ? [] : array_keys($sentences);
        }
        try {
            $after = NotePatch::apply($body, $operations);
        } catch (NotePatchException) {
            return [];
        }
        $claimed = [];
        foreach (self::diff($sentences, self::split(self::SENTENCE, $after) ?? [$after]) ?? [[0, count($sentences), 0, 0]] as [$from, $to]) {
            for ($i = $from; $i < $to; $i++) {
                $claimed[$i] = true;
            }
            if ($from === $to) {
                $claimed[max(0, min($from - 1, count($sentences) - 1))] = true;
            }
        }
        $starts = [];
        $offset = 0;
        foreach ($sentences as $i => $sentence) {
            $starts[$i] = $offset;
            $offset += strlen($sentence);
        }
        foreach ($operations as $operation) {
            if (substr_count($body, $operation['find']) !== 1) {
                continue;
            }
            $from = strpos($body, $operation['find']);
            $to = $from + strlen($operation['find']);
            foreach ($starts as $i => $start) {
                if ($start < $to && $start + strlen($sentences[$i]) > $from) {
                    $claimed[$i] = true;
                }
            }
        }
        $indices = array_keys($claimed);
        sort($indices);

        return $indices;
    }

    /** The text of the sentences at $indices, for saying which ones are held. */
    public static function sentencesAt(string $body, array $indices): array
    {
        $sentences = self::split(self::SENTENCE, $body) ?? [$body];

        return array_values(array_map(static fn (int $i): string => trim($sentences[$i] ?? ''), $indices));
    }

    /** @return string[]|null the pieces, which rebuild the text exactly */
    private static function split(string $pattern, string $text): ?array
    {
        if (preg_match_all($pattern, $text, $matches) === false) {
            return null;
        }
        $pieces = array_values(array_filter($matches[0], static fn (string $piece): bool => $piece !== ''));

        return implode('', $pieces) === $text ? $pieces : null;
    }

    /**
     * @param string[] $sentences
     * @return string[]|null
     */
    private static function wordsOf(array $sentences): ?array
    {
        $words = [];
        foreach ($sentences as $sentence) {
            $pieces = self::split(self::WORD, $sentence);
            if ($pieces === null) {
                return null;
            }
            array_push($words, ...$pieces);
        }

        return $words;
    }

    /**
     * Where two sequences differ, as [from, to, newFrom, newTo] ranges; null
     * when they cannot be compared within bounds.
     *
     * @param string[] $old
     * @param string[] $new
     * @return array<int, array{0: int, 1: int, 2: int, 3: int}>|null
     */
    private static function diff(array $old, array $new): ?array
    {
        return self::diffRange($old, $new, 0, count($old), 0, count($new));
    }

    /**
     * A small difference is compared exactly, by a longest common
     * subsequence. A large one, such as two changes at either end of a long
     * record, is first pinned at the pieces that occur exactly once on both
     * sides and in the same order, and only the gaps between them are compared.
     *
     * @param string[] $old
     * @param string[] $new
     * @return array<int, array{0: int, 1: int, 2: int, 3: int}>|null
     */
    private static function diffRange(array $old, array $new, int $from, int $to, int $newFrom, int $newTo): ?array
    {
        while ($from < $to && $newFrom < $newTo && $old[$from] === $new[$newFrom]) {
            $from++;
            $newFrom++;
        }
        while ($from < $to && $newFrom < $newTo && $old[$to - 1] === $new[$newTo - 1]) {
            $to--;
            $newTo--;
        }
        $rows = $to - $from;
        $cols = $newTo - $newFrom;
        if ($rows === 0 && $cols === 0) {
            return [];
        }
        if ($rows === 0 || $cols === 0) {
            return [[$from, $to, $newFrom, $newTo]];
        }
        if (($rows + 1) * ($cols + 1) <= self::MAX_CELLS) {
            return self::commonSubsequence($old, $new, $from, $to, $newFrom, $newTo);
        }

        $oldCount = array_count_values(array_slice($old, $from, $rows));
        $newCount = array_count_values(array_slice($new, $newFrom, $cols));
        $newAt = [];
        for ($j = $newFrom; $j < $newTo; $j++) {
            if ($newCount[$new[$j]] === 1 && ($oldCount[$new[$j]] ?? 0) === 1) {
                $newAt[$new[$j]] = $j;
            }
        }
        $pairs = [];
        for ($i = $from; $i < $to; $i++) {
            if ($oldCount[$old[$i]] === 1 && isset($newAt[$old[$i]])) {
                $pairs[] = [$i, $newAt[$old[$i]]];
            }
        }
        if ($pairs === []) {
            return null;
        }

        // The longest run of those pairs in the same order on both sides.
        $tails = [];
        $tailPair = [];
        $previous = [];
        foreach ($pairs as $k => [, $j]) {
            $low = 0;
            $high = count($tails);
            while ($low < $high) {
                $middle = intdiv($low + $high, 2);
                if ($tails[$middle] < $j) {
                    $low = $middle + 1;
                } else {
                    $high = $middle;
                }
            }
            $tails[$low] = $j;
            $tailPair[$low] = $k;
            $previous[$k] = $low > 0 ? $tailPair[$low - 1] : -1;
        }
        $anchors = [];
        for ($k = $tailPair[count($tails) - 1]; $k >= 0; $k = $previous[$k]) {
            $anchors[] = $pairs[$k];
        }

        $hunks = [];
        $at = $from;
        $newAtNow = $newFrom;
        foreach ([...array_reverse($anchors), [$to, $newTo]] as [$i, $j]) {
            $gap = self::diffRange($old, $new, $at, $i, $newAtNow, $j);
            if ($gap === null) {
                return null;
            }
            array_push($hunks, ...$gap);
            $at = $i + 1;
            $newAtNow = $j + 1;
        }

        return $hunks;
    }

    /**
     * @param string[] $old
     * @param string[] $new
     * @return array<int, array{0: int, 1: int, 2: int, 3: int}>
     */
    private static function commonSubsequence(array $old, array $new, int $from, int $to, int $newFrom, int $newTo): array
    {
        $rows = $to - $from;
        $cols = $newTo - $newFrom;
        $width = $cols + 1;
        $common = array_fill(0, ($rows + 1) * $width, 0);
        for ($i = $rows - 1; $i >= 0; $i--) {
            for ($j = $cols - 1; $j >= 0; $j--) {
                $common[$i * $width + $j] = $old[$from + $i] === $new[$newFrom + $j]
                    ? $common[($i + 1) * $width + $j + 1] + 1
                    : max($common[($i + 1) * $width + $j], $common[$i * $width + $j + 1]);
            }
        }

        $hunks = [];
        $open = null;
        $i = 0;
        $j = 0;
        while ($i < $rows || $j < $cols) {
            if ($i < $rows && $j < $cols && $old[$from + $i] === $new[$newFrom + $j]) {
                if ($open !== null) {
                    $hunks[] = [$from + $open[0], $from + $i, $newFrom + $open[1], $newFrom + $j];
                    $open = null;
                }
                $i++;
                $j++;
                continue;
            }
            $open ??= [$i, $j];
            if ($j >= $cols || ($i < $rows && $common[($i + 1) * $width + $j] >= $common[$i * $width + $j + 1])) {
                $i++;
            } else {
                $j++;
            }
        }
        if ($open !== null) {
            $hunks[] = [$from + $open[0], $to, $newFrom + $open[1], $newTo];
        }

        return $hunks;
    }
}
