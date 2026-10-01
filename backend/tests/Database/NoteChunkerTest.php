<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\NoteChunker;
use PHPUnit\Framework\TestCase;

/** How a note is cut before it is embedded — pure text, no database. */
final class NoteChunkerTest extends TestCase
{
    private const SIZE = 6000;

    private static function long(int $chars): string
    {
        return rtrim(str_repeat('word ', (int) ceil($chars / 5)));
    }

    public function testAShortNoteIsOneChunkWithNoHeading(): void
    {
        $chunks = (new NoteChunker())->chunks('Title', "## A heading\n\nBody  text.", self::SIZE);

        self::assertSame([['heading' => null, 'text' => 'Title ## A heading Body text.']], $chunks);
    }

    public function testALongNoteIsCutOnItsHeadingsAndEachChunkNamesItsSection(): void
    {
        $body = "Intro.\n\n## First\n\n".self::long(4000)."\n\n### Second\n\n".self::long(4000)."\n\n## Third\n\nShort.";
        $chunks = (new NoteChunker())->chunks('T', $body, self::SIZE);

        self::assertSame([null, 'First', 'Second', 'Third'], array_column($chunks, 'heading'));
        self::assertStringStartsWith('T Intro.', $chunks[0]['text']);
        self::assertStringStartsWith('T — First word', $chunks[1]['text']);
        self::assertSame('T — Third Short.', $chunks[3]['text']);
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(self::SIZE, mb_strlen($chunk['text'], 'UTF-8'));
        }
    }

    public function testASectionLongerThanAChunkIsCutOnParagraphsAndAParagraphLongerStillIsCutHard(): void
    {
        $section = self::long(5000)."\n\n".self::long(5000)."\n\n".self::long(13000);
        $chunks = (new NoteChunker())->chunks('T', "## Only\n\n".$section, self::SIZE);

        self::assertGreaterThanOrEqual(5, count($chunks));
        foreach ($chunks as $chunk) {
            self::assertSame('Only', $chunk['heading']);
            self::assertStringStartsWith('T — Only ', $chunk['text']);
            self::assertLessThanOrEqual(self::SIZE, mb_strlen($chunk['text'], 'UTF-8'));
        }
        self::assertSame(
            NoteChunker::collapse($section),
            NoteChunker::collapse(implode(' ', array_map(static fn (array $c): string => substr($c['text'], strlen('T — Only ')), $chunks))),
            'nothing is lost and nothing is duplicated between the pieces'
        );
    }

    public function testAHeadingInsideAFencedCodeBlockIsNotAHeading(): void
    {
        $body = "## Real\n\n```\n## not a heading\n```\n\n".self::long(7000);
        $chunks = (new NoteChunker())->chunks('T', $body, self::SIZE);

        self::assertSame(['Real'], array_values(array_unique(array_column($chunks, 'heading'))));
    }

    public function testANoteWithNoTextHasNoChunks(): void
    {
        self::assertSame([], (new NoteChunker())->chunks('', "  \n ", self::SIZE));
    }
}
