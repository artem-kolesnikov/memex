<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What a held item's comment has to look like, in the words both copies use.
 *
 * The rule is served TWICE on purpose: in the charter, for an agent that loads
 * the curation skill, and in the MCP tool schema, for an assistant that never
 * will and has nothing else to go on. Two copies drift — the charter gained
 * two exceptions on 2026-09-16 and the schema kept saying "nothing else" for
 * the rest of that day — so the clauses that carry the rule live here, the
 * schema is built from them, and {@see \App\Tests\Database\FilingRulesServedTest}
 * refuses a charter that has stopped saying them.
 *
 * Short and atomic on purpose: a clause is a phrase both documents can carry
 * in their own sentence, not a paragraph one of them would have to quote.
 */
final class FilingRules
{
    public const SENTENCE = 'one short sentence per change';
    public const BULLETS = 'one `- ` bullet line per change';
    public const CITED = 'where those links should point instead';
    public const OBSERVATION = "on the comment's last line";
    public const LINE_BREAKS = 'real line breaks rather than the two characters `\n`';

    /**
     * Which clause each served field has to carry.
     *
     * @var array<string, array<string, list<string>>>
     */
    public const SERVED = [
        'propose' => ['comment' => [self::SENTENCE, self::BULLETS, self::OBSERVATION, self::LINE_BREAKS]],
        'propose_delete' => ['reason' => [self::SENTENCE, self::CITED]],
        'propose_merge' => ['comment' => [self::SENTENCE, self::BULLETS]],
    ];

    /** @return list<string> */
    public static function clauses(): array
    {
        return [self::SENTENCE, self::BULLETS, self::CITED, self::OBSERVATION, self::LINE_BREAKS];
    }

    /**
     * Both documents, compared as prose: emphasis and line wrapping are
     * formatting rather than wording, and a clause broken over a wrap in the
     * charter is still the charter saying it.
     */
    public static function says(string $document, string $clause): bool
    {
        return str_contains(self::plain($document), self::plain($clause));
    }

    private static function plain(string $text): string
    {
        return strtolower((string) preg_replace('/\s+/', ' ', str_replace(['*', '_'], '', $text)));
    }
}
