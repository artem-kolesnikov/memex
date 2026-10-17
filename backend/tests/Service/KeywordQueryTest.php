<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\KeywordQuery;
use App\Service\NoteWriter;
use App\Tests\Support\KbFixture;
use App\Tests\Support\TestData;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The search-box grammar, case by case. The MATCH strings asserted here are
 * what FTS5 receives, so a change to the parser that "just" reformats them
 * has to say so in this file. Every word is written as `notes_terms` holds
 * it, which is the word itself unless it is Cyrillic or of an unspaced script.
 */
final class KeywordQueryTest extends KernelTestCase
{
    /** @return iterable<string, array{string, array{match: string, negated: bool, exact: bool, words: bool}}> */
    public static function cases(): iterable
    {
        yield 'plain words are ANDed, as before' => ['memex memory', self::compiled('"memex" AND "memory"')];
        yield 'the example from the guide' => ['(memex OR memory) NOT ai', self::compiled('("memex" OR "memory") NOT "ai"', exact: true)];
        yield 'a leading hyphen negates' => ['memex -ai', self::compiled('"memex" NOT "ai"', exact: true)];
        yield 'a bang negates too' => ['memex !ai', self::compiled('"memex" NOT "ai"', exact: true)];
        yield 'a phrase is the words in order' => ['"review gate" NOT curator', self::compiled('"review gate" NOT "curator"', exact: true)];
        yield 'a one-word phrase is exact all the same' => ['"memex"', self::compiled('"memex"', exact: true)];
        yield 'lowercase or and not are words' => ['cats or dogs not fish', self::compiled('"cats" AND "dogs" AND "fish"')];
        yield 'AND is the space' => ['memex AND memory', self::compiled('"memex" AND "memory"')];
        yield 'tsquery symbols typed by hand' => ['ubuntu & certbot', self::compiled('"ubuntu" AND "certbot"')];
        yield 'a pipe is OR' => ['ubuntu | certbot', self::compiled('"ubuntu" OR "certbot"', exact: true)];
        yield 'a trailing operator is dropped' => ['memex OR', self::compiled('"memex"', exact: true)];
        yield 'a leading operator is dropped' => ['OR memex', self::compiled('"memex"', exact: true)];
        yield 'NOT with nothing to negate' => ['memex NOT', self::compiled('"memex"', exact: true)];
        yield 'an unclosed group closes at the end' => ['(memex OR memory', self::compiled('"memex" OR "memory"', exact: true)];
        yield 'a stray close paren is ignored' => ['memex) memory', self::compiled('"memex" AND "memory"')];
        yield 'an empty group is nothing' => ['memex ()', self::compiled('"memex"')];
        yield 'nested groups' => ['((x OR y) NOT z) OR w', self::compiled('(("x" OR "y") NOT "z") OR "w"', exact: true)];
        yield 'NOT over a group' => ['memex NOT (ai OR llm)', self::compiled('"memex" NOT ("ai" OR "llm")', exact: true)];
        yield 'an apostrophe splits, as before' => ["women's", self::compiled('"women"')];
        yield 'a metacharacter inside a word splits it, as before' => ['nginx:80 foo*bar', self::compiled('"nginx" AND "80" AND "foo" AND "bar"')];
        yield 'a hyphenated word is not a negation' => ['memex-oss bundle', self::compiled('"memex-oss" AND "bundle"')];
        yield 'a bare hyphen is nothing' => ['memex - memory', self::compiled('"memex" AND "memory"')];
        yield 'an unterminated quote runs to the end' => ['memex "review gate', self::compiled('"memex" AND "review gate"', exact: true)];
        yield 'only punctuation' => ['() "" -', self::compiled('', words: false)];
        yield 'a bang before a group negates it' => ['!(cat OR dog)', self::compiled('"cat" OR "dog"', exact: true, negated: true)];
        yield 'a dash before a group negates it' => ['-(cat OR dog) fish', self::compiled('"fish" NOT ("cat" OR "dog")', exact: true)];
        yield 'a dash before a phrase negates it' => ['memex -"review gate"', self::compiled('"memex" NOT "review gate"', exact: true)];
        yield 'a double bang is a double negation' => ['!!cat', self::compiled('"cat"', exact: true)];
        yield 'NOT NOT is the same' => ['NOT NOT cat', self::compiled('"cat"', exact: true)];
        yield 'a run of dashes is one negation' => ['--cat', self::compiled('"cat"', exact: true, negated: true)];
        yield 'a compact pipe is OR' => ['cat|dog', self::compiled('"cat" OR "dog"', exact: true)];
        yield 'a compact ampersand is AND' => ['cat&dog', self::compiled('"cat" AND "dog"')];
        yield 'a bang inside a word is an operator' => ['cat!dog', self::compiled('"cat" NOT "dog"', exact: true)];
        yield 'a NUL byte is a space' => ["cat\0dog", self::compiled('"cat" AND "dog"')];
        yield 'a tab is a space' => ["cat\tdog", self::compiled('"cat" AND "dog"')];
        yield 'only AND' => ['AND AND', self::compiled('', words: false)];
        yield 'only operators' => ['NOT OR', self::compiled('', exact: true, words: false)];
        yield 'NOT on its own is the complement' => ['NOT ai', self::compiled('"ai"', exact: true, negated: true)];
        yield 'OR with a negation is the complement of the rest' => ['memex OR NOT ai', self::compiled('"ai" NOT "memex"', exact: true, negated: true)];
        yield 'NOT a stop word excludes nothing' => ['NOT the', self::compiled('', exact: true)];
        yield 'a phrase keeps the stop words inside it' => ['"the review the gate"', self::compiled('"review the gate"', exact: true)];
        yield 'a group past eight deep reads as a space' => ['(((((((((x OR y) z))))))))', self::compiled('"x" OR ("y" AND "z")', exact: true)];
        yield 'a Cyrillic word is asked for by its stem' => ['договора аренды', self::compiled('"договор" AND "аренд"')];
        yield 'a run of an unspaced script is the phrase of its pairs' => ['价格表', self::compiled('"价格 格表"')];
        yield 'a one-character run is itself' => ['价', self::compiled('"价"')];
        yield 'a word mixing scripts is a phrase per script' => ['iPhone价格', self::compiled('"iPhone" AND "价格"')];
        yield 'a phrase is stemmed word by word' => ['"договор аренды"', self::compiled('"договор аренд"', exact: true)];
        yield 'NOT works on stems too' => ['NOT договоры', self::compiled('"договор"', exact: true, negated: true)];
    }

