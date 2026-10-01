<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Yaml\Yaml;

/** What the vault inventory tests read in a PHP file, and which source is an edition's. */
final class VaultEntrySource
{
    public static function entersAVault(string $source): bool
    {
        $tokens = self::code($source);
        foreach ($tokens as $i => $token) {
            if (in_array($token, ['VaultScope', 'EveryVault'], true)) {
                return true;
            }
            if ($token === 'bind' && ($tokens[$i - 1] ?? null) === '->' && ($tokens[$i + 1] ?? null) === '(') {
                return true;
            }
        }

        return false;
    }

    public static function opensADatabase(string $source): bool
    {
        $tokens = self::code($source);
        foreach ($tokens as $i => $token) {
            if (in_array($token, ['SQLite3', 'PDO'], true) && ($tokens[$i - 1] ?? null) === 'new') {
                return true;
            }
            if ($token === 'DriverManager' || str_contains($token, 'sqlite:') || preg_match('/^[\'"]\s*ATTACH\b/i', $token) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The source without comments or whitespace, names unqualified.
     *
     * @return list<string>
     */
    public static function code(string $source): array
    {
        $code = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $text = $token[1];
                if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $text = substr((string) strrchr('\\'.$text, '\\'), 1);
                }
                $code[] = $token[0] === T_OBJECT_OPERATOR || $token[0] === T_NULLSAFE_OBJECT_OPERATOR ? '->' : $text;
            } else {
                $code[] = $token;
            }
        }

        return $code;
    }

    /**
     * The source directories an edition registers as its own in
     * config/edition/services.yaml, as `src/Name/`: its tests declare what
     * they enter.
     *
     * @return list<string>
     */
    public static function editionDirs(): array
    {
        $file = \dirname(__DIR__, 2).'/config/edition/services.yaml';
        if (!is_file($file)) {
            return [];
        }
        $dirs = [];
        foreach (Yaml::parseFile($file)['services'] ?? [] as $definition) {
            if (\is_array($definition) && \is_string($definition['resource'] ?? null)) {
                $dirs[] = 'src/'.basename($definition['resource']).'/';
            }
        }

        return $dirs;
    }
}
