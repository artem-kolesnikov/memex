<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Where the core reports a service it depends on failing and working again.
 * $problemKey names the problem, so repeats of it are one problem.
 * {@see HealthLog} writes failures to the log.
 */
interface ServiceHealth
{
    public function serviceFailed(string $problemKey, string $detail): void;

    public function serviceRecovered(string $problemKey): void;
}
