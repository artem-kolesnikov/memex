<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A note's properties: named facts beside its text, the YAML frontmatter
 * fields of Obsidian and Basic Memory (`status: active`, `due: 2026-11-01`)
 * (operator, 2026-10-17). Whoever writes the note writes them: an assistant
 * through `propose`, an imported file through its frontmatter, the owner in
 * the editor. Enrichment never fills them, because a guessed `due` is a wrong
 * fact a filter would trust.
 *
 * A set is a name-to-value map in the order written. A value is a string, a
 * number, true or false, null (a property left empty, as Obsidian writes
 * one), a list or a map of these. A change set is the same map read as an
 * edit: each name sent is set, null removes it, and a name not sent is kept.
 */
final class NoteProperties
{
    /** The JSON a note's properties may take, the same bound as the frontmatter they come from. */
    public const MAX_BYTES = FrontmatterParser::MAX_METADATA_BYTES;
    public const MAX_NAME_CHARS = 64;
    private const MAX_DEPTH = 16;

    /**
     * Frontmatter keys that are the note's own fields, read on import or
     * written by export, and so never properties.
     */
    public const RESERVED = ['title', 'tags', 'aliases', 'summary', 'summary_by', 'source_url', 'created', 'updated'];

    /**
     * The properties a file's frontmatter carries: every key that is not one
     * of the note's own fields. Lenient, as import is with the rest of the
     * frontmatter: a name or value memex cannot keep is dropped, not refused.
     *
     * An export made before properties existed wrote memex's own `source` and
     * `status` lines, which import never read; they are recognised by their
     * values together and left out, so moving such an export in does not give
     * every note two properties nobody wrote.
     *
     * @param array<mixed> $meta the parsed frontmatter
     * @return array<string, mixed>
     */
    public static function fromFrontmatter(array $meta): array
    {
        $oldExport = \in_array($meta['source'] ?? null, \App\Entity\Note::SOURCES, true)
            && \in_array($meta['status'] ?? null, [\App\Entity\Note::STATUS_VERIFIED, \App\Entity\Note::STATUS_PENDING], true);
        $properties = [];
        foreach ($meta as $name => $value) {
            $name = (string) $name;
            if (\in_array($name, self::RESERVED, true) || ($oldExport && \in_array($name, ['source', 'status'], true))
                || self::nameProblem($name) !== null) {
                continue;
            }
            try {
                $properties[$name] = self::value($value, 0);
            } catch (\InvalidArgumentException) {
            }
        }

        return self::bounded($properties) ? $properties : [];
    }

    /**
     * A whole set as a caller sent it, checked strictly: the owner's editor and
     * the REST API, where a value memex cannot keep is an error to report.
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
     */
    public static function set(mixed $value): array
    {
        return self::map($value);
    }

    /**
     * A change set as an assistant sent it: null removes a property. Null when
     * it changes nothing.
     *
     * @return array<string, mixed>|null
     * @throws \InvalidArgumentException
     */
    public static function changes(mixed $value): ?array
    {
        $changes = self::map($value);

        return $changes === [] ? null : $changes;
    }

    /**
     * $current with $changes laid on it: a name set keeps its place, a new one
     * is added at the end, a null removes it.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public static function apply(array $current, array $changes): array
    {
        foreach ($changes as $name => $value) {
            if ($value === null) {
                unset($current[$name]);
            } else {
                $current[$name] = $value;
            }
        }

        return $current;
    }

    /**
     * The change set that turns $from into $to: what an owner's whole set
     * means as an edit.
     *
     * @param array<string, mixed> $from
     * @param array<string, mixed> $to
     * @return array<string, mixed>
     */
    public static function diff(array $from, array $to): array
    {
        $changes = [];
        foreach ($to as $name => $value) {
            if (!\array_key_exists($name, $from) || !self::same($from[$name], $value)) {
                $changes[$name] = $value;
            }
        }
        foreach (array_keys($from) as $name) {
            if (!\array_key_exists($name, $to)) {
                $changes[$name] = null;
            }
        }

        return $changes;
    }

    /** Two values, or two sets, alike in everything but the order of a set's names. */
    public static function same(mixed $a, mixed $b): bool
    {
        return self::canonical($a) === self::canonical($b);
    }

    /** A set as stored: null when empty. */
    public static function stored(array $properties): ?array
    {
        return $properties === [] ? null : $properties;
    }

    /** What the API sends for a set: an object, also when empty. */
    public static function json(?array $properties): object|array
    {
        return $properties === null || $properties === [] ? new \stdClass() : $properties;
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (\is_object($value)) {
            $value = (array) $value;
        }
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \InvalidArgumentException('properties must be an object of names and values, such as {"status": "active"}');
        }
        $out = [];
        foreach ($value as $name => $item) {
            $name = (string) $name;
            $problem = self::nameProblem($name);
            if ($problem !== null) {
                throw new \InvalidArgumentException('Property “'.mb_substr($name, 0, 80).'”: '.$problem);
            }
            // Null is a property left empty in a set, as Obsidian keeps one,
            // and a removal in a change set.
            $out[$name] = self::value($item, 0);
        }
        if (!self::bounded($out)) {
            throw new \InvalidArgumentException('properties exceed '.(self::MAX_BYTES / 1024).' KB; keep long text in the note body');
        }

        return $out;
    }

    private static function nameProblem(string $name): ?string
    {
        if ($name !== trim($name) || $name === '' || mb_strlen($name) > self::MAX_NAME_CHARS) {
            return 'a name is 1-'.self::MAX_NAME_CHARS.' characters with no space at either end';
        }
        if (preg_match('/[.\"\p{Cc}]/u', $name) === 1 || str_starts_with($name, '$')) {
            return 'a name has no dots, quotation marks or control characters and does not start with $ (a dot reaches inside a value in a search filter)';
        }
        if (ctype_digit($name)) {
            return 'a name is not a number alone';
        }
        if (\in_array($name, self::RESERVED, true)) {
            return '`'.$name.'` is the note\'s own field, not a property; the reserved names are '.implode(', ', self::RESERVED);
        }

        return null;
    }

    private static function value(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('a property value nests deeper than '.self::MAX_DEPTH.' levels');
        }
        if ($value === null || \is_string($value) || \is_bool($value) || \is_int($value)) {
            return $value;
        }
        if (\is_float($value)) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException('a property number must be finite');
            }

            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return self::date($value);
        }
        if (\is_object($value)) {
            $value = (array) $value;
        }
        if (\is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::value($item, $depth + 1);
            }

            return $out;
        }

        throw new \InvalidArgumentException('a property value is text, a number, true or false, a list or an object');
    }

    /**
     * A date YAML read as a timestamp, back as the text it was: the day alone
     * when it carries no time, so `due: 2026-11-01` stays that and compares
     * with other days as text does.
     */
    private static function date(\DateTimeInterface $date): string
    {
        $date = \DateTimeImmutable::createFromInterface($date);
        if ($date->format('H:i:s.u') === '00:00:00.000000' && $date->getOffset() === 0) {
            return $date->format('Y-m-d');
        }

        return $date->getOffset() === 0 ? $date->format('Y-m-d\TH:i:s\Z') : $date->format('Y-m-d\TH:i:sP');
    }

    private static function bounded(array $properties): bool
    {
        return \strlen(json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)) <= self::MAX_BYTES;
    }

    private static function canonical(mixed $value): string
    {
        $sort = static function (mixed $v) use (&$sort): mixed {
            if (!\is_array($v)) {
                return $v;
            }
            if (!array_is_list($v)) {
                ksort($v, SORT_STRING);
            }

            return array_map($sort, $v);
        };

        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
