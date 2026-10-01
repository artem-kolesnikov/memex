<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\LineDiff;
use PHPUnit\Framework\TestCase;

/**
 * The diff engine, tested the only way a diff engine can honestly be tested:
 * by round-tripping it against text it was not written for.
 *
 * A diff that is merely *plausible* is the worst kind of bug this codebase
 * could ship — it silently corrupts the previous versions of somebody's notes,
 * which are the thing nobody looks at until the day they need it. Hand-picked
 * cases cannot find that. Randomised round-trips can, and did: the first
 * version of `backtrack()` passed several hand-written cases and emitted
 * patches that would not apply to their own source.
 */
final class LineDiffTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function pairs(): iterable
    {
        yield 'both empty' => ['', ''];
        yield 'identical' => ["a\nb\nc", "a\nb\nc"];
        yield 'empty to content' => ['', 'a'];
        yield 'content to empty' => ['a', ''];
        yield 'one line changed' => ["a\nb\nc", "a\nB\nc"];
        yield 'appended' => ["a\nb\nc", "a\nb\nc\nd"];
        yield 'prepended' => ["a\nb", "z\na\nb"];
        yield 'deleted in the middle' => ["a\nb\nc\nd", "a\nc\nd"];
        yield 'two distant edits' => ["1\n2\n3\n4\n5\n6\n7", "X\n2\n3\n4\n5\n6\nY"];
        yield 'blank lines matter' => ["one\n\nthree\n", "one\ntwo\nthree\n"];
        // The one everybody gets wrong: a trailing newline is a real,
        // load-bearing difference, and markdown notes have them.
        yield 'trailing newline gained' => ['trailing', "trailing\n"];
        yield 'trailing newline lost' => ["trailing\n", 'trailing'];
        yield 'alternating' => ["a\nb\na\nb\na", "b\na\nb\na\nb"];
        yield 'total replacement' => ['x', "completely\ndifferent\ntext"];
        yield 'unicode survives' => ["héllo\nwörld", "héllo\nwörld — changed"];
    }

    /** @dataProvider pairs */
    public function testAPatchReconstructsItsTargetExactly(string $from, string $to): void
    {
        self::assertSame($to, LineDiff::apply($from, LineDiff::diff($from, $to)));
    }

    /**
     * 3,000 randomly generated edits, from a fixed seed so a failure is
     * reproducible rather than a story about a build that once went red.
     */
    public function testRandomEditsAlwaysRoundTrip(): void
    {
        mt_srand(20260823);
        $vocabulary = ['alpha', 'beta', 'gamma', '', 'delta epsilon', '## A heading', '- a list item'];

        for ($case = 0; $case < 3000; ++$case) {
            $lines = [];
            for ($i = 0, $n = mt_rand(0, 14); $i < $n; ++$i) {
                $lines[] = $vocabulary[mt_rand(0, count($vocabulary) - 1)];
            }
            $from = implode("\n", $lines);

            for ($e = 0, $edits = mt_rand(0, 6); $e < $edits; ++$e) {
                $what = mt_rand(0, 2);
                if ($what === 0 && $lines !== []) {
                    array_splice($lines, mt_rand(0, count($lines) - 1), 1);
                } elseif ($what === 1) {
                    array_splice($lines, mt_rand(0, count($lines)), 0, ['zeta '.mt_rand(0, 3)]);
                } elseif ($lines !== []) {
                    $lines[mt_rand(0, count($lines) - 1)] = 'changed '.mt_rand(0, 3);
                }
            }
            $to = implode("\n", $lines);

            self::assertSame(
                $to,
                LineDiff::apply($from, LineDiff::diff($from, $to)),
                sprintf('Case %d did not round-trip: %s -> %s', $case, json_encode($from), json_encode($to))
            );
        }
    }

    public function testTheOneParagraphEditIsTheCaseThisExistsFor(): void
    {
        // The shape that motivated the whole change: an unattended pass
        // rewording part of a long note. If this ever stops being tiny the
        // storage saving has gone, and nobody would otherwise notice.
        $lines = array_map(static fn (int $i): string => "Line $i of a long operational runbook.", range(1, 1200));
        $from = implode("\n", $lines);
        $lines[600] = 'This paragraph was rewritten overnight.';
        $to = implode("\n", $lines);

        $ops = LineDiff::diff($from, $to);

        self::assertSame($to, LineDiff::apply($from, $ops));
        self::assertLessThan(
            strlen($to) / 100,
            strlen((string) json_encode($ops)),
            'A one-line edit to a 1,200-line note must cost far less than one percent of the note'
        );
    }

    public function testAnEditAtBothEndsIsStillSmall(): void
    {
        // Prefix/suffix trimming alone would store everything BETWEEN the two
        // edits — that is, the whole note. This is the case that says the
        // engine is doing real work rather than trimming ends.
        $lines = array_map(static fn (int $i): string => "Line $i of a long operational runbook.", range(1, 1200));
        $from = implode("\n", $lines);
        $lines[5] = 'typo fixed near the top';
        $lines[1194] = 'typo fixed near the bottom';
        $to = implode("\n", $lines);

        $ops = LineDiff::diff($from, $to);

        self::assertSame($to, LineDiff::apply($from, $ops));
        self::assertLessThan(strlen($to) / 100, strlen((string) json_encode($ops)));
    }

    public function testAPatchThatDoesNotFitItsSourceThrowsRatherThanReturningHalfANote(): void
    {
        // Reconstructing against the wrong text is the failure mode of a chain
        // read out of order. Returning a partial body would be worse than
        // failing: the caller is restoring somebody's previous note.
        $ops = LineDiff::diff("a\nb\nc", "a\nB\nc");

        $this->expectException(\RuntimeException::class);
        LineDiff::apply('a', $ops);
    }

    public function testALeftoverSourceIsRefusedToo(): void
    {
        // The other half of "does not fit": a patch accounting for less than
        // the whole source. Without this check it would silently truncate.
        $this->expectException(\RuntimeException::class);
        LineDiff::apply("a\nb\nc", [[LineDiff::COPY, 1]]);
    }

    public function testAnUnknownOperationIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        LineDiff::apply('a', [[99, 1]]);
    }
}
