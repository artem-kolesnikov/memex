<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The one sentence the editor's assistant says about Analyze: whether its
 * summaries and titles run on the account's own key, on this box's key under
 * the tier's allowance and which model, or not at all.
 */
final class AnalyzeAllowance
{
    public const OWN = 'own';
    public const INCLUDED = 'included';
    public const NONE = 'none';

    public function __construct(
        private readonly EnrichmentSettings $settings,
        private readonly SpendLimiter $limiter,
    ) {
    }

    /**
     * `left` counts clicks: one Analyze spends one of the Analyze allowance
     * and one of the descriptions allowance, so the smaller is what remains.
     *
     * @return array{suggestions: string, model: ?string, left: ?int}
     */
    public function current(): array
    {
        $creds = $this->settings->forVault();
        if (!$creds->textEnabled) {
            return ['suggestions' => self::NONE, 'model' => null, 'left' => null];
        }
        if ($creds->isOwnAccount()) {
            return ['suggestions' => self::OWN, 'model' => null, 'left' => null];
        }
        $daily = $this->limiter->dailyLeft();
        $left = array_filter([$daily['analyze'], $daily['text']], static fn (?int $n): bool => $n !== null);

        return ['suggestions' => self::INCLUDED, 'model' => $creds->model, 'left' => $left === [] ? null : min($left)];
    }
}
