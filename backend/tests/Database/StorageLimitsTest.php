<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\StorageLimits;

final class StorageLimitsTest extends DatabaseTestCase
{
    public function testDefaultsAndStrictAdjustment(): void
    {
        $limits = self::getContainer()->get(StorageLimits::class);
        self::assertSame(['max_note_bytes' => 2097152, 'max_import_bytes' => 104857600], $limits->current());
        self::assertSame(4096, $limits->update(['max_note_bytes' => 4096])['max_note_bytes']);
        self::assertSame(104857600, $limits->current()['max_import_bytes']);
        foreach (['max_notes' => 5, 'max_note_bytes' => 0, 'max_import_bytes' => StorageLimits::CEILINGS['max_import_bytes'] + 1] as $key => $value) {
            try {
                $limits->update([$key => $value]);
                self::fail($key.' accepted '.$value);
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame(['max_note_bytes' => 4096, 'max_import_bytes' => 104857600], $limits->current());
    }

    public function testMissingPolicyIsVisibleNotDefaults(): void
    {
        self::getContainer()->get('doctrine.dbal.directory_connection')->executeStatement('DELETE FROM storage_policy');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Storage policy is missing');
        self::getContainer()->get(StorageLimits::class)->current();
    }
}
