<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ProseEscapes;
use PHPUnit\Framework\TestCase;

/**
 * A reason the operator can read, from an agent that escaped it twice.
 *
 * Both failures of this repair are silent, which is why the preservation cases
 * outnumber the repairs here: leaving the escapes in serves `\n` to the inbox,
 * and unescaping too eagerly rewrites text somebody meant literally. Every
 * preservation case below is one a Codex review produced against the first
 * version, which had no notion of a code span (2026-09-16).
 *
 * {@see \App\Tests\Database\ProposalProseTest} is the other half: that the
 * repair is reached from both doors onto the review inbox, and that it touches
 * the prose and not the note.
 */
final class ProseEscapesTest extends TestCase
{
    public function testAWhollyEscapedReasonBecomesTheTextItMeant(): void
    {
        self::assertSame(
            "Xylitol keeps its \"lethal to dogs\" note.\n\nNothing else changes.",
            ProseEscapes::repair('Xylitol keeps its \"lethal to dogs\" note.\n\nNothing else changes.')
        );
    }

    public function testTheWholeJsonEscapeVocabularyComesBack(): void
    {
        self::assertSame("one\ttwo\r\nthree", ProseEscapes::repair('one\ttwo\r\nthree'));
        self::assertSame("a café\nnext", ProseEscapes::repair('a caf\u00e9\nnext'));
        self::assertSame("either / or\nnext", ProseEscapes::repair('either \/ or\nnext'));
    }

    public function testASurrogatePairIsOneCharacterRatherThanTwoBrokenHalves(): void
    {
        // Decoded separately its halves are not characters at all, and the
        // first version of this left them as written.
        self::assertSame("shipped 😀\nnext", ProseEscapes::repair('shipped \ud83d\ude00\nnext'));
    }

    public function testAnEscapedBackslashSurvivesAsOneBackslash(): void
    {
        self::assertSame("C:\\logs\nand more", ProseEscapes::repair('C:\\\\logs\nand more'));
    }

    public function testTextWithNothingEscapedIsUntouched(): void
    {
        foreach (['', 'plain prose', "- one\n- two", 'a path C:\\Users', 'markdown \*not a list\*'] as $value) {
            self::assertSame($value, ProseEscapes::repair($value));
        }
    }

    public function testProseThatWrapsIsNeverRepaired(): void
    {
        // The guard: this text HAS real line breaks, so `\n` in it is the
        // subject rather than a botched one. A comment whose quotes alone
        // arrived escaped stays as filed for the same reason, which is the
        // price of the guard rather than an oversight.
        foreach ([
            "The importer writes \\n between records.\nSee note 12.",
            "Document \\\" and \\n.\nSee note 3.",
        ] as $value) {
            self::assertSame($value, ProseEscapes::repair($value));
        }
    }

    public function testACodeSpanKeepsWhateverIsInsideIt(): void
    {
        foreach ([
            'Use `\n` between records.',
            'Move the cache to `C:\temp\notes`.',
            "Fenced:\n```\nwrite \\n here\n```",
        ] as $value) {
            self::assertSame($value, ProseEscapes::repair($value), 'a code span is the writer\'s');
        }
    }

    public function testTheProseAroundACodeSpanIsStillRepaired(): void
    {
        self::assertSame(
            "Says \"one\" per line.\nThe separator is `\\n`.",
            ProseEscapes::repair('Says \"one\" per line.\nThe separator is `\n`.')
        );
        self::assertSame(
            'Both: `\n` and "quoted" prose.',
            ProseEscapes::repair('Both: `\n` and \"quoted\" prose.')
        );
    }

    public function testALongValueOfCodeSpansIsRepairedWithoutExhaustingTheProcess(): void
    {
        // Two megabytes of backtick runs. Locating the spans before repairing
        // them cost an array entry each and killed the process outright, and
        // the report length cap is applied further down the write than this.
        $value = str_repeat('`a', 1_000_000).'\n';
        $before = memory_get_peak_usage(true);

        $repaired = ProseEscapes::repair($value);

        self::assertSame(str_repeat('`a', 1_000_000)."\n", $repaired);
        self::assertLessThan(64 * 1024 * 1024, memory_get_peak_usage(true) - $before);
    }
}
