<?php

declare(strict_types=1);

namespace App\Service;

final class NoLimits implements AccountLimits
{
    public function spend(): SpendAllowance
    {
        return SpendAllowance::unlimited();
    }

    public function room(): array
    {
        return ['notes' => null, 'connections' => null];
    }

    public function includedText(): array
    {
        return ['on' => false, 'model' => null];
    }

    public function embeddingModel(): ?EmbeddingModel
    {
        return null;
    }

    public function spendLimitReached(string $surface, string $window, int $limit): void
    {
    }

    public function roomReached(string $dimension, int $limit): void
    {
    }
}
