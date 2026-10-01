<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;

final class HealthLog implements ServiceHealth
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function serviceFailed(string $problemKey, string $detail): void
    {
        $this->logger->warning('A service memex depends on failed', ['problem' => $problemKey, 'detail' => $detail]);
    }

    public function serviceRecovered(string $problemKey): void
    {
    }
}
