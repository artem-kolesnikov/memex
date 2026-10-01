<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Cuts a note into the pieces that get embedded, one vector each.
 *
 * A note that collapses to at most the model's piece size
 * ({@see EmbeddingModel::chunkChars()}) is one chunk whose text is
 * exactly what the whole-note embedding used to be, so its hash is unchanged
 * and nothing is re-bought for it. A longer note is cut on its markdown
 * headings, consecutive sections packed until the next would not fit, a
 * section longer than a chunk split on its paragraphs, and a paragraph longer
 * than a chunk split hard. Every chunk starts with the note's title and the
 * heading it sits under, because a vector of "the gate holds the note, never
 * the spend" with nothing naming memex is a vector of nothing in particular.
 */
class NoteChunker
{
    /**
     * @return list<array{heading: ?string, text: string}> never empty for a
     *                                                      note with any text
     */
    public function chunks(string $title, string $bodyMd, int $size): array
    {
        $whole = self::collapse($title.' '.$bodyMd);
        $title = self::collapse($title);
        if ($whole === '') {
            return [];
        }
        if (mb_strlen($whole, 'UTF-8') <= $size) {
            return [['heading' => null, 'text' => $whole]];
        }

        $chunks = [];
        $open = null;
        foreach ($this->sections($bodyMd) as [$heading, $body]) {
            $prefix = $title.($heading === null ? '' : ' — '.$heading).' ';
            foreach ($this->pieces($body, $size - mb_strlen($prefix, 'UTF-8')) as $piece) {
                if ($piece === '') {
                    continue;
                }
                $candidate = $open === null ? $prefix.$piece : $open['text'].' '.$piece;
                if ($open !== null && $open['heading'] === $heading && mb_strlen($candidate, 'UTF-8') <= $size) {
                    $open['text'] = $candidate;
                    continue;
                }
                if ($open !== null) {
                    $chunks[] = $open;
                }
                $open = ['heading' => $heading, 'text' => $prefix.$piece];
            }
        }
        if ($open !== null) {
            $chunks[] = $open;
        }

        return $chunks === [] ? [['heading' => null, 'text' => $whole]] : $chunks;
    }

    /**
     * The body split on ATX headings outside fenced code, each section carrying
     * the heading it sits under. Text before the first heading has none.
     *
     * @return list<array{0: ?string, 1: string}>
     */
    private function sections(string $body): array
    {
        $sections = [];
        $heading = null;
        $lines = [];
        $inFence = false;
        foreach (preg_split('/\R/u', $body) ?: [] as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $inFence = !$inFence;
            } elseif (!$inFence && preg_match('/^#{1,6}\s+(.+?)\s*#*\s*$/u', $line, $m) === 1) {
                $sections[] = [$heading, implode("\n", $lines)];
                $heading = self::collapse($m[1]);
                $lines = [];
                continue;
            }
            $lines[] = $line;
        }
        $sections[] = [$heading, implode("\n", $lines)];

        return $sections;
    }

    /**
     * One section as pieces that each fit in $limit characters after
     * collapsing: the whole section when it fits, else its paragraphs packed
     * greedily, a paragraph past the limit cut hard.
     *
     * @return list<string>
     */
    private function pieces(string $section, int $limit): array
    {
        $limit = max(200, $limit);
        $whole = self::collapse($section);
        if (mb_strlen($whole, 'UTF-8') <= $limit) {
            return [$whole];
        }

        $pieces = [];
        $open = '';
        foreach (preg_split('/\n\s*\n/u', $section) ?: [] as $paragraph) {
            $paragraph = self::collapse($paragraph);
            if ($paragraph === '') {
                continue;
            }
            while (mb_strlen($paragraph, 'UTF-8') > $limit) {
                if ($open !== '') {
                    $pieces[] = $open;
                    $open = '';
                }
                // Cut at the last space before the limit when there is one in
                // the second half, so a hard split still keeps words whole.
                $cut = mb_strrpos(mb_substr($paragraph, 0, $limit, 'UTF-8'), ' ', 0, 'UTF-8');
                if ($cut === false || $cut < intdiv($limit, 2)) {
                    $cut = $limit;
                }
                $pieces[] = trim(mb_substr($paragraph, 0, $cut, 'UTF-8'));
                $paragraph = trim(mb_substr($paragraph, $cut, null, 'UTF-8'));
            }
            if ($paragraph === '') {
                continue;
            }
            $joined = $open === '' ? $paragraph : $open.' '.$paragraph;
            if (mb_strlen($joined, 'UTF-8') <= $limit) {
                $open = $joined;
            } else {
                $pieces[] = $open;
                $open = $paragraph;
            }
        }
        if ($open !== '') {
            $pieces[] = $open;
        }

        return $pieces;
    }

    /** Tags stripped and whitespace collapsed, exactly as the whole-note text always was. */
    public static function collapse(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
    }
}
