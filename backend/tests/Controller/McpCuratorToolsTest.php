<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

/**
 * `tools/list` withholds curator verbs from an agent token (2026-08-20).
 *
 * **The set shrank to three on 2026-08-26**: curation's
 * four READ verbs are served to every role now, and only `log`, `log_recent`
 * and `resolve_curation_flag` are withheld. The pairing this file protects is
 * unchanged and matters more, not less — a verb can now drift in EITHER
 * direction, and `testTheOpenSetIsActuallyOpen()` covers the new one.
 *
 * Found the hard way. A ChatGPT connector holding an agent token could not see
 * `needs_enrichment` at all, reached for `curation_candidates` instead — which
 * it would have been refused — and reported the whole enrichment workflow as
 * unavailable. The seven curator verbs sat between the two in a list of twenty,
 * and the client had dropped the tail. Everything after the cut was invisible,
 * including `list_skills`, `get_skill`, `list_tags` and `health`.
 *
 * So the length of the list is a correctness concern, not housekeeping, and
 * `CURATOR_TOOLS` is what keeps it short. What rots is the pairing: someone
 * adds a curator verb, guards it with `requireCurator()`, and forgets the
 * constant — and the verb is advertised to agents again, one more entry pushing
 * the useful ones past whatever the client's cut happens to be. Nothing else
 * checks that, so this reads the source and checks it.
 */
final class McpCuratorToolsTest extends TestCase
{
    private const SOURCE = __DIR__.'/../../src/Service/McpServer.php';

    /** @return string[] every verb name passed to requireCurator() */
    private static function guardedVerbs(): array
    {
        preg_match_all(
            "/requireCurator\('([a-z_]+)'\)/",
            (string) file_get_contents(self::SOURCE),
            $m
        );

        return array_values(array_unique($m[1]));
    }

    /** @return string[] the CURATOR_TOOLS constant, read as declared */
    private static function declaredCuratorTools(): array
    {
        $source = (string) file_get_contents(self::SOURCE);
        $start = strpos($source, 'private const CURATOR_TOOLS = [');
        self::assertNotFalse($start, 'CURATOR_TOOLS has been renamed or removed');
        $end = strpos($source, '];', $start);
        preg_match_all("/'([a-z_]+)'/", substr($source, $start, $end - $start), $m);

        return array_values(array_unique($m[1]));
    }

    public function testEveryGuardedVerbIsWithheldFromAgents(): void
    {
        $missing = array_diff(self::guardedVerbs(), self::declaredCuratorTools());

        self::assertSame([], array_values($missing),
            'these verbs refuse an agent token but are still advertised to one: '
            .implode(', ', $missing).'. Each is a menu entry that cannot work AND one more '
            .'row pushing the useful verbs toward the client\'s truncation point.');
    }

    public function testNothingIsWithheldThatAgentsAreAllowedToCall(): void
    {
        // The opposite failure, and the quieter one: a verb listed here but not
        // actually gated disappears from every agent's tool list while working
        // perfectly. `needs_enrichment` is deliberately open to all tokens and
        // is exactly the kind of verb that could be swept in here by habit.
        $overreach = array_diff(self::declaredCuratorTools(), self::guardedVerbs());

        self::assertSame([], array_values($overreach),
            'these are hidden from agents but not curator-gated, so agents lose a verb '
            .'they could have used: '.implode(', ', $overreach));
    }

    public function testTheOpenSetIsActuallyOpen(): void
    {
        // The drift C-10 makes possible: someone adds `requireCurator()` back
        // to a verb that OPEN_CURATION_TOOLS still calls open. Nothing would
        // fail — the verb simply refuses every agent token again, and the
        // silence that improvises a curation pass without a queue is back.
        $source = (string) file_get_contents(self::SOURCE);
        $start = strpos($source, 'private const OPEN_CURATION_TOOLS = [');
        self::assertNotFalse($start, 'OPEN_CURATION_TOOLS has been renamed or removed');
        $end = strpos($source, '];', $start);
        preg_match_all("/'([a-z_]+)'/", substr($source, $start, $end - $start), $m);
        $open = array_values(array_unique($m[1]));

        self::assertNotEmpty($open);
        $closed = array_intersect($open, self::guardedVerbs());
        self::assertSame([], array_values($closed),
            'these are declared open to every role but are curator-gated in code: '
            .implode(', ', $closed));
        $withheld = array_intersect($open, self::declaredCuratorTools());
        self::assertSame([], array_values($withheld),
            'these are declared open to every role but withheld from the tool list: '
            .implode(', ', $withheld));
    }

    public function testTheGuardedSetIsNotEmpty(): void
    {
        // If the regex above ever stops matching — a refactor renames the
        // helper, or the argument stops being a literal — both tests above pass
        // vacuously and this file becomes decoration.
        self::assertNotEmpty(self::guardedVerbs(), 'no requireCurator() calls found: the test can no longer see what it checks');
    }
}
