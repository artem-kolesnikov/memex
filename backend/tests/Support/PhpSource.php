<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Every function in a PHP file, with the method names it CALLS.
 *
 * Extracted from {@see \App\Tests\Database\EmbeddingCallSiteGuardTest} when a
 * second guard needed the same reading (2026-09-08). Shared rather than copied
 * because the reason it is a parser and not a substring search is the reason
 * the copy would rot: `token_get_all()` gives a comment a token type of its
 * own, so a guard built on it cannot mistake a citation for a call — and this
 * project has shipped that exact mistake four times.
 *
 * Attribution is to the OUTERMOST enclosing function, so a purchase inside a
 * closure is covered by a check asked at the top of the method that defines it.
 * Nested functions are not reported separately.
 */
final class PhpSource
{
    /** @return array<int, array{name: string, line: int, calls: string[]}> */
    public static function methodsIn(string $file): array
    {
        return self::parseMethods((string) file_get_contents($file));
    }

    /** @return array<int, array{name: string, line: int, calls: string[]}> */
    public static function parseMethods(string $source): array
    {
        $tokens = token_get_all($source);
        $methods = [];

        for ($i = 0, $n = count($tokens); $i < $n; ++$i) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            $name = self::nameAfterFunction($tokens, $i);
            $line = $tokens[$i][2];

            // Walk to the body. An abstract or interface method ends at `;`
            // and has no body to scan.
            $j = $i;
            while ($j < $n && !($tokens[$j] === '{' || $tokens[$j] === ';')) {
                ++$j;
            }
            if ($j >= $n || $tokens[$j] === ';') {
                continue;
            }

            $depth = 0;
            $calls = [];
            for ($k = $j; $k < $n; ++$k) {
                $token = $tokens[$k];
                if ($token === '{') {
                    ++$depth;
                    continue;
                }
                if ($token === '}') {
                    if (--$depth === 0) {
                        break;
                    }
                    continue;
                }
                // `->name(` or `?->name(`. A name in a comment is a T_COMMENT
                // or T_DOC_COMMENT and never reaches here, which is the whole
                // point of parsing rather than searching.
                if (!is_array($token) || $token[0] !== T_STRING) {
                    continue;
                }
                $prev = self::previousMeaningful($tokens, $k);
                if ($prev === null || !is_array($prev) || !in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                    continue;
                }
                $calls[] = $token[1];
            }

            $methods[] = ['name' => $name, 'line' => $line, 'calls' => array_values(array_unique($calls))];

            // Skip past this function's body so nested closures are not
            // reported as functions of their own — their calls already belong
            // to the method above.
            $i = $k ?? $i;
        }

        return $methods;
    }

    /** Every `.php` file under a directory, sorted. @return string[] */
    public static function filesUnder(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** @param array<int, array{0: int, 1: string, 2: int}|string> $tokens */
    private static function nameAfterFunction(array $tokens, int $i): string
    {
        for ($k = $i + 1, $n = count($tokens); $k < $n; ++$k) {
            if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($tokens[$k] === '&') {
                continue;
            }

            return is_array($tokens[$k]) && $tokens[$k][0] === T_STRING ? $tokens[$k][1] : '{closure}';
        }

        return '{closure}';
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private static function previousMeaningful(array $tokens, int $k): array|string|null
    {
        for ($p = $k - 1; $p >= 0; --$p) {
            if (is_array($tokens[$p]) && in_array($tokens[$p][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $tokens[$p];
        }

        return null;
    }
}
