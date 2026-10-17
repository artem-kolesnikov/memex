<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\NotePatch;
use App\Service\NotePatchException;
use App\Service\NoteSections;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Operations that name their place by the note's shape: the end or start of
 * the note, or a section by its heading, so an assistant adding a paragraph
 * quotes nothing.
 */
final class NotePatchSectionsTest extends TestCase
{
    private const NOTE = "Opening paragraph.\n\n## Plan\n\nDig the beds.\n\n### Tools\n\nSpade.\n\n## Costs\n\nAbout 40 euros.\n\n## Sources\n\n- The shop's leaflet.\n";

    private static function patched(array $operations, string $body = self::NOTE): string
    {
        return NotePatch::apply($body, NotePatch::parse($operations));
    }

    public function testAppendGoesAtTheEndOfTheNoteAsItsOwnParagraph(): void
    {
        self::assertSame(
            "Opening.\n\nAdded.\n",
            self::patched([['append' => "Added.\n"]], "Opening.\n"),
        );
    }

    public function testPrependGoesAtTheStartOfTheNote(): void
    {
        self::assertSame("Added.\n\nOpening.\n", self::patched([['prepend' => 'Added.']], "Opening.\n"));
    }

    public function testASectionAppendLandsAfterItsSubsectionsAndBeforeTheNextSection(): void
    {
        self::assertSame(
            "Opening paragraph.\n\n## Plan\n\nDig the beds.\n\n### Tools\n\nSpade.\n\nWater them.\n\n## Costs\n\nAbout 40 euros.\n\n## Sources\n\n- The shop's leaflet.\n",
            self::patched([['section' => 'Plan', 'append' => 'Water them.']]),
        );
    }

    public function testASectionPrependLandsStraightUnderItsHeading(): void
    {
        self::assertSame(
            "Opening paragraph.\n\n## Plan\n\nDig the beds.\n\n### Tools\n\nSpade.\n\n## Costs\n\nRevised on 2026-10-17.\n\nAbout 40 euros.\n\n## Sources\n\n- The shop's leaflet.\n",
            self::patched([['section' => '## Costs', 'prepend' => 'Revised on 2026-10-17.']]),
        );
    }

    public function testASectionReplaceKeepsItsHeadingAndTheSectionsAroundIt(): void
    {
        self::assertSame(
            "Opening paragraph.\n\n## Plan\n\nDig the beds.\n\n### Tools\n\nSpade.\n\n## Costs\n\nAbout 55 euros, with the compost.\n\n## Sources\n\n- The shop's leaflet.\n",
            self::patched([['section' => 'costs', 'replace' => 'About 55 euros, with the compost.']]),
        );
    }

    public function testBeforePlacesANewSectionAboveTheHeading(): void
    {
        self::assertSame(
            "Opening paragraph.\n\n## Plan\n\nDig the beds.\n\n### Tools\n\nSpade.\n\n## Costs\n\nAbout 40 euros.\n\n## Risks\n\nFrost in May.\n\n## Sources\n\n- The shop's leaflet.\n",
            self::patched([['section' => 'Sources', 'before' => "## Risks\n\nFrost in May."]]),
        );
    }

    public function testTheLastSectionRunsToTheEndOfTheNote(): void
    {
        self::assertSame(
            "Opening paragraph.\n\n## Plan\n\nDig the beds.\n\n### Tools\n\nSpade.\n\n## Costs\n\nAbout 40 euros.\n\n## Sources\n\n- The shop's leaflet.\n\n- The neighbour's advice.\n",
            self::patched([['section' => 'Sources', 'append' => "- The neighbour's advice."]]),
        );
    }

    public function testAHeadingInsideACodeBlockIsCodeNotASection(): void
    {
        $body = "## Setup\n\n```bash\n# Install\napt install x\n```\n\n## Install\n\nDone.\n";

        self::assertSame(['Setup', 'Install'], array_column(NoteSections::outline($body), 'heading'));
        self::assertSame(
            "## Setup\n\n```bash\n# Install\napt install x\n```\n\n## Install\n\nDone twice.\n",
            self::patched([['section' => 'Install', 'replace' => 'Done twice.']], $body),
        );
    }

    public function testACyrillicHeadingIsMatchedWithoutRegardToCase(): void
    {
        self::assertSame(
            "## Источники\n\n- Брошюра.\n\n- Сосед.\n",
            self::patched([['section' => 'источники', 'append' => '- Сосед.']], "## Источники\n\n- Брошюра.\n"),
        );
    }

    public function testAHeadingThatAppearsTwiceIsRefused(): void
    {
        $this->expectException(NotePatchException::class);
        $this->expectExceptionMessageMatches('/appears 2 times.*`find`/');

        self::patched([['section' => 'Notes', 'append' => 'x']], "## A\n\n### Notes\n\na\n\n## B\n\n### Notes\n\nb\n");
    }

