<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\McpServer;
use App\Service\NoteWriter;
use PHPUnit\Framework\TestCase;

/**
 * The summary an assistant writes for itself (2026-08-19).
 *
 * memex hosts no AI of its own: an assistant calling `propose` is
 * already holding the text, so it writes the description and passes it as an
 * argument, and the server makes no summarizing call. What this guards is the
 * boundary between "supplied" and "not supplied", because the two mean
 * different money. NoteWriter::create() keeps a non-empty summary verbatim and
 * skips the OpenAI call; anything this reads as null buys a summary. A
 * whitespace-only argument therefore must NOT count as a summary — treating it
 * as one would leave the note permanently blank AND skip the fallback, which is
 * the one outcome worse than paying.
 */
final class McpSuppliedSummaryTest extends TestCase
{
    public function testAWrittenSummaryIsTakenVerbatim(): void
    {
        self::assertSame(
            'What the note is about.',
            McpServer::summaryArg(['summary' => 'What the note is about.'])
        );
    }

    public function testSurroundingWhitespaceIsTrimmed(): void
    {
        self::assertSame('Trimmed.', McpServer::summaryArg(['summary' => "  Trimmed.\n"]));
    }

    public function testAnAbsentSummaryIsNull(): void
    {
        self::assertNull(McpServer::summaryArg([]));
        self::assertNull(McpServer::summaryArg(['title' => 'no summary here']));
    }

    public function testAnEmptyOrBlankSummaryIsNullSoTheFallbackStillRuns(): void
    {
        self::assertNull(McpServer::summaryArg(['summary' => '']));
        self::assertNull(McpServer::summaryArg(['summary' => "   \n\t "]));
    }

    public function testANonStringSummaryIsRefusedRatherThanCoerced(): void
    {
        // A model sending {"summary": ["a", "b"]} must not have the array
        // stringified into the note's description.
        self::assertNull(McpServer::summaryArg(['summary' => ['a', 'b']]));
        self::assertNull(McpServer::summaryArg(['summary' => 42]));
        self::assertNull(McpServer::summaryArg(['summary' => null]));
    }

    public function testEveryPathThatTakesASummaryReadsItTheSameWay(): void
    {
        // MCP and REST normalise through one function. Each path grew its own
        // copy first, which is how they would have drifted — one trimming and
        // one not, or capping at a different length than the schema advertises.
        foreach (['plain', '  padded  ', '', '   ', 'x'] as $value) {
            self::assertSame(
                NoteWriter::normaliseSummary($value),
                McpServer::summaryArg(['summary' => $value]),
                'summaryArg must be the shared normaliser, not a second reading of it'
            );
        }
    }

    public function testASummaryIsCutToTheLengthTheSchemaAdvertises(): void
    {
        // maxLength 5000 on every `summary` field in the tool schema, and the
        // column is TEXT, so the cap is a promise rather than a limit imposed
        // by storage. A model that ignores the schema still cannot overrun it.
        $long = str_repeat('a', NoteWriter::SUMMARY_MAX_CHARS + 500);

        self::assertSame(NoteWriter::SUMMARY_MAX_CHARS, mb_strlen((string) NoteWriter::normaliseSummary($long)));
    }

    public function testAMultibyteSummaryIsCutByCharactersNotBytes(): void
    {
        $long = str_repeat('é', NoteWriter::SUMMARY_MAX_CHARS + 10);

        self::assertSame(NoteWriter::SUMMARY_MAX_CHARS, mb_strlen((string) NoteWriter::normaliseSummary($long)));
    }
}
