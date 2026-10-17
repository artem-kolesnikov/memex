<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Which notes a search keeps by their properties ({@see NoteProperties}), in
 * Basic Memory's filter syntax so an assistant that knows one knows the other
 * (operator, 2026-10-17). Every name must match:
 *
 * - `{"status": "active"}` the value; a list property matches when it holds it
 * - `{"topics": ["a", "b"]}` a list property holding every one of them
 * - `{"priority": {"$in": ["high", "critical"]}}` any one of them
 * - `{"due": {"$lt": "2026-11-01"}}`, `$gt`, `$gte`, `$lte`: numbers by value,
 *   text (ISO dates included) in alphabetical order
 * - `{"score": {"$between": [0.3, 0.8]}}` both ends included
 * - `{"client.name": "Acme"}` a value inside an object property
 */
final class PropertyFilter
{
    private const OPERATORS = ['$in', '$gt', '$gte', '$lt', '$lte', '$between'];
    private const MAX_NAMES = 20;

    /** @param list<array{path: string, test: string, values: list<string|int|float|bool>}> $conditions */
    private function __construct(private readonly array $conditions)
    {
    }

    /**
     * The filter a caller sent, or null when it names nothing.
     *
     * @throws \InvalidArgumentException naming the name and what is wrong with it
     */
    public static function from(mixed $filters): ?self
    {
        if ($filters === null || $filters === []) {
            return null;
        }
        if (\is_object($filters)) {
            $filters = (array) $filters;
        }
        if (!\is_array($filters) || array_is_list($filters)) {
            throw new \InvalidArgumentException('properties must be an object of property names and the values to match, such as {"status": "active"}');
        }
        if (\count($filters) > self::MAX_NAMES) {
            throw new \InvalidArgumentException('properties may filter on at most '.self::MAX_NAMES.' names at once');
        }
        $conditions = [];
        foreach ($filters as $name => $wanted) {
            $name = (string) $name;
            $path = self::path($name);
            foreach (self::tests($name, $wanted) as [$test, $values]) {
                $conditions[] = ['path' => $path, 'test' => $test, 'values' => $values];
            }
        }

        return new self($conditions);
    }

    /**
     * SQL conditions on the notes alias `n`, with their parameters.
     *
     * Each is an EXISTS over the value's items: json_each on a list walks its
     * items and on a single value yields that value, so one condition serves
     * both. An object is never matched as a whole.
     *
     * @return array{conditions: list<string>, params: array<string, string>}
     */
    public function sql(): array
    {
        $conditions = [];
        $params = [];
        $types = [];
        foreach ($this->conditions as $i => ['path' => $path, 'test' => $test, 'values' => $values]) {
            $params["prop{$i}"] = $path;
            $tests = [];
            foreach ($values as $j => $value) {
                $param = "prop{$i}v{$j}";
                if (\is_bool($value)) {
                    $tests[] = "pj{$i}.type = '".($value ? 'true' : 'false')."'";
                    continue;
                }
                $params[$param] = \is_string($value) ? $value : json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
                $tests[] = \is_string($value)
                    ? "(pj{$i}.type = 'text' AND pj{$i}.value ".self::comparison($test, $j)." :{$param})"
                    : "(pj{$i}.type IN ('integer', 'real') AND pj{$i}.value ".self::comparison($test, $j)." CAST(:{$param} AS REAL))";
            }
            // Both ends of a range are met by the same item; any of `$in`'s.
            $joined = implode($test === 'between' ? ' AND ' : ' OR ', $tests);
            $conditions[] = "(json_type(n.properties, :prop{$i}) IS NOT 'object' AND EXISTS (SELECT 1 FROM json_each(n.properties, :prop{$i}) pj{$i} WHERE pj{$i}.type NOT IN ('object', 'array') AND ({$joined})))";
        }

        return ['conditions' => $conditions, 'params' => $params];
    }

    private static function comparison(string $test, int $index): string
    {
        return match ($test) {
            'gt' => '>',
            'gte' => '>=',
            'lt' => '<',
            'lte' => '<=',
            'between' => $index === 0 ? '>=' : '<=',
            default => '=',
        };
    }

    /** The JSON path a dotted name reaches. */
    private static function path(string $name): string
    {
        $segments = explode('.', $name);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment !== trim($segment) || preg_match('/[\"\p{Cc}]/u', $segment) === 1) {
                throw new \InvalidArgumentException('properties: “'.mb_substr($name, 0, 80).'” is not a property name; a dot reaches a value inside an object property, as in "client.name"');
            }
        }

        return '$'.implode('', array_map(static fn (string $s): string => '."'.$s.'"', $segments));
    }

    /**
     * What one name must satisfy, as tests ANDed together: each is a kind of
     * comparison and the values it compares with.
     *
     * @return list<array{0: string, 1: list<string|int|float|bool>}>
     */
    private static function tests(string $name, mixed $wanted): array
    {
        $where = 'properties.'.$name;
        if (self::scalar($wanted)) {
            return [['eq', [$wanted]]];
        }
        if (\is_object($wanted)) {
            $wanted = (array) $wanted;
        }
        if (!\is_array($wanted) || $wanted === []) {
            throw new \InvalidArgumentException($where.': give a value, a list of values, or an operator object such as {"$in": [...]}; '.(null === $wanted ? 'null matches nothing' : 'an empty one matches nothing'));
        }
        if (array_is_list($wanted)) {
            $values = self::scalars($where, $wanted);

            return array_map(static fn ($value): array => ['eq', [$value]], $values);
        }
        if (\count($wanted) !== 1) {
            throw new \InvalidArgumentException($where.': an operator object holds exactly one operator, one of '.implode(', ', self::OPERATORS));
        }
        $operator = (string) array_key_first($wanted);
        $operand = $wanted[$operator];
        if (!\in_array($operator, self::OPERATORS, true)) {
            throw new \InvalidArgumentException($where.': unknown operator “'.$operator.'”; use one of '.implode(', ', self::OPERATORS).', with the $');
        }
        if ($operator === '$in') {
            if (!\is_array($operand) || $operand === [] || !array_is_list($operand)) {
                throw new \InvalidArgumentException($where.': $in takes a non-empty list of values');
            }

            return [['eq', self::scalars($where, $operand)]];
        }
        if ($operator === '$between') {
            if (!\is_array($operand) || \count($operand) !== 2 || !array_is_list($operand)) {
                throw new \InvalidArgumentException($where.': $between takes exactly [low, high]');
            }
            [$low, $high] = [self::comparable($where, $operand[0]), self::comparable($where, $operand[1])];
            if (\is_string($low) !== \is_string($high)) {
                throw new \InvalidArgumentException($where.': $between compares two numbers or two texts, not one of each');
            }

            return [['between', [$low, $high]]];
        }

        return [[substr($operator, 1), [self::comparable($where, $operand)]]];
    }

    private static function scalar(mixed $value): bool
    {
        return \is_string($value) || \is_int($value) || \is_bool($value) || (\is_float($value) && is_finite($value));
    }

    /** @return list<string|int|float|bool> */
    private static function scalars(string $where, array $values): array
    {
        foreach ($values as $value) {
            if (!self::scalar($value)) {
                throw new \InvalidArgumentException($where.': a value to match is text, a number or true/false');
            }
        }

        return array_values($values);
    }

    private static function comparable(string $where, mixed $value): string|int|float
    {
        if (\is_string($value) || \is_int($value) || (\is_float($value) && is_finite($value))) {
            return $value;
        }

        throw new \InvalidArgumentException($where.': a comparison takes a number, or a text such as a date written 2026-11-01');
    }
}
