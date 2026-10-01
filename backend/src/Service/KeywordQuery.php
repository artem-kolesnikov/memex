<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A search box query as an FTS5 MATCH expression, with the operators a person types.
 *
 *   words            all must appear (as before)
 *   a OR b           either; `|` is accepted as the same thing
 *   NOT a, -a, !a    must not appear
 *   ( ... )          grouping
 *   "a b"            the words in that order
 *
 * Operators are UPPERCASE words only: "cats or dogs" is three words, and stays
 * the query it always was. `AND` is accepted and means what the space means.
 *
 * `exact` is true when the query carried OR, NOT or a phrase — an expression
 * that only means something against the literal text. HybridSearch then never
 * falls back to meaning-search for it: the nearest vectors to "NOT ai" are
 * notes about ai.
 *
 * FTS5 has no NOT of its own, only `a NOT b`, so an expression compiles to a
 * MATCH string and `negated`: when it is set the query is every note the
 * string does NOT match. `NOT ai` is `"ai"` negated, `memex OR NOT ai` is
 * `"ai" NOT "memex"` negated. Every expression has one of the two forms.
 *
 * English stop words are dropped as words, the way the search always treated
 * them: `NOT the` excludes nothing, and a query of nothing but stop words
 * matches no text (`words` stays true, so the caller can still search it by
 * meaning). Inside a phrase they stay, except at its ends, so "review the
 * gate" still asks for those words in that order.
 *
 * Never throws and never emits an expression FTS5 refuses: every word goes
 * out quoted, every character with a meaning to the parser is stripped from
 * it, and the parser drops what it cannot place (a trailing OR, an empty
 * group, a stray paren) rather than erroring on it. Groups nest at most
 * MAX_DEPTH deep; a paren past that is read as a space.
 */
final class KeywordQuery
{
    private const T_WORD = 'word';
    private const T_PHRASE = 'phrase';
    private const T_OR = 'or';
    private const T_AND = 'and';
    private const T_NOT = 'not';
    private const T_OPEN = '(';
    private const T_CLOSE = ')';

    private const MAX_DEPTH = 8;

    /** English stop words (the Snowball list), which a query never asks for. */
    private const STOP_WORDS = [
        'i', 'me', 'my', 'myself', 'we', 'our', 'ours', 'ourselves', 'you', 'your', 'yours', 'yourself',
        'yourselves', 'he', 'him', 'his', 'himself', 'she', 'her', 'hers', 'herself', 'it', 'its', 'itself',
        'they', 'them', 'their', 'theirs', 'themselves', 'what', 'which', 'who', 'whom', 'this', 'that',
        'these', 'those', 'am', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had',
        'having', 'do', 'does', 'did', 'doing', 'a', 'an', 'the', 'and', 'but', 'if', 'or', 'because', 'as',
        'until', 'while', 'of', 'at', 'by', 'for', 'with', 'about', 'against', 'between', 'into', 'through',
        'during', 'before', 'after', 'above', 'below', 'to', 'from', 'up', 'down', 'in', 'out', 'on', 'off',
        'over', 'under', 'again', 'further', 'then', 'once', 'here', 'there', 'when', 'where', 'why', 'how',
        'all', 'any', 'both', 'each', 'few', 'more', 'most', 'other', 'some', 'such', 'no', 'nor', 'not',
        'only', 'own', 'same', 'so', 'than', 'too', 'very', 's', 't', 'can', 'will', 'just', 'don', 'should',
        'now',
    ];

    /** @var list<array{0: string, 1?: list<string>}> */
    private array $tokens = [];
    private int $pos = 0;
    private bool $words = false;

    /**
     * @return array{match: string, negated: bool, exact: bool, words: bool} `match` is empty when
     *         nothing is left to match; `words` says whether the query named any word at all,
     *         stop words included
     */
    public static function parse(string $query): array
    {
        $self = new self();
        $exact = $self->tokenize(self::scrub($query));
        $parts = [];
        while ($self->pos < count($self->tokens)) {
            $part = $self->expression();
            if ($part !== null) {
                $parts[] = $part;
            } elseif ($self->pos < count($self->tokens)) {
                // A token expression() could not start on (a stray `)`): skip it.
                ++$self->pos;
            }
        }

        $root = self::node(self::T_AND, $parts);
        if ($root === null) {
            return ['match' => '', 'negated' => false, 'exact' => $exact, 'words' => $self->words];
        }
        [$negated, $match] = self::compile($root);

        return ['match' => $match, 'negated' => $negated, 'exact' => $exact, 'words' => $self->words];
    }

