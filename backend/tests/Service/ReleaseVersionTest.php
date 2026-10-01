<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ReleaseVersion;
use PHPUnit\Framework\TestCase;

class ReleaseVersionTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/release-version-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testTheStampTheDeployWroteIsTheVersion(): void
    {
        file_put_contents($this->file, "1.9.4\n");

        self::assertSame('1.9.4', (new ReleaseVersion($this->file))->current());
    }

    public function testACheckoutNobodyReleasedSaysSo(): void
    {
        self::assertSame(ReleaseVersion::UNRELEASED, (new ReleaseVersion($this->file))->current());
    }

    public function testAnEmptyStampIsNoRelease(): void
    {
        file_put_contents($this->file, "\n");

        self::assertSame(ReleaseVersion::UNRELEASED, (new ReleaseVersion($this->file))->current());
    }
}