    /**
     * @dataProvider cases
     *
     * @param array{match: string, negated: bool, exact: bool, words: bool} $compiled
     */
    public function testParse(string $query, array $compiled): void
    {
        $parsed = KeywordQuery::parse($query);
        unset($parsed['passage']);
        self::assertSame($compiled, $parsed);
    }

    /** @return iterable<string, array{string, string}> */
    public static function passages(): iterable
    {
        yield 'every word asked for, any of them' => ['memex memory', '"memex" OR "memory"'];
        yield 'a word asked to be absent is not marked' => ['memex NOT ai', '"memex"'];
        yield 'a phrase is marked as a phrase' => ['"review gate"', '"review gate"'];
        yield 'a Cyrillic word marks every form of its stem' => ['договора', '"договор"*'];
        yield 'a phrase with a Cyrillic word marks its words apart' => ['"договор аренды"', '"договор"* OR "аренд"*'];
        yield 'an unspaced run has nothing to mark in the text' => ['价格', ''];
    }

    /**
     * What `notes_fts`, which holds the note's own text, is asked for to mark
     * the passage a match was found in.
     *
     * @dataProvider passages
     */
    public function testPassage(string $query, string $passage): void
    {
        self::assertSame($passage, KeywordQuery::parse($query)['passage']);
    }

    /**
     * Whatever a person types, FTS5 accepts the expressions the parser hands
     * it: the match runs against a vault's own notes_terms and the passage
     * against its notes_fts, both without an error.
     */
    public function testNoInputProducesUnbalancedOrForeignSyntax(): void
    {
        TestData::fresh();
        self::bootKernel();
        $container = self::getContainer();
        $kb = new KbFixture($container);
        $writer = $container->get(NoteWriter::class);
        $kb->note($writer, 'Review gate', 'Operators and phrases: memex OR memory, NOT ai.');
        $kb->note($writer, 'Café notes', 'nginx:80 serves memex-oss; women\'s "quoted" text.');
        $connection = $container->get(EntityManagerInterface::class)->getConnection();

        $alphabet = [
            'a', 'b', 'x', 'the', ' ', '(', ')', '"', '-', '!', '&', '|', ':', '*', '<', '>', "'", '\\', "\0", "\t", 'é',
            '^', '+', '{', '}', ',', 'OR', 'NOT', 'AND', 'NEAR', 'NEAR(', 'title:', 'a-b', "a'b", '(((((((((',
            'д', 'договора', 'ё', '价', '价格表', 'ที่', 'iPhone价格', "и\u{0306}",
        ];
        $refused = [];
        mt_srand(20260921);
        for ($i = 0; $i < 2000; ++$i) {
            $parts = [];
            $n = mt_rand(1, 8);
            for ($j = 0; $j < $n; ++$j) {
                $parts[] = $alphabet[mt_rand(0, count($alphabet) - 1)];
            }
            $query = implode(mt_rand(0, 1) ? ' ' : '', $parts);
            foreach ([KeywordQuery::parse($query), KeywordQuery::literal($query)] as $compiled) {
                foreach (['notes_terms' => $compiled['match'], 'notes_fts' => $compiled['passage']] as $table => $expression) {
                    if ($expression === '') {
                        continue;
                    }
                    try {
                        $connection->fetchOne("SELECT count(*) FROM {$table} WHERE {$table} MATCH :q", ['q' => $expression]);
                    } catch (DbalException $e) {
                        $refused[] = var_export($query, true).' => '.$expression.': '.$e->getMessage();
                    }
                }
            }
        }

        self::assertSame([], $refused);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TestData::discard();
    }

    /** @return array{match: string, negated: bool, exact: bool, words: bool} */
    private static function compiled(string $match, bool $exact = false, bool $negated = false, bool $words = true): array
    {
        return ['match' => $match, 'negated' => $negated, 'exact' => $exact, 'words' => $words];
    }
}
