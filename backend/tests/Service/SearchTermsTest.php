<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SearchTerms;
use PHPUnit\Framework\TestCase;

/**
 * What the keyword index holds for a text, and what it is asked for a query
 * word: the two halves have to agree, or a word is indexed one way and looked
 * for another.
 */
final class SearchTermsTest extends TestCase
{
    public function testEnglishPassesThroughUntouched(): void
    {
        $text = 'The review gate holds agent writes; memex-oss, café, nginx:80.';

        self::assertSame($text, SearchTerms::index($text));
    }

    public function testCyrillicWordsAreHeldAsTheirStems(): void
    {
        self::assertSame('срок действ договор истек.', SearchTerms::index('Срок действия договора истёк.'));
    }

    public function testACyrillicWordWithDigitsIsLeftAsWritten(): void
    {
        self::assertSame('номер Т851АТ150', SearchTerms::index('номер Т851АТ150'));
    }

    public function testADecomposedLetterStemsLikeTheComposedOne(): void
    {
        self::assertSame(SearchTerms::index('Майский'), SearchTerms::index("Ма\u{0438}\u{0306}ский"));
        self::assertSame('договор', SearchTerms::index("догово\u{0301}р"));
    }

    public function testAnUnspacedRunIsHeldAsItsPairsWithItsCharactersAtTheEnd(): void
    {
        self::assertSame(' 价格 格表  价 格 表', SearchTerms::index('价格表'));
        self::assertSame(' 价 ', SearchTerms::index('价'));
    }

    /** A quoted phrase across two runs asks for their pairs side by side, so nothing may sit between them. */
    public function testPairsOfNeighbouringRunsStayNeighbours(): void
    {
        self::assertSame(' 회의   기록  회 의 기 록', SearchTerms::index('회의 기록'));
    }

    public function testAThaiCharacterKeepsItsMarksInsideOneToken(): void
    {
        self::assertSame(" ท\u{EE35}\u{EE48}น  ท\u{EE35}\u{EE48} น", SearchTerms::index("ท\u{0E35}\u{0E48}น"));
    }

    public function testThaiWordsThatDifferOnlyInTheirMarksStayApart(): void
    {
        self::assertNotSame(SearchTerms::query('ปู'), SearchTerms::query('ป'));
        self::assertNotSame(SearchTerms::query('กิน'), SearchTerms::query('ก้น'));
    }

    public function testAWordMixingScriptsKeepsEachPartApart(): void
    {
        self::assertSame('iPhone 价格  价 格', SearchTerms::index('iPhone价格'));
        self::assertSame([
            ['match' => 'iPhone', 'passages' => ['"iPhone"']],
            ['match' => '价格', 'passages' => []],
        ], SearchTerms::query('iPhone价格'));
    }

    public function testAQueryRunIsAskedForAsItsPairs(): void
    {
        self::assertSame([['match' => '价格 格表', 'passages' => []]], SearchTerms::query('价格表'));
        self::assertSame([['match' => '价', 'passages' => []]], SearchTerms::query('价'));
    }

    public function testAQueryWordKeepsItsPunctuationAsOnePhrase(): void
    {
        self::assertSame([['match' => 'memex-oss', 'passages' => ['"memex-oss"']]], SearchTerms::query('memex-oss'));
        self::assertSame([['match' => 'договор-аренд', 'passages' => ['"договор"*', '"аренд"*']]], SearchTerms::query('договора-аренды'));
    }

    public function testInvalidUtf8IsIndexedAsItIs(): void
    {
        self::assertSame("abc\xFF", SearchTerms::index("abc\xFF"));
    }
}
