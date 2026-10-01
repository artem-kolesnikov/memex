<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The owner's presets for memex-writing ({@see MemexWriting}), and the switch
 * between it and the owner's own writing skills: off, memex-writing is not
 * served and says nothing.
 *
 * Closed vocabulary only. A setting picks one main choice per axis and may add
 * add-ons that combine with it; nothing here is free text, so a setting can
 * never carry prose into a shipped skill. Free instructions belong in notes,
 * where they are the owner's and pass the review gate.
 *
 * Stored in the vault's settings as the keys that differ from the defaults:
 * "never chose anything" and "chose every default back" are one value, NULL. A
 * key this class does not know is ignored on read and dropped on the next
 * write.
 */
final class Personalization
{
    /** Each axis's main choices, the default first. */
    public const CHOICES = [
        'scope' => ['one_subject', 'one_idea', 'whole_topic'],
        'opening' => ['summary', 'answer', 'context'],
        'format' => ['mixed', 'prose', 'bullets'],
        'reasoning' => ['reasons', 'bare', 'rationale'],
    ];

    /** Add-ons, each with the axis it belongs to. Off is the default for every one. */
    public const ADD_ONS = [
        'scope_short' => 'scope',
        'format_minimal' => 'format',
        'reasoning_confidence' => 'reasoning',
        'reasoning_sources' => 'reasoning',
    ];

    /** An add-on that contradicts a main choice, and the choices it contradicts. */
    public const CONFLICTS = [
        'scope_short' => ['whole_topic'],
    ];

    /** memex-writing served, or the owner's own writing skills only. */
    public const SWITCHES = ['writing'];

    /** @return array<string, string|bool> */
    public static function defaults(): array
    {
        $out = array_fill_keys(self::SWITCHES, true);
        foreach (self::CHOICES as $axis => $choices) {
            $out[$axis] = $choices[0];
        }
        foreach (self::ADD_ONS as $key => $axis) {
            $out[$key] = false;
        }

        return $out;
    }

    /**
     * A stored blob as a complete set of choices. Anything unrecognised reads
     * as the default, and an add-on stored beside a choice it contradicts
     * reads as off.
     *
     * @return array<string, string|bool>
     */
    public static function read(?array $stored): array
    {
        $out = self::defaults();
        foreach ($stored ?? [] as $key => $value) {
            if (self::valid($key, $value)) {
                $out[$key] = $value;
            }
        }
        foreach (self::CONFLICTS as $addOn => $choices) {
            if ($out[$addOn] === true && in_array($out[self::ADD_ONS[$addOn]], $choices, true)) {
                $out[$addOn] = false;
            }
        }

        return $out;
    }

    /**
     * Merge a PATCH onto what is stored, or explain the refusal. Partial, so a
     * stale tab cannot undo a choice made in another; null puts a key back on
     * its default.
     *
     * @return array{0: array|null, 1: string|null} the blob to store, or null and a reason
     */
    public static function merge(?array $stored, array $patch): array
    {
        if ($patch === []) {
            return [null, 'Nothing to change'];
        }
        $next = self::read($stored);
        $defaults = self::defaults();

        foreach ($patch as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, $defaults)) {
                return [null, sprintf('"%s" is not something you can set', is_string($key) ? $key : gettype($key))];
            }
            if ($value === null) {
                $next[$key] = $defaults[$key];
                continue;
            }
            if (!self::valid($key, $value)) {
                return [null, isset(self::CHOICES[$key])
                    ? sprintf('"%s" must be one of: %s', $key, implode(', ', self::CHOICES[$key]))
                    : sprintf('"%s" must be true or false', $key)];
            }
            $next[$key] = $value;
        }

        foreach (self::CONFLICTS as $addOn => $choices) {
            $axis = self::ADD_ONS[$addOn];
            if ($next[$addOn] === true && in_array($next[$axis], $choices, true)) {
                return [null, sprintf('"%s" does not go with %s "%s"', $addOn, $axis, $next[$axis])];
            }
        }

        return [self::stored($next), null];
    }

    /**
     * The keys that differ from the defaults, or null when none do.
     *
     * @param array<string, string|bool> $settings a complete set, as read() returns
     */
    public static function stored(array $settings): ?array
    {
        $out = [];
        foreach (self::defaults() as $key => $default) {
            if ($settings[$key] !== $default) {
                $out[$key] = $settings[$key];
            }
        }

        return $out === [] ? null : $out;
    }

    /** Which version of the stored blob a tab is editing, so a stale one is refused rather than obeyed. */
    public static function revision(?array $stored): string
    {
        return substr(sha1(json_encode(self::stored(self::read($stored)), JSON_THROW_ON_ERROR)), 0, 12);
    }

    private static function valid(mixed $key, mixed $value): bool
    {
        if (!is_string($key)) {
            return false;
        }
        if (isset(self::CHOICES[$key])) {
            return in_array($value, self::CHOICES[$key], true);
        }

        return (isset(self::ADD_ONS[$key]) || in_array($key, self::SWITCHES, true)) && is_bool($value);
    }
}