    /**
     * The words as typed, all of them required, no operators — the canary's
     * question of whether a title finds itself, never a person's search.
     *
     * @return array{match: string, negated: bool, exact: bool, words: bool}
     */
    public static function literal(string $query): array
    {
        $words = self::words(self::scrub($query));
        $kept = array_values(array_filter($words, static fn (string $w): bool => !self::isStopWord($w)));

        return [
            'match' => implode(' AND ', array_map(self::quote(...), $kept)),
            'negated' => false,
            'exact' => false,
            'words' => $words !== [],
        ];
    }

    private static function scrub(string $query): string
    {
        return mb_check_encoding($query, 'UTF-8') ? $query : mb_convert_encoding($query, 'UTF-8', 'UTF-8');
    }

    /** @return bool Whether an operator or phrase was seen */
    private function tokenize(string $query): bool
    {
        $exact = false;
        $open = 0;
        $flattened = 0;
        $length = strlen($query);
        $i = 0;
        while ($i < $length) {
            $c = $query[$i];
            if (ctype_space($c)) {
                ++$i;
                continue;
            }
            if ($c === '(') {
                if ($open < self::MAX_DEPTH) {
                    $this->tokens[] = [self::T_OPEN];
                    ++$open;
                } else {
                    ++$flattened;
                }
                ++$i;
                continue;
            }
            if ($c === ')') {
                if ($flattened > 0) {
                    --$flattened;
                } else {
                    $this->tokens[] = [self::T_CLOSE];
                    $open = max(0, $open - 1);
                }
                ++$i;
                continue;
            }
            // The symbol forms are operators wherever they stand, so `cat|dog`
            // and `!(a OR b)` read as written rather than as words.
            if ($c === '|' || $c === '&' || $c === '!') {
                $this->tokens[] = [$c === '|' ? self::T_OR : ($c === '&' ? self::T_AND : self::T_NOT)];
                $exact = $exact || $c !== '&';
                ++$i;
                continue;
            }
            if ($c === '"') {
                $end = strpos($query, '"', $i + 1);
                $inner = $end === false ? substr($query, $i + 1) : substr($query, $i + 1, $end - $i - 1);
                $i = $end === false ? $length : $end + 1;
                $words = self::words($inner);
                if ($words !== []) {
                    $this->tokens[] = [self::T_PHRASE, $words];
                    $exact = true;
                }
                continue;
            }
            $start = $i;
            while ($i < $length && !ctype_space($query[$i]) && strpos('()"|&!', $query[$i]) === false) {
                ++$i;
            }
            $raw = substr($query, $start, $i - $start);
            switch ($raw) {
                case 'OR':
                    $this->tokens[] = [self::T_OR];
                    $exact = true;
                    continue 2;
                case 'AND':
                    $this->tokens[] = [self::T_AND];
                    continue 2;
                case 'NOT':
                    $this->tokens[] = [self::T_NOT];
                    $exact = true;
                    continue 2;
            }
            // A leading dash negates what follows — a word, or a group or
            // phrase that starts right after it. A dash on its own between
            // words is nothing, and a dash inside a word is part of it.
            $rest = ltrim($raw, '-');
            $negated = $rest !== $raw && ($rest !== '' || ($i < $length && ($query[$i] === '(' || $query[$i] === '"')));
            if ($negated) {
                $this->tokens[] = [self::T_NOT];
                $exact = true;
            }
            foreach (self::words($rest) as $word) {
                $this->tokens[] = [self::T_WORD, [$word]];
            }
        }

        return $exact;
    }

    /**
     * The words in a run of text: split on whitespace and on every character
     * that was ever query syntax, control bytes dropped, and a piece with no
     * letter or digit in it discarded — FTS5 reads nothing from it, and an
     * empty phrase matches no note at all. "women's" is two words, as it
     * always was.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $clean = trim(preg_replace("/[&|!():*<>'\"\\\\\\x00-\\x1f\\x7f]+/", ' ', $text) ?? '');
        if ($clean === '') {
            return [];
        }

        return array_values(array_filter(
            preg_split('/\s+/', $clean) ?: [],
            static fn (string $w): bool => preg_match('/[\p{L}\p{N}]/u', $w) === 1,
        ));
    }

    private static function isStopWord(string $word): bool
    {
        return in_array(mb_strtolower($word), self::STOP_WORDS, true);
    }

    /** An FTS5 string: whatever it holds is text for the tokenizer, never syntax. */
    private static function quote(string $text): string
    {
        return '"'.str_replace('"', '""', $text).'"';
    }

    /** expression := conjunction (OR conjunction)* */
    private function expression(): ?array
    {
        $operands = [];
        $left = $this->conjunction();
        if ($left !== null) {
            $operands[] = $left;
        }
        while ($this->peek() === self::T_OR) {
            ++$this->pos;
            $right = $this->conjunction();
            if ($right !== null) {
                $operands[] = $right;
            }
        }

        return self::node(self::T_OR, $operands);
    }

