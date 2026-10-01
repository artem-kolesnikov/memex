<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AiCredential;
use App\Entity\ProviderSpend;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The one place a provider purchase is written down.
 *
 * Deliberately a service and not a handful of `new ProviderSpend(...)` calls,
 * for the same reason {@see MlClient} is the only thing that reads
 * {@see AiCredentials}: the interesting rules are easy to forget one call site
 * at a time. Here they are the null-versus-zero rule, the fact that a FAILED
 * call is still billed, and the translation of the services' reported usage
 * into a row — none of which a caller should have to remember.
 *
 * ## Recording must never break the thing it is recording
 *
 * Every method swallows its own failures and logs them. This is the opposite of
 * the house style elsewhere and it is the right call exactly once, here: a
 * summary that was written, paid for and returned must not be lost because the
 * bookkeeping row could not be inserted. Losing a row understates a total;
 * throwing loses the user's work AND the money, and the money is gone either
 * way.
 *
 * The failure is loud in the log rather than silent, because a ledger that has
 * quietly stopped writing is worse than one that never existed — it reports
 * small numbers with the same confidence as true ones.
 *
 * ## What it does not do
 *
 * It does not decide whether a call is allowed. That is {@see SpendLimiter},
 * which bounds behaviour using counters in a cache pool, and the two are
 * deliberately separate: a bound that a deploy may reset is the wrong thing to
 * account with, and an append-only ledger is the wrong thing to rate-limit
 * from.
 */
class SpendLedger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * A text or embedding purchase, from the `usage` block ml-processor
     * returns.
     *
     * The usage shape is normalised by the service — `provider`, `model`,
     * `input_tokens`, `output_tokens`, `key` — precisely so this method does
     * not branch per provider. It is validated rather than trusted: the service
     * is on the same box, but a response shape that has drifted should produce
     * a row with nulls in it and a log line, not a TypeError inside somebody's
     * save.
     *
     * Takes the credential's id rather than the entity, because that is what
     * {@see AiCredentials} and {@see SectionCredentials} carry. `getReference()`
     * builds the association from the id without a query.
     *
     * `$succeeded` is false for a call the provider BILLED and that returned
     * nothing usable — an answer with no text in it, a completion cut off at
     * its token cap, tag suggestions that were not valid JSON. Those cost
     * exactly as much as the ones that worked, and they are the ones somebody
     * looking at this screen would want to find.
     *
     * @param array<string, mixed>|null $usage null when the service returned none
     */
    public function recordModelUse(
        string $surface,
        string $operation,
        ?array $usage,
        ?int $credentialId = null,
        bool $succeeded = true,
    ): void {
        if ($usage === null) {
            // Not an error worth a log line on its own: an older ml-processor,
            // or a call that failed before any provider answered. The absence
            // of a row is the honest record of "we do not know".
            return;
        }

        try {
            $provider = is_string($usage['provider'] ?? null) ? $usage['provider'] : 'unknown';
            $model = is_string($usage['model'] ?? null) ? $usage['model'] : null;

            // OBSERVED, never assumed. 'caller' means the account's own key
            // reached the provider; anything else — including a missing field —
            // means the box paid, which is the safe direction to be wrong in:
            // it attributes an unknown to the operator rather than quietly
            // crediting him with somebody else's spend.
            $payer = ($usage['key'] ?? null) === 'caller'
                ? ProviderSpend::PAYER_TEAM
                : ProviderSpend::PAYER_OPERATOR;

            $row = (new ProviderSpend(
                $surface,
                $operation,
                $provider,
                $payer,
            ))
                ->withModel($model)
                ->withTokens(
                    $this->count($usage['input_tokens'] ?? null),
                    $this->count($usage['output_tokens'] ?? null),
                )
                ->withSucceeded($succeeded)
                // The key is recorded only when the account's own one paid. A
                // row that says `operator` must not also name a credential, or
                // a reader has two answers to one question.
                ->withCredential(
                    $payer === ProviderSpend::PAYER_TEAM && $credentialId !== null
                        ? $this->em->getReference(AiCredential::class, $credentialId)
                        : null
                );

            $this->em->persist($row);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->fail($operation, $e);
        }
    }

    /**
     * A reported count, or null.
     *
     * Null and zero are different answers and this is the only place that
     * distinction is enforced: null is "the provider did not say", zero is "it
     * said none". Collapsing them is how `note_embeddings.token_est` became a
     * column that cannot be used for anything.
     */
    private function count(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        return null;
    }

    private function fail(string $operation, \Throwable $e): void
    {
        $this->logger->error('spend ledger could not record {operation}: {message}', [
            'operation' => $operation,
            'message' => $e->getMessage(),
        ]);
    }
}
