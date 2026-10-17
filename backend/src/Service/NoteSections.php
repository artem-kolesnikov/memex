<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A note's sections, found by their Markdown headings, so an assistant can
 * read one part of a long note or change one without quoting its text.
 *
 * A section is its heading line and everything under it up to the next
 * heading of the same or a higher level, its subsections included. Only `#`
 * headings count, and none inside a fenced code block, where `# comment` is
 * code.
 */
final class NoteSections
{
    /** How many headings an error lists before it stops. */
    private const LISTED = 20;

    /**
     * Every heading, in order, with where its section starts and ends.
     *
     * @return list<array{level: int, heading: string, start: int, body_start: int, end: int}>
     */
    public static function all(string $body): array
    {
        $headings = [];
        $fence = null;
        $offset = 0;
        foreach (preg_split('/(?<=\n)/', $body) ?: [] as $line) {
            $length = strlen($line);
            $text = rtrim($line, "\r\n");
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $text, $m) === 1) {
                if ($fence === null) {
                    $fence = $m[1];
                } elseif ($m[1][0] === $fence[0] && strlen($m[1]) >= strlen($fence) && trim(substr(ltrim($text), strlen($m[1]))) === '') {
                    $fence = null;
                }
            } elseif ($fence === null && preg_match('/^ {0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/', $text, $m) === 1) {
                $headings[] = [
                    'level' => strlen($m[1]),
                    'heading' => trim($m[2] ?? ''),
                    'start' => $offset,
                    'body_start' => $offset + $length,
                    'end' => strlen($body),
                ];
            }
            $offset += $length;
        }
        foreach ($headings as $i => $heading) {
            for ($j = $i + 1; $j < count($headings); ++$j) {
                if ($headings[$j]['level'] <= $heading['level']) {
                    $headings[$i]['end'] = $headings[$j]['start'];
                    break;
                }
            }
        }

        return $headings;
    }

    /**
     * The headings as a reader sees them, to choose a section from.
     *
     * @return list<array{level: int, heading: string}>
     */
    public static function outline(string $body): array
    {
        return array_map(
            static fn (array $h): array => ['level' => $h['level'], 'heading' => $h['heading']],
            self::all($body),
        );
    }

    /**
     * The one section a heading names, matched without regard to case,
     * spacing or the `#` marks, so "## Sources" and "sources" name the same.
     *
     * @return array{level: int, heading: string, start: int, body_start: int, end: int}
     *
     * @throws \InvalidArgumentException when no heading, or more than one, is named so
     */
    public static function locate(string $body, string $section): array
    {
        $wanted = self::key($section);
        if ($wanted === '') {
            throw new \InvalidArgumentException('A section is named by its heading, and this one is empty.');
        }
        $headings = self::all($body);
        $matches = array_values(array_filter($headings, static fn (array $h): bool => self::key($h['heading']) === $wanted));
        if (count($matches) === 1) {
            return $matches[0];
        }
        $named = '“'.trim(ltrim(trim($section), '#')).'”';
        if ($matches !== []) {
            throw new \InvalidArgumentException('The heading '.$named.' appears '.count($matches).' times in the note. Name the place with `find` instead, quoting text from the section you mean.');
        }
        if ($headings === []) {
            throw new \InvalidArgumentException('The note has no headings, so no section is named '.$named.'.');
        }
        $listed = array_map(static fn (array $h): string => '“'.$h['heading'].'”', array_slice($headings, 0, self::LISTED));

        throw new \InvalidArgumentException('No heading '.$named.' in the note. Its headings: '.implode(', ', $listed).(count($headings) > self::LISTED ? ', …' : '').'.');
    }

    private static function key(string $heading): string
    {
        $heading = preg_replace('/^#+\s*|\s+#+$/', '', trim($heading)) ?? $heading;

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $heading) ?? $heading));
    }
}
