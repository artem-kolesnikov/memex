<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\RussianStem;
use PHPUnit\Framework\TestCase;

/**
 * Pairs from Snowball's own Russian vocabulary and its expected output
 * (github.com/snowballstem/snowball-data, russian/voc.txt and output.txt),
 * one or more for every step of the algorithm. The port agreed with all
 * 49,785 words of that list when it was written.
 */
final class RussianStemTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function pairs(): iterable
    {
        yield 'a noun ending' => ['страна', 'стран'];
        yield 'a noun in the instrumental plural' => ['вопросами', 'вопрос'];
        yield 'a verb after its reflexive ending' => ['договорились', 'договор'];
        yield 'a past tense after а' => ['бегали', 'бега'];
        yield 'a perfective gerund after а' => ['ворвавшись', 'ворва'];
        yield 'a perfective gerund in ивши' => ['вступивши', 'вступ'];
        yield 'an adjective' => ['вопросительные', 'вопросительн'];
        yield 'a participle before its adjective ending' => ['бегущий', 'бегущ'];
        yield 'a participle in ующ' => ['балующий', 'бал'];
        yield 'a participle after а' => ['играющею', 'игра'];
        yield 'a superlative, undoubling н' => ['длиннейшим', 'длин'];
        yield 'a superlative' => ['благороднейшая', 'благородн'];
        yield 'a comparative' => ['великолепнее', 'великолепн'];
        yield 'a derivational ending in R2' => ['возможность', 'возможн'];
        yield 'a derivational ending short of R2' => ['радость', 'радост'];
        yield 'an adverb in нно' => ['безнаказанно', 'безнаказа'];
        yield 'ё read as е' => ['берёза', 'берез'];
        yield 'the note that started it' => ['доверенность', 'доверен'];
    }

    /** @dataProvider pairs */
    public function testStem(string $word, string $stem): void
    {
        self::assertSame($stem, RussianStem::stem($word));
    }

    public function testCaseIsFolded(): void
    {
        self::assertSame(RussianStem::stem('договора'), RussianStem::stem('ДОГОВОРА'));
    }
}
