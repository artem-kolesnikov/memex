<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Tier 1 of the brief: the settings that cannot break curation, only change
 * what it does.
 *
 * Every field maps to a `curation_candidates` argument or to a claim the
 * journal can check, which is what makes the brief operational rather than
 * prose — a brief saying 20 notes against a run that examined 45 is a
 * measurable deviation, not a matter of opinion.
 */
final class CurationBriefFields
{
    public const NOTES_PER_RUN_MIN = 1;
    public const NOTES_PER_RUN_MAX = 100;
    public const COOLDOWN_MIN = 0;
    public const COOLDOWN_MAX = 365;
    public const MAX_EXCLUDED_TAGS = 25;
    public const MAX_TAG_LENGTH = 60;

    public const BOLDNESS = ['conservative', 'balanced', 'bold'];
    public const REPORT_BACK = ['examined', 'changed', 'filed', 'skipped', 'observations'];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'notes_per_run' => 20,
            'work_first' => '',
            'cooldown_days' => CurationQueue::DEFAULT_COOLDOWN_DAYS,
            'never_touch_tags' => [],
            'boldness' => 'balanced',
            'report_back' => ['examined', 'changed', 'filed'],
        ];
    }

    /**
     * Coerce whatever arrived into a valid field set, falling back to the
     * default for anything unrecognised. Nothing here rejects: a brief is a
     * settings row, and a request that half-parses should leave the user with a
     * working brief rather than a 400 and no curation.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $raw): array
    {
        $out = self::defaults();

        if (isset($raw['notes_per_run']) && is_numeric($raw['notes_per_run'])) {
            $out['notes_per_run'] = max(self::NOTES_PER_RUN_MIN, min(self::NOTES_PER_RUN_MAX, (int) $raw['notes_per_run']));
        }
        if (isset($raw['cooldown_days']) && is_numeric($raw['cooldown_days'])) {
            $out['cooldown_days'] = max(self::COOLDOWN_MIN, min(self::COOLDOWN_MAX, (int) $raw['cooldown_days']));
        }
        if (is_string($raw['work_first'] ?? null) && in_array($raw['work_first'], CurationQueue::reasons(), true)) {
            $out['work_first'] = $raw['work_first'];
        }
        if (is_string($raw['boldness'] ?? null) && in_array($raw['boldness'], self::BOLDNESS, true)) {
            $out['boldness'] = $raw['boldness'];
        }
        if (is_array($raw['never_touch_tags'] ?? null)) {
            $tags = [];
            foreach ($raw['never_touch_tags'] as $tag) {
                if (!is_string($tag)) {
                    continue;
                }
                $tag = mb_substr(trim($tag), 0, self::MAX_TAG_LENGTH);
                if ($tag !== '' && !in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                }
            }
            $out['never_touch_tags'] = array_slice($tags, 0, self::MAX_EXCLUDED_TAGS);
        }
        if (is_array($raw['report_back'] ?? null)) {
            $out['report_back'] = array_values(array_intersect(self::REPORT_BACK, array_filter(
                $raw['report_back'],
                static fn (mixed $v): bool => is_string($v),
            )));
        }

        return $out;
    }
}
