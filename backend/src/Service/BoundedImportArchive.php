<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Reads a ZIP within the operator's import limit. The central directory's
 * declared sizes are checked first, then every entry is streamed and its
 * length and CRC compared against what was declared, because a declared size
 * is a claim the archive makes about itself.
 */
final class BoundedImportArchive
{
    public const MAX_ENTRIES = 2000;
    public const MAX_NAME_BYTES = 4096;

    /** @return array<int, array{name: string, size: int, crc: int}> keyed by entry index */
    public function preflight(\ZipArchive $zip, int $maxBytes): array
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new \InvalidArgumentException('Archive has more than '.self::MAX_ENTRIES.' entries');
        }
        $entries = [];
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $stat = $zip->statIndex($index);
            if ($stat === false || !isset($stat['name'], $stat['size'], $stat['crc']) || !is_int($stat['size']) || $stat['size'] < 0 || !is_int($stat['crc'])) {
                throw new \InvalidArgumentException('Archive entry metadata is unreadable');
            }
            $name = $stat['name'];
            if (!is_string($name) || strlen($name) > self::MAX_NAME_BYTES || str_contains($name, "\0") || !mb_check_encoding($name, 'UTF-8')) {
                throw new \InvalidArgumentException('Archive entry name must be UTF-8 and at most '.self::MAX_NAME_BYTES.' bytes');
            }
            if (str_ends_with($name, '/')) {
                continue;
            }
            if ($stat['size'] > $maxBytes - $total) {
                throw new \InvalidArgumentException('Archive expanded content exceeds '.$maxBytes.' bytes');
            }
            $total += $stat['size'];
            $entries[$index] = ['name' => $name, 'size' => $stat['size'], 'crc' => $stat['crc']];
        }

        return $entries;
    }

    /** @param array{name: string, size: int, crc: int} $entry */
    public function read(\ZipArchive $zip, int $index, array $entry, int $maxBytes): string
    {
        $stream = @$zip->getStreamIndex($index);
        if ($stream === false) {
            throw new \InvalidArgumentException('Cannot read archive entry: '.$entry['name']);
        }
        $body = '';
        $crc = hash_init('crc32b');
        try {
            while (!feof($stream)) {
                $chunk = @fread($stream, min(65536, $maxBytes - strlen($body) + 1));
                if ($chunk === false || ($chunk === '' && !feof($stream))) {
                    throw new \InvalidArgumentException('Archive entry read failed: '.$entry['name']);
                }
                if (strlen($chunk) > $maxBytes - strlen($body)) {
                    throw new \InvalidArgumentException('Archive entry exceeds '.$maxBytes.' bytes: '.$entry['name']);
                }
                $body .= $chunk;
                hash_update($crc, $chunk);
            }
        } finally {
            fclose($stream);
        }
        if (strlen($body) !== $entry['size'] || hash_final($crc) !== sprintf('%08x', $entry['crc'])) {
            throw new \InvalidArgumentException('Archive entry size or checksum mismatch: '.$entry['name']);
        }
        if (!mb_check_encoding($body, 'UTF-8')) {
            throw new \InvalidArgumentException('Archive entry is not valid UTF-8: '.$entry['name']);
        }

        return $body;
    }
}
