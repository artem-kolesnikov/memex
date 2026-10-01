<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What an account is allowed to store about how memex looks.
 *
 * The values themselves belong to the browser: `public/theme-boot.js` has to
 * paint before any request completes, so it owns the palettes and derives the
 * accent steps. This class is the other half — it decides what may be WRITTEN,
 * because a preference arriving over HTTP is untrusted input like any other and
 * is handed straight back to every one of that person's devices.
 *
 * The three colours below must stay in step with THEMED in theme-boot.js. They
 * are pinned by AppearanceTest so the two cannot drift silently.
 */
final class Appearance
{
    private const THEMES = ['light', 'dark'];

    /**
     * Everything an account may store, all of them hex colours. Everything else
     * on the page is the theme, which is chosen and not tuned — `surface` and
     * `muted` were storable until 2026-08-29 and are refused now, so a stale tab
     * is told rather than quietly writing a preference nothing reads.
     */
    private const KEYS = ['accent', 'link', 'bodyText'];

    /**
     * Six hex digits, nothing else.
     *
     * Not cosmetic strictness: this string is written into a style property on
     * the document element, so anything that is not a colour has no business
     * reaching a browser. Shorthand and named colours are refused rather than
     * expanded — one spelling in the column keeps comparison and the picker's
     * "is this the current one" honest.
     *
     * The anchor is \z rather than $: PCRE's $ also matches BEFORE a trailing
     * newline, so "#123456\\n" was stored verbatim as a colour.
     */
    private const HEX = '/^#[0-9a-fA-F]{6}\z/';

    /**
     * Normalise a stored blob for reading.
     *
     * Anything unrecognised is dropped rather than repaired: a column written
     * by an older or newer version must never be able to put a value the
     * browser will not accept back onto a page.
     */
    public static function read(?array $stored): array
    {
        $out = [];
        foreach (self::KEYS as $which) {
            foreach (self::THEMES as $theme) {
                $value = $stored[$which][$theme] ?? null;
                if (is_string($value) && preg_match(self::HEX, $value) === 1) {
                    $out[$which][$theme] = strtolower($value);
                }
            }
        }

        return $out;
    }

    /**
     * Merge a PATCH body onto what is stored, or explain the refusal.
     *
     * Partial by design — the picker changes one theme's accent at a time, and
     * sending the whole blob back would let a stale tab undo the other theme.
     * Null for a value CLEARS it, which is how Reset travels.
     *
     * @return array{0: array|null, 1: string|null} the new blob, or null and a reason
     */
    public static function merge(?array $stored, array $patch): array
    {
        $next = self::read($stored);

        if ($patch === []) {
            return [null, 'Nothing to change'];
        }

        foreach ($patch as $which => $themes) {
            if (!in_array($which, self::KEYS, true)) {
                return [null, sprintf('"%s" is not something you can set', $which)];
            }
            if (!is_array($themes) || $themes === []) {
                return [null, sprintf('"%s" must name at least one theme', $which)];
            }
            foreach ($themes as $theme => $value) {
                if (!in_array($theme, self::THEMES, true)) {
                    return [null, sprintf('"%s" is not a theme', is_string($theme) ? $theme : gettype($theme))];
                }
                if ($value === null) {
                    unset($next[$which][$theme]);
                    continue;
                }
                if (!is_string($value) || preg_match(self::HEX, $value) !== 1) {
                    return [null, sprintf('The %s colour must be written as #rrggbb', $which)];
                }
                $next[$which][$theme] = strtolower($value);
            }
        }

        // An empty blob is stored as NULL so "never chose anything" and "chose
        // the defaults back" are the same row, and Reset leaves no residue.
        foreach (self::KEYS as $which) {
            if (isset($next[$which]) && $next[$which] === []) {
                unset($next[$which]);
            }
        }

        return [$next === [] ? null : $next, null];
    }
}
