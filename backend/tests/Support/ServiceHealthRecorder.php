<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\ServiceHealth;

/**
 * What the core reported to the edition's {@see ServiceHealth}, and is still
 * failing, by problem: a core test reads this rather than any edition's own
 * record of it.
 */
final class ServiceHealthRecorder implements ServiceHealth
{
    /** @var array<string, array{detail: string, occurrences: int}> */
    public array $failing = [];

    public function __construct(private readonly ServiceHealth $inner)
    {
    }

    public function serviceFailed(string $problemKey, string $detail): void
    {
        $this->failing[$problemKey] = ['detail' => $detail, 'occurrences' => ($this->failing[$problemKey]['occurrences'] ?? 0) + 1];
        $this->inner->serviceFailed($problemKey, $detail);
    }

    public function serviceRecovered(string $problemKey): void
    {
        unset($this->failing[$problemKey]);
        $this->inner->serviceRecovered($problemKey);
    }
}
