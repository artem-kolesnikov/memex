<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Repairs an agent's free-text field that arrived JSON-escaped.
 *
 * An agent holding a string it means to send as JSON sometimes escapes it
 * twice, and the value memex stores is then the escape sequences themselves:
 * the review inbox showed a proposal whose reason read `lethal to dogs\", and
 * [[Pets]]` with every line break spelled `\n` (2026-09-16). Nothing in the
 * request path can prevent it — the JSON is valid and the field is a string
 * that happens to contain backslashes — so the repair is here, where every
 * writer of a proposal passes and every later reader gets the fixed text.
 *
 * Two guards keep it from rewriting text somebody meant literally, and both
 * cost real repairs to hold:
 *
 * - **A value that already contains a line break is left alone.** Text that
 *   wraps and also says `\n` is prose about escape sequences. This is why a
 *   comment whose quotes ALONE arrived escaped stays as filed.
 * - **Code spans and fences are never touched**, because `\n` inside one is
 *   the subject of the sentence around it.
 *
 * What is left ambiguous is a single line naming a path — `C:\notes` reads as
 * an escaped line break — and that is the right way round for a field that
 * holds prose about a change, where a Windows path outside a code span is far
 * rarer than a botched escape.
 */
final class ProseEscapes
{
    /** Fenced blocks and inline spans, whose contents are the writer's. */
    private const CODE = '```[\s\S]*?```|`[^`\n]*`';

    /** One escape is enough to read the value as JSON that escaped twice. */
    private const ESCAPED = '/\\\\(?:[nrtbf"]|u[0-9a-fA-F]{4})/';

    private const SIMPLE = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f", '"' => '"', '/' => '/', '\\' => '\\'];

    public static function repair(string $text): string
    {
        if (!str_contains($text, '\\') || str_contains($text, "\n") || preg_match(self::ESCAPED, $text) !== 1) {
            return $text;
        }

        // Code and escapes in ONE pass, so a code span is passed through
        // rather than located: collecting the spans first cost an array entry
        // per backtick run, and a 2MB comment of them exhausted the process
        // (Codex, 2026-09-16).
        //
        // A surrogate pair is one alternative rather than two matches: decoded
        // separately its halves are not characters, and json_decode refuses
        // them.
        $pair = 'u[dD][89abAB][0-9a-fA-F]{2}\\\\u[dD][c-fC-F][0-9a-fA-F]{2}';

        return preg_replace_callback(
            '/'.self::CODE.'|\\\\('.$pair.'|u[0-9a-fA-F]{4}|.)/s',
            static function (array $m): string {
                if (!isset($m[1])) {
                    return $m[0];
                }
                if (isset(self::SIMPLE[$m[1]])) {
                    return self::SIMPLE[$m[1]];
                }
                if ($m[1][0] !== 'u') {
                    return $m[0];
                }
                $decoded = json_decode('"\\'.$m[1].'"');

                return is_string($decoded) ? $decoded : $m[0];
            },
            $text,
        ) ?? $text;
    }
}
