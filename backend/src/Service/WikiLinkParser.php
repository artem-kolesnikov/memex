<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Extracts [[wiki-link]] targets from markdown. Pipe syntax [[Target|label]]
 * links to Target. Targets are deduplicated case-insensitively, preserving the
 * first-seen spelling. A target is stored exactly as written, whitespace and
 * all — a line wrap is repaired where the target is RESOLVED
 * ({@see NoteEnricher::resolveTarget()}), not here, because collapsing it at
 * extraction would rename a note genuinely titled with a tab or a double
 * space and lose a link that used to resolve.
 * Code is not linked: fenced blocks and inline code spans are stripped
 * before extraction (vault audit bug — `[[wiki-links]]` in a code
 * span is documentation, not a link).
 */
class WikiLinkParser
{
    /** @return string[] */
    public function extractTargets(string $markdown): array
    {
        $markdown = $this->stripCode($markdown);
        if (!preg_match_all('/\[\[([^\[\]|]+)(?:\|[^\[\]]*)?\]\]/', $markdown, $matches)) {
            return [];
        }

        $targets = [];
        foreach ($matches[1] as $raw) {
            $target = trim($raw);
            if ($target === '' || mb_strlen($target) > 500) {
                continue;
            }
            $key = mb_strtolower($target);
            $targets[$key] ??= $target;
        }

        return array_values($targets);
    }

    private function stripCode(string $markdown): string
    {
        // Fenced blocks first (``` or ~~~, any info string, an unclosed fence
        // runs to end of text), then inline spans — a backtick run closed by
        // an equal-length run, per CommonMark.
        $markdown = preg_replace('/^[ \t]*(`{3,}|~{3,}).*?(?:^[ \t]*\1[ \t]*$|\z)/msu', '', $markdown) ?? $markdown;

        return preg_replace('/(`+)(?:[^`]|(?!\1)`)*?\1/su', '', $markdown) ?? $markdown;
    }
}
