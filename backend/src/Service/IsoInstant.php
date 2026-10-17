<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A moment a caller names, read strictly and kept in UTC, the zone every
 * timestamp in a vault is written in.
 */
final class IsoInstant
{
    /**
     * @param bool $allowDate whether a bare `2026-10-17` is accepted, as the
     *                        start of that day in UTC
     *
     * @throws \InvalidArgumentException naming the field, when the text is not a real moment
     */
    public static function parse(?string $raw, string $field, bool $allowDate = false): ?\DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if ($allowDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            $raw .= 'T00:00:00Z';
        }
        // The shape is checked before the parse, not by it: `createFromFormat`
        // accepts `2026-8-27` for `Y-m-d` and would let a half-padded date
        // through a contract that says it is strict.
        $iso = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';
        if (preg_match($iso, $raw) !== 1) {
            throw new \InvalidArgumentException($field.' must be an ISO-8601 instant, e.g. "2026-08-26T00:00:00Z"'.($allowDate ? ', or a date, e.g. "2026-08-26"' : ''));
        }
        try {
            $at = new \DateTimeImmutable($raw);
        } catch (\Exception) {
            throw new \InvalidArgumentException($field.' is not a real date and time');
        }
        // Month 13 throws; the 31st of February does NOT — PHP rolls it into
        // March and hands back a date nobody asked for. Compared before the
        // zone is applied, so an offset does not move the day out from under
        // the check.
        if ($at->format('Y-m-d\TH:i:s') !== substr($raw, 0, 19)) {
            throw new \InvalidArgumentException($field.' is not a real date and time');
        }

        return $at->setTimezone(new \DateTimeZone('UTC'));
    }
}
