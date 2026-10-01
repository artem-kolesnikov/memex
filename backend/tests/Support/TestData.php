<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Filesystem\Filesystem;

/**
 * A fresh data directory for every test: its own directory file and vaults,
 * so no test sees another's rows and none needs a transaction to roll back.
 * Set before the kernel boots, which reads MEMEX_DATA_DIR when it builds the
 * storage services.
 */
final class TestData
{
    private static ?string $current = null;

    public static function fresh(): string
    {
        self::discard();
        $root = (string) getenv('MEMEX_TEST_DATA_ROOT');
        $dir = $root.'/'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $_SERVER['MEMEX_DATA_DIR'] = $_ENV['MEMEX_DATA_DIR'] = $dir;
        putenv('MEMEX_DATA_DIR='.$dir);

        return self::$current = $dir;
    }

    public static function discard(): void
    {
        if (self::$current !== null) {
            (new Filesystem())->remove(self::$current);
            self::$current = null;
        }
    }
}
