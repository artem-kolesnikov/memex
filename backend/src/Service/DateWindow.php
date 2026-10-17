<?php

declare(strict_types=1);

namespace App\Service;

/**
 * When a note was created or last changed, as a search narrows it: each
 * `after` includes its moment and each `before` excludes it, so two windows
 * that meet at one instant share no note.
 */
final class DateWindow
{
    /** The fields a caller names them by, in the order they are read. */
    public const FIELDS = ['updated_after', 'updated_before', 'created_after', 'created_before'];

    private function __construct(
        public readonly ?\DateTimeImmutable $updatedAfter,
        public readonly ?\DateTimeImmutable $updatedBefore,
        public readonly ?\DateTimeImmutable $createdAfter,
        public readonly ?\DateTimeImmutable $createdBefore,
    ) {
    }

    /**
     * The window a request's fields describe, or null when they name none.
     *
     * @param array<string, mixed> $fields
     *
     * @throws \InvalidArgumentException when a field is not a moment, or a window closes before it opens
     */
    public static function from(array $fields): ?self
    {
        $at = [];
        foreach (self::FIELDS as $field) {
            $value = $fields[$field] ?? null;
            if ($value !== null && !is_string($value)) {
                throw new \InvalidArgumentException($field.' must be a string, an ISO-8601 instant or a date');
            }
            $at[$field] = IsoInstant::parse($value === null ? null : trim($value), $field, allowDate: true);
        }
        foreach (['updated', 'created'] as $column) {
            $after = $at[$column.'_after'];
            $before = $at[$column.'_before'];
            if ($after !== null && $before !== null && $after >= $before) {
                // Refused rather than answered with nothing: an empty window
                // is a mistake, and "no notes" would read as a fact.
                throw new \InvalidArgumentException($column.'_after must be earlier than '.$column.'_before');
            }
        }
        if (array_filter($at) === []) {
            return null;
        }

        return new self($at['updated_after'], $at['updated_before'], $at['created_after'], $at['created_before']);
    }

    /**
     * SQL conditions on the notes alias `n`, with their parameters, in the
     * text form the vault stores timestamps in.
     *
     * @return array{conditions: list<string>, params: array<string, string>}
     */
    public function sql(): array
    {
        $conditions = [];
        $params = [];
        foreach ([
            'updated_after' => ['n.updated_at >=', $this->updatedAfter],
            'updated_before' => ['n.updated_at <', $this->updatedBefore],
            'created_after' => ['n.created_at >=', $this->createdAfter],
            'created_before' => ['n.created_at <', $this->createdBefore],
        ] as $param => [$comparison, $at]) {
            if ($at !== null) {
                $conditions[] = $comparison.' :'.$param;
                $params[$param] = $at->format('Y-m-d H:i:s');
            }
        }

        return ['conditions' => $conditions, 'params' => $params];
    }
}
