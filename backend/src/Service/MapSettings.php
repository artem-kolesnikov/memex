<?php

declare(strict_types=1);

namespace App\Service;

/**
 * How an account wants the map drawn.
 *
 * The same shape as {@see Appearance}: a small closed vocabulary, validated
 * here because a preference arriving over HTTP is untrusted input and is handed
 * straight back to every one of that person's devices. The values are names,
 * not numbers — nothing here reaches a style property, and a renderer that met
 * a word it did not know would silently draw nothing.
 *
 * The vocabulary is pinned by MapSettingsTest against the frontend's own union
 * types, so a name added on one side and not the other fails rather than
 * quietly storing a setting no renderer reads.
 */
final class MapSettings
{
    /** Every key, with the words it accepts. The first of each is the default. */
    private const ALLOWED = [
        'view' => ['2d', '3d'],
        'nodeShape' => ['dot', 'square'],
        'links' => ['curve', 'line', 'arrow'],
        'labels' => ['title', 'id', 'none'],
    ];

    /**
     * Normalise a stored blob for reading.
     *
     * Anything unrecognised is dropped rather than repaired, so a column
     * written by an older or newer version cannot put a word this renderer does
     * not know onto a canvas. What is missing is the default, which is why the
     * defaults live in the SPA and not here: an absent key has to mean the same
     * thing to a browser that never asked the server.
     */
    public static function read(?array $stored): array
    {
        $out = [];
        foreach (self::ALLOWED as $key => $words) {
            $value = $stored[$key] ?? null;
            if (is_string($value) && in_array($value, $words, true)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Merge a PATCH body onto what is stored, or explain the refusal.
     *
     * Partial like the appearance picker: one control changes at a time, and
     * sending the whole blob back would let a stale tab undo a choice made in
     * another tab. Null for a value CLEARS it, putting that control back on its
     * default.
     *
     * @return array{0: array|null, 1: string|null} the new blob, or null and a reason
     */
    public static function merge(?array $stored, array $patch): array
    {
        $next = self::read($stored);

        if ($patch === []) {
            return [null, 'Nothing to change'];
        }

        foreach ($patch as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, self::ALLOWED)) {
                return [null, sprintf('"%s" is not something you can set', is_string($key) ? $key : gettype($key))];
            }
            if ($value === null) {
                unset($next[$key]);
                continue;
            }
            if (!is_string($value) || !in_array($value, self::ALLOWED[$key], true)) {
                return [null, sprintf(
                    '"%s" must be one of: %s',
                    $key,
                    implode(', ', self::ALLOWED[$key]),
                )];
            }
            $next[$key] = $value;
        }

        // An empty blob is stored as NULL, so "never chose anything" and "chose
        // every default back" are the same row.
        return [$next === [] ? null : $next, null];
    }

    /** @return array<string, list<string>> */
    public static function vocabulary(): array
    {
        return self::ALLOWED;
    }
}
