<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Note;
use App\Service\FrontmatterParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrontmatterDatesTest extends TestCase
{
    public static function dates(): iterable
    {
        yield 'memex export, with a zone' => ['2026-01-02T03:04:05+02:00', '2026-01-02 01:04:05'];
        yield 'memex export, UTC' => ['2024-05-06T07:08:09Z', '2024-05-06 07:08:09'];
        yield 'Obsidian date' => ['2023-04-01', '2023-04-01 00:00:00'];
        yield 'Obsidian date and time, no zone' => ['2023-04-01T10:30', '2023-04-01 10:30:00'];
        yield 'quoted' => ['"2023-04-01T10:30:00Z"', '2023-04-01 10:30:00'];
        yield 'space-separated' => ['2023-04-01 10:30:15', '2023-04-01 10:30:15'];
    }

    #[DataProvider('dates')]
    public function testADateComesBackAsUtc(string $written, string $expected): void
    {
        $parsed = (new FrontmatterParser())->parse("---\ncreated: $written\nupdated: $written\n---\nbody", 'fallback');

        foreach (['created', 'updated'] as $key) {
            self::assertNotNull($parsed[$key], $key);
            self::assertSame('UTC', $parsed[$key]->getTimezone()->getName());
            self::assertSame($expected, $parsed[$key]->format('Y-m-d H:i:s'));
        }
    }

    public static function notDates(): iterable
    {
        yield 'a word' => ['yesterday'];
        yield 'a number' => ['1700000000'];
        yield 'a list' => ['[2023-04-01]'];
        yield 'empty' => ['""'];
        yield 'the future' => ['2999-01-01'];
        yield 'a date inside text' => ['"on 2023-04-01"'];
    }

    #[DataProvider('notDates')]
    public function testWhatIsNotAPastDateIsDropped(string $written): void
    {
        $parsed = (new FrontmatterParser())->parse("---\ntitle: Kept\ncreated: $written\n---\nbody", 'fallback');

        self::assertNull($parsed['created']);
        self::assertSame('Kept', $parsed['title']);
        self::assertSame('body', $parsed['body']);
    }

    public function testNoFrontmatterCarriesNoDates(): void
    {
        $parsed = (new FrontmatterParser())->parse('just a body', 'fallback');

        self::assertNull($parsed['created']);
        self::assertNull($parsed['updated']);
    }

    public function testBothDatesAreCarried(): void
    {
        $note = $this->note();
        $note->carryDates(new \DateTimeImmutable('2022-01-02 03:04:05+02:00'), new \DateTimeImmutable('2024-05-06 07:08:09Z'));

        self::assertSame('2022-01-02 01:04:05 UTC', $note->getCreatedAt()->format('Y-m-d H:i:s e'));
        self::assertSame('2024-05-06 07:08:09 UTC', $note->getUpdatedAt()->format('Y-m-d H:i:s e'));
    }

    public function testACreationAloneIsAlsoTheLastUpdate(): void
    {
        $note = $this->note();
        $note->carryDates(new \DateTimeImmutable('2023-04-01 00:00:00Z'), null);

        self::assertSame('2023-04-01 00:00:00', $note->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertSame('2023-04-01 00:00:00', $note->getUpdatedAt()->format('Y-m-d H:i:s'));
    }

    public function testAnUpdateAloneIsAlsoTheCreation(): void
    {
        $note = $this->note();
        $note->carryDates(null, new \DateTimeImmutable('2024-05-06 07:08:09Z'));

        self::assertSame('2024-05-06 07:08:09', $note->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertSame('2024-05-06 07:08:09', $note->getUpdatedAt()->format('Y-m-d H:i:s'));
    }

    public function testAnUpdateBeforeTheCreationIsReadAsTheCreation(): void
    {
        $note = $this->note();
        $note->carryDates(new \DateTimeImmutable('2024-05-06 07:08:09Z'), new \DateTimeImmutable('2020-01-01 00:00:00Z'));

        self::assertSame('2024-05-06 07:08:09', $note->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertSame('2024-05-06 07:08:09', $note->getUpdatedAt()->format('Y-m-d H:i:s'));
    }

    public function testNoDatesLeaveTheNoteAsWritten(): void
    {
        $note = $this->note();
        $created = $note->getCreatedAt();
        $note->carryDates(null, null);

        self::assertSame($created, $note->getCreatedAt());
        self::assertSame($created, $note->getUpdatedAt());
    }

    private function note(): Note
    {
        return new Note(null, 'Dated', 'body', Note::SOURCE_UPLOAD, null, Note::STATUS_VERIFIED);
    }
}
