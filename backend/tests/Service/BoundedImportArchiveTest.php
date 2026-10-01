<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BoundedImportArchive;
use PHPUnit\Framework\TestCase;

final class BoundedImportArchiveTest extends TestCase
{
    /** @var string[] */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            unlink($path);
        }
    }

    /** @param array<string, string> $files */
    private function zip(array $files): \ZipArchive
    {
        $path = tempnam(sys_get_temp_dir(), 'bounded-zip-');
        $this->paths[] = $path;
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
        foreach ($files as $name => $body) {
            $zip->addFromString($name, $body);
        }
        self::assertTrue($zip->close());
        self::assertTrue($zip->open($path));

        return $zip;
    }

    public function testPreflightCountsHiddenAndUnsupportedEntriesAtTheExactBoundary(): void
    {
        $zip = $this->zip(['ok.md' => 'abc', '.hidden/no.bin' => 'xyz', 'junk.png' => '12']);
        $archive = new BoundedImportArchive();
        self::assertCount(3, $archive->preflight($zip, 8));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expanded content exceeds');
        try {
            $archive->preflight($zip, 7);
        } finally {
            $zip->close();
        }
    }

    public function testReadDoesNotTrustTheDeclaredSize(): void
    {
        $zip = $this->zip(['body.md' => str_repeat('x', 100)]);
        $archive = new BoundedImportArchive();
        $entry = $archive->preflight($zip, 100)[0];
        $entry['size'] = 10;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('entry exceeds 10 bytes');
        try {
            $archive->read($zip, 0, $entry, 10);
        } finally {
            $zip->close();
        }
    }

    public function testChecksumMismatchIsRefused(): void
    {
        $zip = $this->zip(['body.md' => 'hello']);
        $archive = new BoundedImportArchive();
        $entry = $archive->preflight($zip, 5)[0];
        $entry['crc'] = 0;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('checksum mismatch');
        try {
            $archive->read($zip, 0, $entry, 5);
        } finally {
            $zip->close();
        }
    }

    public function testUnreadableEntryIsNamed(): void
    {
        $zip = $this->zip(['body.md' => 'hello']);
        $archive = new BoundedImportArchive();
        $entry = $archive->preflight($zip, 5)[0];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot read archive entry');
        try {
            $archive->read($zip, 99, $entry, 5);
        } finally {
            $zip->close();
        }
    }

    public function testEntryNamesAreBounded(): void
    {
        $zip = $this->zip([str_repeat('a', 4097).'.md' => 'x']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('4096 bytes');
        try {
            (new BoundedImportArchive())->preflight($zip, 1);
        } finally {
            $zip->close();
        }
    }
}
