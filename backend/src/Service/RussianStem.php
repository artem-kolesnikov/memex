<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The Snowball Russian stemmer (snowballstem.org/algorithms/russian), ported
 * as written: "договора", "договору" and "договор" all stem to "договор", so
 * keyword search matches a Russian word in any of its forms.
 *
 * Every ending list is searched longest first, and the longest one present
 * decides, as Snowball's `among` does: when it needs a preceding а or я and
 * there is none, nothing shorter is tried in its place.
 */
final class RussianStem
{
    private const VOWELS = 'аеиоуыэюя';

    private const PERFECTIVE_GERUND_AFTER_A = ['в', 'вши', 'вшись'];
    private const PERFECTIVE_GERUND = ['ив', 'ивши', 'ившись', 'ыв', 'ывши', 'ывшись'];
    private const ADJECTIVE = [
        'ее', 'ие', 'ые', 'ое', 'ими', 'ыми', 'ей', 'ий', 'ый', 'ой', 'ем', 'им', 'ым', 'ом', 'его', 'ого',
        'ему', 'ому', 'их', 'ых', 'ую', 'юю', 'ая', 'яя', 'ою', 'ею',
    ];
    private const PARTICIPLE_AFTER_A = ['ем', 'нн', 'вш', 'ющ', 'щ'];
    private const PARTICIPLE = ['ивш', 'ывш', 'ующ'];
    private const REFLEXIVE = ['ся', 'сь'];
    private const VERB_AFTER_A = ['ла', 'на', 'ете', 'йте', 'ли', 'й', 'л', 'ем', 'н', 'ло', 'но', 'ет', 'ют', 'ны', 'ть', 'ешь', 'нно'];
    private const VERB = [
        'ила', 'ыла', 'ена', 'ейте', 'уйте', 'ите', 'или', 'ыли', 'ей', 'уй', 'ил', 'ыл', 'им', 'ым', 'ен',
        'ило', 'ыло', 'ено', 'ят', 'ует', 'уют', 'ит', 'ыт', 'ены', 'ить', 'ыть', 'ишь', 'ую', 'ю',
    ];
    private const NOUN = [
        'а', 'ев', 'ов', 'ие', 'ье', 'е', 'иями', 'ями', 'ами', 'еи', 'ии', 'и', 'ией', 'ей', 'ой', 'ий', 'й',
        'иям', 'ям', 'ием', 'ем', 'ам', 'ом', 'о', 'у', 'ах', 'иях', 'ях', 'ы', 'ь', 'ию', 'ью', 'ю', 'ия', 'ья', 'я',
    ];
    private const DERIVATIONAL = ['ост', 'ость'];
    private const SUPERLATIVE = ['ейш', 'ейше'];

    /** @var list<string> */
    private array $w;
    private int $rv;

    private function __construct(string $word)
    {
        $this->w = mb_str_split(str_replace('ё', 'е', mb_strtolower($word, 'UTF-8')), 1, 'UTF-8');
    }

    public static function stem(string $word): string
    {
        $self = new self($word);
        [$self->rv, $r2] = $self->regions();

        if (!$self->perfectiveGerund()) {
            $self->cut($self->longest(self::REFLEXIVE));
            if (!$self->adjectival() && !$self->verb()) {
                $self->cut($self->longest(self::NOUN));
            }
        }
        $self->cut($self->longest(['и']));
        $derivational = $self->longest(self::DERIVATIONAL);
        if ($derivational !== null && count($self->w) - mb_strlen($derivational) >= $r2) {
            $self->cut($derivational);
        }
        $self->tidyUp();

        return implode('', $self->w);
    }

    /**
     * RV starts after the first vowel; R2 is R1 of R1, where R1 starts after
     * the first consonant that follows a vowel.
     *
     * @return array{0: int, 1: int}
     */
    private function regions(): array
    {
        $n = count($this->w);
        $rv = $n;
        $r2 = $n;
        $i = $this->past($this->vowel(...), 0);
        if ($i !== null) {
            $rv = $i;
            $i = $this->past(fn (string $c): bool => !$this->vowel($c), $i);
            $i = $i === null ? null : $this->past($this->vowel(...), $i);
            $i = $i === null ? null : $this->past(fn (string $c): bool => !$this->vowel($c), $i);
            if ($i !== null) {
                $r2 = $i;
            }
        }

        return [$rv, $r2];
    }

    /** The position just after the first letter from $from that passes $test. */
    private function past(\Closure $test, int $from): ?int
    {
        for ($i = $from, $n = count($this->w); $i < $n; ++$i) {
            if ($test($this->w[$i])) {
                return $i + 1;
            }
        }

        return null;
    }

    private function vowel(string $c): bool
    {
        return str_contains(self::VOWELS, $c);
    }

    /**
     * The longest of the endings that ends the word inside RV.
     *
     * @param list<string> $endings
     */
    private function longest(array $endings): ?string
    {
        $best = null;
        $word = implode('', array_slice($this->w, $this->rv));
        foreach ($endings as $ending) {
            if (($best === null || mb_strlen($ending) > mb_strlen($best)) && str_ends_with($word, $ending)) {
                $best = $ending;
            }
        }

        return $best;
    }

    /** Whether the letter before $ending is а or я, inside RV. */
    private function afterA(string $ending): bool
    {
        $at = count($this->w) - mb_strlen($ending) - 1;

        return $at >= $this->rv && ($this->w[$at] === 'а' || $this->w[$at] === 'я');
    }

    private function cut(?string $ending): bool
    {
        if ($ending === null) {
            return false;
        }
        array_splice($this->w, count($this->w) - mb_strlen($ending));

        return true;
    }

    /**
     * Cuts the longest of the endings that need а or я before them, or of the
     * ones that do not; false when the longest needs it and has none.
     *
     * @param list<string> $afterA
     * @param list<string> $plain
     */
    private function either(array $afterA, array $plain): bool
    {
        $ending = $this->longest([...$afterA, ...$plain]);
        if ($ending === null || (!in_array($ending, $plain, true) && !$this->afterA($ending))) {
            return false;
        }

        return $this->cut($ending);
    }

    private function perfectiveGerund(): bool
    {
        return $this->either(self::PERFECTIVE_GERUND_AFTER_A, self::PERFECTIVE_GERUND);
    }

    private function adjectival(): bool
    {
        if (!$this->cut($this->longest(self::ADJECTIVE))) {
            return false;
        }
        $this->either(self::PARTICIPLE_AFTER_A, self::PARTICIPLE);

        return true;
    }

    private function verb(): bool
    {
        return $this->either(self::VERB_AFTER_A, self::VERB);
    }

    private function tidyUp(): void
    {
        $ending = $this->longest([...self::SUPERLATIVE, 'н', 'ь']);
        if ($ending === 'ь') {
            $this->cut('ь');
        } elseif ($ending !== null) {
            if ($ending !== 'н') {
                $this->cut($ending);
            }
            if ($this->longest(['нн']) !== null) {
                $this->cut('н');
            }
        }
    }
}