    public function testAnUnknownHeadingIsRefusedWithTheHeadingsThereAre(): void
    {
        try {
            self::patched([['section' => 'Budget', 'append' => 'x']]);
            self::fail('an unknown heading was accepted');
        } catch (NotePatchException $e) {
            self::assertStringContainsString('Operation 1: No heading “Budget”', $e->getMessage());
            self::assertStringContainsString('“Plan”, “Tools”, “Costs”, “Sources”', $e->getMessage());
        }
    }

    public function testCountReplacesEveryOccurrenceAndOnlyThatMany(): void
    {
        self::assertSame(
            'Call Ann. Ann knows. Ask Ann.',
            self::patched([['find' => 'Anna', 'replace' => 'Ann', 'count' => 3]], 'Call Anna. Anna knows. Ask Anna.'),
        );

        $this->expectException(NotePatchException::class);
        $this->expectExceptionMessageMatches('/appears 3 times in the note, not the 2 `count` says/');
        self::patched([['find' => 'Anna', 'replace' => 'Ann', 'count' => 2]], 'Call Anna. Anna knows. Ask Anna.');
    }

    public function testAFindWithASectionIsLookedForInThatSectionAlone(): void
    {
        $body = "## 2025\n\nBudget: 40 euros.\n\n## 2026\n\nBudget: 40 euros.\n";

        self::assertSame(
            "## 2025\n\nBudget: 40 euros.\n\n## 2026\n\nBudget: 55 euros.\n",
            self::patched([['section' => '2026', 'find' => '40 euros', 'replace' => '55 euros']], $body),
        );

        $this->expectException(NotePatchException::class);
        $this->expectExceptionMessageMatches('/not in the section as it now stands/');
        self::patched([['section' => '2026', 'find' => 'Budget: 30', 'replace' => 'x']], $body);
    }

    /** @return iterable<string, array{list<array<string, mixed>>}> */
    public static function shapes(): iterable
    {
        yield 'append' => [[['append' => 'Added at the end.']]];
        yield 'prepend' => [[['prepend' => 'Added at the start.']]];
        yield 'section append' => [[['section' => 'Plan', 'append' => 'Water them.']]];
        yield 'section prepend' => [[['section' => 'Costs', 'prepend' => 'Revised.']]];
        yield 'section replace' => [[['section' => 'Costs', 'replace' => 'About 55 euros.']]];
        yield 'section emptied' => [[['section' => 'Costs', 'replace' => '']]];
        yield 'before' => [[['section' => 'Sources', 'before' => '## Risks']]];
        yield 'count' => [[['find' => '## ', 'replace' => '##  ', 'count' => 4]]];
        yield 'find in a section' => [[['section' => 'Sources', 'find' => 'leaflet', 'replace' => 'catalogue']]];
        yield 'mixed, in order' => [[
            ['section' => 'Plan', 'append' => 'Water them.'],
            ['find' => 'Spade.', 'replace' => 'Spade and fork.'],
            ['append' => 'Last line.'],
        ]];
    }

    /**
     * What is stored is plain anchors: each occurs once where it applies, and
     * together they give exactly what the operations gave.
     */
    #[DataProvider('shapes')]
    public function testResolvedAnchorsGiveTheSameNote(array $operations): void
    {
        $parsed = NotePatch::parse($operations);
        $resolved = NotePatch::resolve(self::NOTE, $parsed);

        $body = self::NOTE;
        foreach ($resolved as $op) {
            self::assertSame(['find', 'replace'], array_keys($op));
            self::assertSame(1, substr_count($body, $op['find']), 'an anchor that is not unique where it applies');
            $body = str_replace($op['find'], $op['replace'], $body);
        }
        self::assertSame(NotePatch::apply(self::NOTE, $parsed), $body);
    }

    public function testAnOperationThatLeavesTheSectionAsItWasIsRefused(): void
    {
        $this->expectException(NotePatchException::class);
        $this->expectExceptionMessageMatches('/would change nothing/');

        NotePatch::resolve(self::NOTE, NotePatch::parse([['section' => 'Costs', 'replace' => 'About 40 euros.']]));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function malformed(): iterable
    {
        yield 'two places' => [['append' => 'a', 'prepend' => 'b'], 'separate operations'];
        yield 'before without a section' => [['before' => 'a'], '`before` needs the `section`'];
        yield 'replace beside append' => [['section' => 'Plan', 'append' => 'a', 'replace' => 'b'], '`replace` goes with `find` or a `section`'];
        yield 'find beside append' => [['find' => 'a', 'replace' => 'b', 'append' => 'c'], 'goes without `append`'];
        yield 'empty append' => [['append' => "\n"], '`append` must be the text to add'];
        yield 'empty section' => [['section' => ' ', 'append' => 'a'], '`section` must be a heading'];
        yield 'a section alone' => [['section' => 'Plan'], 'each operation needs'];
        yield 'count of zero' => [['find' => 'a', 'replace' => 'b', 'count' => 0], '`count` must be a whole number'];
    }

    #[DataProvider('malformed')]
    public function testAMalformedOperationIsRefusedSayingWhy(array $operation, string $reason): void
    {
        $this->expectException(NotePatchException::class);
        $this->expectExceptionMessage($reason);

        NotePatch::parse([$operation]);
    }
}