    /** conjunction := unary (AND? unary)* */
    private function conjunction(): ?array
    {
        $operands = [];
        while (true) {
            if ($this->peek() === self::T_AND) {
                ++$this->pos;
                continue;
            }
            $type = $this->peek();
            if ($type === null || $type === self::T_OR || $type === self::T_CLOSE) {
                break;
            }
            $operand = $this->unary();
            if ($operand !== null) {
                $operands[] = $operand;
            }
        }

        return self::node(self::T_AND, $operands);
    }

    /** unary := NOT* primary */
    private function unary(): ?array
    {
        $nots = 0;
        while ($this->peek() === self::T_NOT) {
            ++$this->pos;
            ++$nots;
        }
        $operand = $this->primary();
        if ($operand === null || $nots % 2 === 0) {
            return $operand;
        }

        return [self::T_NOT, [$operand]];
    }

    /** primary := word | phrase | ( expression ) */
    private function primary(): ?array
    {
        $token = $this->tokens[$this->pos] ?? null;
        if ($token === null) {
            return null;
        }
        ++$this->pos;
        switch ($token[0]) {
            case self::T_WORD:
                $this->words = true;

                return self::isStopWord($token[1][0]) ? null : [self::T_WORD, $token[1]];
            case self::T_PHRASE:
                $this->words = true;
                $words = $token[1];
                while ($words !== [] && self::isStopWord($words[0])) {
                    array_shift($words);
                }
                while ($words !== [] && self::isStopWord($words[count($words) - 1])) {
                    array_pop($words);
                }

                return $words === [] ? null : [self::T_WORD, $words];
            case self::T_OPEN:
                $inner = $this->expression();
                if ($this->peek() === self::T_CLOSE) {
                    ++$this->pos;
                }

                return $inner;
            default:
                // A `)` with no `(` open, or an operator with nothing before it.
                return null;
        }
    }

    /**
     * @param list<array> $operands
     */
    private static function node(string $type, array $operands): ?array
    {
        return match (count($operands)) {
            0 => null,
            1 => $operands[0],
            default => [$type, $operands],
        };
    }

    /**
     * The tree as FTS5, positive or negated. A conjunction keeps its negated
     * operands for one `NOT` after the rest; a disjunction that holds one is
     * the complement of a conjunction (De Morgan), so negation only ever rises.
     *
     * @return array{0: bool, 1: string, 2: bool} negated, expression, atomic
     */
    private static function compile(array $node): array
    {
        if ($node[0] === self::T_WORD) {
            return [false, self::quote(implode(' ', $node[1])), true];
        }
        if ($node[0] === self::T_NOT) {
            [$negated, $expression, $atomic] = self::compile($node[1][0]);

            return [!$negated, $expression, $atomic];
        }

        $positive = [];
        $negative = [];
        foreach ($node[1] as $child) {
            $compiled = self::compile($child);
            if ($compiled[0]) {
                $negative[] = $compiled;
            } else {
                $positive[] = $compiled;
            }
        }

        if ($node[0] === self::T_AND) {
            if ($positive === []) {
                return [true, ...self::join($negative, 'OR')];
            }

            return [false, ...self::except(self::join($positive, 'AND'), $negative)];
        }

        if ($negative === []) {
            return [false, ...self::join($positive, 'OR')];
        }

        return [true, ...self::except(self::join($negative, 'AND'), $positive)];
    }

    /**
     * @param list<array{0: bool, 1: string, 2: bool}> $operands
     * @return array{0: string, 1: bool}
     */
    private static function join(array $operands, string $operator): array
    {
        if (count($operands) === 1) {
            return [$operands[0][1], $operands[0][2]];
        }

        return [implode(' '.$operator.' ', array_map(self::group(...), $operands)), false];
    }

    /**
     * `$base` without anything `$excluded` matches.
     *
     * @param array{0: string, 1: bool}                  $base
     * @param list<array{0: bool, 1: string, 2: bool}>   $excluded
     * @return array{0: string, 1: bool}
     */
    private static function except(array $base, array $excluded): array
    {
        if ($excluded === []) {
            return $base;
        }
        [$without, $atomic] = self::join($excluded, 'OR');

        return [self::group([false, ...$base]).' NOT '.self::group([false, $without, $atomic]), false];
    }

    /** @param array{0: bool, 1: string, 2: bool} $compiled */
    private static function group(array $compiled): string
    {
        return $compiled[2] ? $compiled[1] : '('.$compiled[1].')';
    }

    private function peek(): ?string
    {
        return $this->tokens[$this->pos][0] ?? null;
    }
}
