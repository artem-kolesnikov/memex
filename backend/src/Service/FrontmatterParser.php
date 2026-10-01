<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * YAML frontmatter for .md files (uploads + vault import): honors title,
 * tags (array or comma string) and the description VaultExporter writes;
 * malformed frontmatter degrades to whole-file body.
 *
 * `summary` round-trips because the exporter emits it (2026-08-21). Without
 * the reading half, an export-then-import cycle still lost every description —
 * which is the failure the no-lock-in promise is supposed to rule out, just
 * moved one step later.
 *
 * `summary_by` is read back as provenance rather than re-attributed. A note
 * described by somebody's assistant, exported and imported again, was still
 * described by that assistant; claiming the importer wrote it would be a
 * fresher-looking lie than admitting the truth.
 *
 * `created` and `updated` come back as UTC. A value with no zone, the way
 * Obsidian writes its date properties, is read as UTC; a date after now, or
 * anything that is not a date, is dropped.
 */
class FrontmatterParser
{
    public const MAX_METADATA_BYTES = 65536;

    /** @return array{title: string, body: string, tags: string[], summary: ?string, summary_by: ?string, created: ?\DateTimeImmutable, updated: ?\DateTimeImmutable} */
    public function parse(string $content, string $fallbackTitle): array
    {
        $title = $fallbackTitle;
        $tags = [];
        $summary = null;
        $summaryBy = null;
        $created = null;
        $updated = null;
        $body = $content;

        if (preg_match('/\A---\R/', $content, $opening)
            && preg_match('/\R---(?:\R|\z)/', $content, $closing, PREG_OFFSET_CAPTURE, strlen($opening[0]) - 1)) {
            $start = strlen($opening[0]);
            $length = max(0, $closing[0][1] - $start);
            if ($length > self::MAX_METADATA_BYTES) {
                throw new \InvalidArgumentException('Frontmatter metadata exceeds 65536 bytes; shorten the metadata block between --- delimiters.');
            }
            $m = [1 => substr($content, $start, $length), 2 => substr($content, $closing[0][1] + strlen($closing[0][0]))];
            try {
                $meta = Yaml::parse($m[1], Yaml::PARSE_EXCEPTION_ON_ALIAS | Yaml::PARSE_DATETIME);
                if (is_array($meta)) {
                    if (is_string($meta['title'] ?? null) && trim($meta['title']) !== '') {
                        $title = trim($meta['title']);
                    }
                    if (is_array($meta['tags'] ?? null)) {
                        $tags = array_values(array_filter($meta['tags'], 'is_string'));
                    } elseif (is_string($meta['tags'] ?? null)) {
                        $tags = array_values(array_filter(array_map('trim', explode(',', $meta['tags']))));
                    }
                    if (is_string($meta['summary'] ?? null) && trim($meta['summary']) !== '') {
                        $summary = trim($meta['summary']);
                    }
                    if ($summary !== null && is_string($meta['summary_by'] ?? null) && trim($meta['summary_by']) !== '') {
                        $summaryBy = trim($meta['summary_by']);
                    }
                    $created = self::date($meta['created'] ?? null);
                    $updated = self::date($meta['updated'] ?? null);
                    $body = preg_replace('/^\R/', '', $m[2], 1);
                }
            } catch (ParseException $error) {
                if (str_starts_with($error->getMessage(), 'Aliases are disabled')) {
                    throw new \InvalidArgumentException('Frontmatter YAML aliases are not supported; replace references and merges with plain values.', previous: $error);
                }
                if (str_starts_with($error->getMessage(), 'Maximum nesting depth')) {
                    throw new \InvalidArgumentException('Frontmatter nesting exceeds 128 levels; simplify the metadata structure.', previous: $error);
                }
                // Malformed frontmatter: treat the whole file as body.
            }
        }

        return [
            'title' => $title,
            'body' => $body,
            'tags' => $tags,
            'summary' => $summary,
            'summary_by' => $summaryBy,
            'created' => $created,
            'updated' => $updated,
        ];
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        if ($value instanceof \DateTimeInterface) {
            $date = \DateTimeImmutable::createFromInterface($value);
        } elseif (\is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)?(?:Z|[+-]\d{2}:?\d{2})?\z/', trim($value)) === 1) {
            try {
                $date = new \DateTimeImmutable(trim($value), $utc);
            } catch (\Exception) {
                return null;
            }
        } else {
            return null;
        }
        $date = $date->setTimezone($utc);

        return $date > new \DateTimeImmutable('now', $utc) ? null : $date;
    }
}
