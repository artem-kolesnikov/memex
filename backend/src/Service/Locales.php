<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The interface languages this box offers beyond English.
 *
 * English is bundled with the SPA and is the fallback for every key a file
 * leaves out, so a language here may be partial and still be offered. What is
 * stored is the operator's own file, verbatim: the server checks that it is a
 * JSON object whose leaves are all strings and nothing more, because the keys
 * belong to the frontend build and only the frontend can say which are current.
 */
final class Locales
{
    public const BUNDLED = 'en';

    /** BCP 47-ish: a language, optionally a region. `ru`, `pt-br`, `zh-hant`. */
    public const CODE = '/^[a-z]{2,3}(-[a-z]{2,4})?\z/';

    /** More than any interface needs; a limit so a stray upload cannot fill the disk. */
    private const MAX_BYTES = 2 * 1024 * 1024;

    /** What a key may look like: the shape every key in the bundled English has. */
    private const KEY = '/^[A-Za-z0-9_-]+\z/';

    /** Names that reach Object.prototype when the file becomes a JavaScript object. */
    private const FORBIDDEN_KEYS = ['__proto__', 'constructor', 'prototype'];

    /**
     * vue-i18n's linked-message syntax, `@:other.key` and `@.upper:other.key`,
     * makes one message render another. A file may not do that: a link to
     * itself recurses until the page throws, and a link to any other key
     * shows words the translator never wrote. A literal @ is written {'@'}.
     */
    private const LINKED = '/@(?:\.[a-z]+)?:/';

    public function __construct(private readonly string $localesDir)
    {
    }

    public static function isCode(string $code): bool
    {
        return preg_match(self::CODE, $code) === 1;
    }

    /** @return list<array{code: string, keys: int, updated_at: string}> */
    public function installed(): array
    {
        $out = [];
        foreach (glob($this->localesDir.'/*.json') ?: [] as $path) {
            $code = basename($path, '.json');
            if (!self::isCode($code)) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($path), true);
            $out[] = [
                'code' => $code,
                'keys' => is_array($decoded) ? self::countLeaves($decoded) : 0,
                'updated_at' => (new \DateTimeImmutable('@'.filemtime($path)))->format(\DateTimeInterface::ATOM),
            ];
        }
        usort($out, static fn (array $a, array $b) => strcmp($a['code'], $b['code']));

        return $out;
    }

    public function isAvailable(string $code): bool
    {
        return $code === self::BUNDLED || (self::isCode($code) && is_file($this->path($code)));
    }

    /** The file as uploaded, or null when that language is not installed. */
    public function read(string $code): ?string
    {
        if (!self::isCode($code) || !is_file($this->path($code))) {
            return null;
        }

        return (string) file_get_contents($this->path($code));
    }

    /**
     * Store a language, or say why not.
     *
     * @return array{0: int|null, 1: string|null} the number of keys stored, or null and the refusal
     */
    public function install(string $code, string $json): array
    {
        if ($code === self::BUNDLED) {
            return [null, 'English is bundled with memex and cannot be replaced.'];
        }
        if (!self::isCode($code)) {
            return [null, 'A language code is two or three letters, optionally followed by a region: ru, pt-br.'];
        }
        if (strlen($json) > self::MAX_BYTES) {
            return [null, 'That file is larger than any translation needs to be.'];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            return [null, 'The file must be a JSON object of keys and their translations.'];
        }
        $bad = self::firstNonString($decoded);
        if ($bad !== null) {
            return [null, sprintf('Every translation must be text; "%s" is not.', $bad)];
        }
        $bad = self::firstBadKey($decoded);
        if ($bad !== null) {
            return [null, sprintf('"%s" is not a key memex can use.', $bad)];
        }
        $bad = self::firstLinked($decoded);
        if ($bad !== null) {
            return [null, sprintf('"%s" links to another message with @: — write the words out instead.', $bad)];
        }

        if (!is_dir($this->localesDir) && !mkdir($this->localesDir, 0775, true) && !is_dir($this->localesDir)) {
            return [null, 'The languages directory cannot be created on this server.'];
        }
        $tmp = tempnam($this->localesDir, $code.'.');
        if ($tmp === false || file_put_contents($tmp, $json) === false || !rename($tmp, $this->path($code))) {
            if ($tmp !== false) {
                @unlink($tmp);
            }

            return [null, 'The file could not be written on this server.'];
        }
        chmod($this->path($code), 0664);

        return [self::countLeaves($decoded), null];
    }

    public function remove(string $code): bool
    {
        if (!self::isCode($code) || !is_file($this->path($code))) {
            return false;
        }

        return unlink($this->path($code));
    }

    private function path(string $code): string
    {
        return $this->localesDir.'/'.$code.'.json';
    }

    private static function countLeaves(array $tree): int
    {
        $n = 0;
        foreach ($tree as $value) {
            $n += is_array($value) ? self::countLeaves($value) : 1;
        }

        return $n;
    }

    private static function firstBadKey(array $tree, string $prefix = ''): ?string
    {
        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (!is_string($key) || preg_match(self::KEY, $key) !== 1 || in_array($key, self::FORBIDDEN_KEYS, true)) {
                return $path;
            }
            if (is_array($value)) {
                $inner = self::firstBadKey($value, $path);
                if ($inner !== null) {
                    return $inner;
                }
            }
        }

        return null;
    }

    private static function firstLinked(array $tree, string $prefix = ''): ?string
    {
        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $inner = self::firstLinked($value, $path);
                if ($inner !== null) {
                    return $inner;
                }
            } elseif (is_string($value) && preg_match(self::LINKED, $value) === 1) {
                return $path;
            }
        }

        return null;
    }

    /** The dotted path of the first leaf that is not a string, or null when all are. */
    private static function firstNonString(array $tree, string $prefix = ''): ?string
    {
        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                if ($value === []) {
                    return $path;
                }
                $inner = self::firstNonString($value, $path);
                if ($inner !== null) {
                    return $inner;
                }
                continue;
            }
            if (!is_string($value)) {
                return $path;
            }
        }

        return null;
    }
}
