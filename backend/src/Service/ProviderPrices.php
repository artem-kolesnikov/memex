<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What the rows in `provider_spend` cost the OPERATOR, in dollars.
 *
 * A price table was declined in 2026-08-27 and the objection stands: a stale
 * one reports confident wrong numbers. What changed on 2026-09-08 is the
 * question — with per-person sponsorship the operator has to see, per person,
 * what his account is paying for somebody he invited, and a count of tokens is
 * not an answer to that.
 *
 * The objection is answered by SCOPE rather than by maintenance. Only
 * `payer = operator` rows are priced, and his box holds one account: OpenAI,
 * for embeddings and sponsored text ({@see SystemKeys}). An account paying
 * with its own key is billed by its own vendor, and putting that number in his
 * column would be a claim about somebody else's invoice. So the table below is
 * six models rather than every model every provider has ever sold, and it goes
 * stale in one direction that matters: {@see CHECKED} is rendered beside the
 * total, so a figure is always read as "at these prices" rather than as a
 * bill.
 *
 * A model with no price here contributes nothing and is COUNTED separately by
 * {@see AdminMetrics::costByVault()}, so the screen can say the figure is
 * partial. A silent zero would make the dearest possible mistake, which is a
 * new model looking free.
 */
final class ProviderPrices
{
    /** The day these were read off the vendors' own pricing pages. */
    public const CHECKED = '2026-09-09';

    /** USD per 1,000,000 tokens, `[input, output]`. An embedding has no output. */
    private const TOKENS = [
        AiProviders::OPENAI => [
            'text-embedding-3-large' => [0.13, 0.0],
            'text-embedding-3-small' => [0.02, 0.0],
            'gpt-4o-mini' => [0.15, 0.60],
            'gpt-4o' => [2.50, 10.00],
            'gpt-4.1-mini' => [0.40, 1.60],
            'gpt-4.1-nano' => [0.10, 0.40],
        ],
    ];

    /** @return string[] the OpenAI text models this table prices, which are the ones control offers for this box's key */
    public static function textModels(): array
    {
        return array_values(array_filter(
            array_keys(self::TOKENS[AiProviders::OPENAI]),
            static fn (string $model): bool => !str_starts_with($model, 'text-embedding-'),
        ));
    }

    /**
     * Null when nothing here prices this row: a model this table does not
     * know, or a count the provider never reported.
     */
    public function usd(string $provider, ?string $model, ?int $inputTokens, ?int $outputTokens): ?float
    {
        $rate = self::TOKENS[$provider][$model] ?? null;

        return $rate === null
            ? null
            : ((int) $inputTokens * $rate[0] + (int) $outputTokens * $rate[1]) / 1_000_000;
    }
}
