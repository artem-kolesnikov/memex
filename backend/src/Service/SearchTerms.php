<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A note's text as the keyword index (`notes_terms`) reads it, and a query
 * word as the same index is asked for it, so that keyword search works in
 * languages FTS5's tokenizer does not split or stem.
 *
 * - Scripts written without spaces (Chinese, Japanese, Korean, Thai, Lao,
 *   Khmer, Myanmar, Tibetan) have no words for the tokenizer to find: a
 *   whole sentence is one token, and a word inside it never matches. A run
 *   of them is indexed as its overlapping pairs where it stands, and a
 *   query run is asked for as the phrase of its pairs, so "价格" finds
 *   "我们的价格表" and a quoted "회의 기록" finds the two words side by side.
 *   Each run's single characters follow at the end of the text, so a
 *   one-character query still matches inside a run. A character keeps its
 *   vowel and tone marks, so the Thai "ปู" never matches the "ป" of "ปลา".
 * - A word written in Cyrillic letters is indexed and asked for as its
 *   Russian stem ({@see RussianStem}), so "договор" finds "договора".
 * - Everything else passes through untouched and is tokenized exactly as
 *   `notes_fts` tokenizes it, English stemming included.
 *
 * Both sides go through the same functions, so whatever the index holds is
 * what a query asks for.
 */
final class SearchTerms
{
    /** The scripts written without spaces between words, as Basic Memory lists them. */
    private const UNSPACED = '\x{0E00}-\x{0EFF}\x{0F00}-\x{0FFF}\x{1000}-\x{109F}\x{1100}-\x{11FF}\x{1780}-\x{17FF}'
        .'\x{19E0}-\x{19FF}\x{3005}-\x{3007}\x{3021}-\x{3029}\x{3031}-\x{3035}\x{3038}-\x{303B}\x{3040}-\x{30FF}'
        .'\x{3100}-\x{318F}\x{31A0}-\x{31BF}\x{31F0}-\x{31FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{A960}-\x{A97F}'
        .'\x{A9E0}-\x{A9FF}\x{AA60}-\x{AA7F}\x{AC00}-\x{D7AF}\x{D7B0}-\x{D7FF}\x{F900}-\x{FAFF}\x{1AFF0}-\x{1AFFF}'
        .'\x{1B000}-\x{1B16F}\x{20000}-\x{2FFFF}\x{30000}-\x{323AF}';

    private const RUN = '/(['.self::UNSPACED.']\p{M}*(?:['.self::UNSPACED.']\p{M}*|[\x{200C}\x{200D}])*)/u';

    private const WORD = '/(?:(?!['.self::UNSPACED.'])[\p{L}\p{N}\p{M}])+/u';

    private const STEM_CACHE = 4096;

    /** @var array<string, string> */
    private static array $stems = [];

    /** The text as the index holds it. */
    public static function index(string $text): string
    {
        $segments = preg_split(self::RUN, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($segments === false) {
            return $text;
        }
        $out = '';
        $characters = [];
        foreach ($segments as $segment) {
            if (preg_match(self::RUN, $segment) === 1) {
                $units = self::units($segment);
                $out .= ' '.implode(' ', count($units) === 1 ? $units : self::pairs($units)).' ';
                if (count($units) > 1) {
                    array_push($characters, ...$units);
                }
                continue;
            }
            $out .= preg_replace_callback(
                self::WORD,
                static fn (array $m): string => self::isCyrillic($m[0]) ? self::stem($m[0]) : $m[0],
                $segment,
            ) ?? $segment;
        }

        return $characters === [] ? $out : $out.' '.implode(' ', $characters);
    }

    /**
     * One query word as the phrases the index is asked for, all of them
     * required: a word mixing scripts ("iPhone价格") is a phrase per script,
     * since the index keeps a run's pairs apart from the word beside it, and
     * the rest of the word stays one phrase as typed ("memex-oss").
     *
     * Each phrase carries what it looks like in the note's own text, for
     * marking the passage a match was found in: a Cyrillic word by its stem as
     * a prefix, so every form of it is marked, and a run of an unspaced script
     * as nothing, since the text holds it inside a longer token.
     *
     * @return list<array{match: string, passages: list<string>}>
     */
    public static function query(string $word): array
    {
        $phrases = [];
        foreach (preg_split(self::RUN, $word, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $segment) {
            if (preg_match(self::RUN, $segment) === 1) {
                $units = self::units($segment);
                $phrases[] = ['match' => implode(' ', count($units) === 1 ? $units : self::pairs($units)), 'passages' => []];
                continue;
            }
            if (preg_match('/[\p{L}\p{N}]/u', $segment) !== 1) {
                continue;
            }
            $stems = [];
            $match = preg_replace_callback(self::WORD, static function (array $m) use (&$stems): string {
                if (!self::isCyrillic($m[0])) {
                    return $m[0];
                }

                return $stems[] = self::stem($m[0]);
            }, $segment) ?? $segment;
            $phrases[] = [
                'match' => $match,
                'passages' => $stems === [] ? [self::quote($segment)] : array_map(static fn (string $s): string => self::quote($s).'*', $stems),
            ];
        }

        return $phrases;
    }

    /** An FTS5 string: whatever it holds is text for the tokenizer, never syntax. */
    public static function quote(string $text): string
    {
        return '"'.str_replace('"', '""', $text).'"';
    }

    private static function isCyrillic(string $word): bool
    {
        return preg_match('/\A[\p{Cyrillic}\p{M}]*\p{Cyrillic}[\p{Cyrillic}\p{M}]*\z/u', $word) === 1;
    }

    /** The Russian stem, with й and ё composed and stress marks dropped first. */
    private static function stem(string $word): string
    {
        if (isset(self::$stems[$word])) {
            return self::$stems[$word];
        }
        $plain = preg_replace('/\p{M}/u', '', str_replace(
            ["и\u{0306}", "И\u{0306}", "е\u{0308}", "Е\u{0308}"],
            ['й', 'Й', 'ё', 'Ё'],
            $word,
        )) ?? $word;
        if (count(self::$stems) >= self::STEM_CACHE) {
            self::$stems = [];
        }

        return self::$stems[$word] = RussianStem::stem($plain);
    }

    /**
     * A run's characters, each with its combining marks. The tokenizer splits
     * on marks, so each one is written as a private-use character, which the
     * tokenizer keeps inside the token: a pair stays one token, and a vowel
     * or tone mark still tells two words apart.
     *
     * @return list<string>
     */
    private static function units(string $run): array
    {
        preg_match_all('/(?!\p{M})['.self::UNSPACED.']\p{M}*/u', $run, $m);

        return array_map(static fn (string $unit): string => preg_replace_callback(
            '/\p{M}/u',
            static fn (array $mark): string => mb_chr(0xE000 + (mb_ord($mark[0]) & 0xFFF)),
            $unit,
        ) ?? $unit, $m[0]);
    }

    /**
     * @param list<string> $units
     * @return list<string>
     */
    private static function pairs(array $units): array
    {
        $pairs = [];
        for ($i = 1, $n = count($units); $i < $n; ++$i) {
            $pairs[] = $units[$i - 1].$units[$i];
        }

        return $pairs;
    }
}
