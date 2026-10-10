<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The vector space a vault's notes are embedded in. A vault holds one, named in
 * its settings row, and changing it embeds every note again.
 *
 * Each model carries its own distances and piece size. text-embedding-3-large's
 * were measured on memex.tools; nomic-embed-text-v1.5's were mapped onto them
 * on the maintainer's vault (2026-09-30): the wider ones so they admit as many
 * of its 6,555 note pairs and 35 queries, the duplicate ones so they catch as
 * many of 460 lightly edited copies of its notes (a title gone or changed, a
 * sentence or a fifth cut, words dropped). nomic packs distances into about
 * 0.4 of OpenAI's range and tells a copy from a neighbour less sharply, so its
 * duplicate cut-offs admit more distinct pairs. Its pieces are shorter because
 * it reads 2,048 tokens and agreed better with OpenAI's note pairs at that size.
 * text-embedding-3-small's were mapped the same way on the same vault
 * (2026-10-08: 8,001 note pairs, 35 queries, 506 copies). It sits close to
 * large on the wider ones and, like nomic, tells a copy from a neighbour less
 * sharply.
 */
enum EmbeddingModel: string
{
    case OpenAi = 'text-embedding-3-large';
    case OpenAiSmall = 'text-embedding-3-small';
    case Local = 'nomic-embed-text-v1.5';

    public function dimensions(): int
    {
        return match ($this) {
            self::OpenAi, self::OpenAiSmall => 1536,
            self::Local => 768,
        };
    }

    /** True when the vectors are made on this server, with no key and no bill. */
    public function isLocal(): bool
    {
        return $this === self::Local;
    }

    /** The longest piece of a note one vector stands for. */
    public function chunkChars(): int
    {
        return match ($this) {
            self::OpenAi, self::OpenAiSmall => 6000,
            self::Local => 2400,
        };
    }

    /** A query to a note's nearest piece; farther notes are not a match. */
    public function searchDistance(): float
    {
        return match ($this) {
            self::OpenAi => 0.68,
            self::OpenAiSmall => 0.69,
            self::Local => 0.39,
        };
    }

    /** The farthest a note's kept neighbours reach, and so the widest duplicate search. */
    public function neighbourHorizon(): float
    {
        return match ($this) {
            self::OpenAi => 0.5,
            self::OpenAiSmall => 0.48,
            self::Local => 0.21,
        };
    }

    /**
     * Two notes this close are joined in the map: far looser than a duplicate,
     * because an edge says only that they belong near each other.
     */
    public function graphDistance(): float
    {
        return match ($this) {
            self::OpenAi => 0.42,
            self::OpenAiSmall => 0.39,
            self::Local => 0.17,
        };
    }

    /**
     * A note this close to a draft is named to its writer: looser than a
     * duplicate, because at write time "link rather than merge" is the more
     * useful answer, and past it the band fills with topical neighbours.
     */
    public function hintDistance(): float
    {
        return match ($this) {
            self::OpenAi => 0.20,
            self::OpenAiSmall => 0.17,
            self::Local => 0.10,
        };
    }

    /** The default ceiling for duplicate candidates. */
    public function duplicateDistance(): float
    {
        return match ($this) {
            self::OpenAi => 0.12,
            self::OpenAiSmall => 0.155,
            self::Local => 0.06,
        };
    }

    /** At or under this, a pair is almost always the same document. */
    public function duplicateCertain(): float
    {
        return match ($this) {
            self::OpenAi => 0.10,
            self::OpenAiSmall => 0.14,
            self::Local => 0.045,
        };
    }

    /** Past this, pairs are notes on one topic rather than one document. */
    public function duplicateTopical(): float
    {
        return match ($this) {
            self::OpenAi => 0.15,
            self::OpenAiSmall => 0.19,
            self::Local => 0.08,
        };
    }
}
