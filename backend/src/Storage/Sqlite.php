<?php

declare(strict_types=1);

namespace App\Storage;

final class Sqlite
{
    public const BUSY_TIMEOUT_MS = 5000;

    public static function configure(\SQLite3 $db): void
    {
        $db->enableExceptions(true);
        $db->busyTimeout(self::BUSY_TIMEOUT_MS);
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA synchronous = NORMAL');
        $db->exec('PRAGMA foreign_keys = ON');
        self::foldUnicode($db);
    }

    /**
     * SQLite's own lower(), upper() and LIKE fold ASCII only, so a Cyrillic
     * title would match itself only in the case it was typed. These replace
     * them, on every connection memex opens, with PHP's Unicode case folding.
     * No index or constraint may use them: a file opened without them (the
     * sqlite3 shell) would compute different values.
     */
    private static function foldUnicode(\SQLite3 $db): void
    {
        $db->createFunction('lower', static fn (?string $s): ?string => $s === null ? null : mb_strtolower($s), 1, SQLITE3_DETERMINISTIC);
        $db->createFunction('upper', static fn (?string $s): ?string => $s === null ? null : mb_strtoupper($s), 1, SQLITE3_DETERMINISTIC);
        $like = static fn (?string $pattern, ?string $subject, ?string $escape = null): ?int => $pattern === null || $subject === null
            ? null
            : (int) (preg_match(self::likePattern($pattern, $escape), $subject) === 1);
        $db->createFunction('like', $like, 2, SQLITE3_DETERMINISTIC);
        $db->createFunction('like', $like, 3, SQLITE3_DETERMINISTIC);
    }

    private static function likePattern(string $pattern, ?string $escape): string
    {
        $regex = '';
        $chars = mb_str_split($pattern);
        for ($i = 0, $n = \count($chars); $i < $n; ++$i) {
            $c = $chars[$i];
            if ($escape !== null && $c === $escape && $i + 1 < $n) {
                $regex .= preg_quote($chars[++$i], '/');
            } elseif ($c === '%') {
                $regex .= '.*';
            } elseif ($c === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($c, '/');
            }
        }

        return '/^'.$regex.'$/uis';
    }

    /**
     * sqlite-vec, which `vec0` tables and the vector functions need. PHP 8.3's
     * SQLite3 loads extensions only from `sqlite3.extension_dir`, a php.ini
     * setting; `tools/sqlite-vec.sh` installs the pinned build.
     */
    public static function loadVectors(\SQLite3 $db): void
    {
        if ((string) \ini_get('sqlite3.extension_dir') === '') {
            throw new \RuntimeException('sqlite3.extension_dir is not set, so sqlite-vec cannot load. Install it with backend/tools/sqlite-vec.sh and point sqlite3.extension_dir at that directory.');
        }
        $db->loadExtension(PHP_OS_FAMILY === 'Darwin' ? 'vec0.dylib' : 'vec0.so');
    }
}
