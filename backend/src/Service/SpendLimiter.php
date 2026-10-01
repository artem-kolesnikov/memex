<?php

declare(strict_types=1);

namespace App\Service;

use App\Storage\VaultContext;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * The bound on what one knowledge base can make memex buy (C-1, 2026-08-22).
 *
 * Every write and the near-duplicate check embed at OpenAI on the OPERATOR's
 * key, which is the documented embeddings exception, and both are reachable by
 * any authenticated bearer token, including the agent tokens whose every write
 * is held for review. **The review gate holds the note; it has never held the
 * spend.** An assistant told to "save everything you find" is one loop away
 * from a bill, and nothing said no.
 *
 * That was tolerable while one person held every token. It stops being
 * tolerable at the first invite, which is why this is cluster E work and not
 * an optimisation.
 *
 * The limits themselves are the edition's, resolved by {@see AccountLimits};
 * with none, every check below returns before it counts anything. What lives
 * here is the shape of the refusal: a 429 with `Retry-After`, and a sentence
 * that says what happened rather than a number.
 *
 * The windows are built per request rather than compiled, because a number the
 * operator can change is not a number a container can hold. The counters live
 * in a pool of their own; `config/packages/cache.yaml` says what that costs.
 *
 * **A section running on the account's own key is not limited at all.** The
 * ceiling exists to bound what a stranger's loop can spend of the OPERATOR's
 * money; on their own key the bill is theirs, and a refusal memex invents for
 * it has nothing behind it. That check is here rather than in a controller for
 * the same reason `assertEmbedding` is called from `NoteEnricher` — this is
 * where the money leaves, and a rule applied here cannot be forgotten by the
 * next caller.
 *
 * **That exemption is only sound while the key it names is the key that pays**,
 * and since 2026-09-08 it is: `MlClient` sends the vault's OpenAI key with every
 * embedding, resolved from the same {@see EnrichmentSettings} answer this class
 * exempts on. A pointer at a key that will not decrypt resolves to none on both sides,
 * so the ceiling comes back at the same moment the operator starts paying
 * again.
 *
 * **What that cannot fix is a request that changes the answer between the two.**
 * A check and an act are two things, so a method that repoints a section and
 * then spends would be exempted on one answer and sent on another, however
 * fresh both reads are. Nothing composes that sequence, and
 * {@see \App\Tests\Database\SpendOrderingGuardTest} is what keeps it that
 * way — the settings pane that offers these keys is exactly the route that
 * would.
 */
class SpendLimiter
{
    public function __construct(
        private readonly AccountLimits $limits,
        // The pool named in config/packages/cache.yaml, autowired by its own
        // name. Not `cache.rate_limiter`: that one belongs to the framework's
        // limiters and a reference to it by hand resolves to a different
        // namespace than the one the tests clear, which is a limit that
        // silently survives between tests.
        private readonly CacheItemPoolInterface $cacheSpendLimits,
        private readonly LockFactory $lockFactory,
        private readonly VaultContext $context,
    ) {
    }

    /**
     * One click of Analyze: an embedding on the operator's key, every time,
     * and — when the vault's credentials will generate — three text calls too.
     *
     * **Both ceilings are admitted before either is consumed.** They were two
     * calls in the controller, and a click that passed the analyze ceiling and
     * failed the text one spent the analyze allowance on a request that bought
     * nothing; repeated refusals drained a counter with no purchase behind
     * them. Reversing the order only moves that onto the other counter, so the
     * admission is joined here (Codex, 2026-09-10).
     *
     * @param bool $withText whether the text calls on this click will actually
     *                       generate — `MlClient` refuses them for a vault whose
     *                       credentials say no, and a ceiling charged for a call
     *                       that never happens lies
     */
    public function assertAnalyze(bool $withText = false): void
    {
        $allowance = $this->limits->spend();

        $ceilings = [];
        if (!$allowance->isUnlimited() && !$allowance->ownEmbedKey) {
            $ceilings[] = [
                $this->windows('ai_analyze', $allowance->analyzeHourly, $allowance->analyzeDaily),
                'analyze',
                'Analyze has been used as many times as this knowledge base may for now.',
            ];
        }
        if ($withText && !$allowance->isUnlimited() && !$allowance->ownTextKey) {
            $ceilings[] = [
                $this->windows('ai_text', $allowance->textHourly, $allowance->textDaily),
                'text',
                'memex has written as many descriptions for this knowledge base as it may for now.',
            ];
        }

        $this->assertAll($ceilings);
    }

    /**
     * One round of server-side TEXT: a summary, a title, or a tag suggestion.
     *
     * Reachable on this box's account only through sponsorship — an account on
     * its own key is exempt here like everywhere else, and an account with
     * neither runs no server-side text at all. That made it look like the one
     * surface not worth a ceiling, and it was the one with a spend BEFORE any
     * check: `NoteEnricher::enrich()` bought the summary and then let
     * `assertEmbedding` refuse, so a sponsored account past its allowance paid
     * for a summary on every note and was told no afterwards (found by Codex,
     * 2026-09-10).
     *
     * Called once per note rather than once per provider call. The summary and
     * the tag suggestion are two requests, and metering them separately would
     * refuse halfway through a note and store half its enrichment.
     */
    public function assertText(): void
    {
        $allowance = $this->limits->spend();
        if ($allowance->isUnlimited() || $allowance->ownTextKey) {
            return;
        }

        $this->assert(
            'text',
            $this->windows('ai_text', $allowance->textHourly, $allowance->textDaily),
            'memex has written as many descriptions for this knowledge base as it may for now.',
        );
    }

    /**
     * One embedding bought because a WRITE asked for it.
     *
     * Called from the single line that buys one — `NoteEnricher` immediately
     * before `MlClient::embedContent()` — rather than from the controllers.
     * That is the correction this method exists for: `assertAnalyze` is
     * enforced at its one call site, and the embedding path had five, of
     * which four were missed. A limit applied
     * where the money leaves cannot be forgotten by the next caller, which is
     * the same argument CLAUDE.md §Spend makes for AiCredentials.
     *
     * Not charged when nothing is bought: the caller checks its
     * embedded-text hash first and returns early on an unchanged note, so a
     * re-save that changes a tag consumes nothing.
     *
     * {@see EmbeddingSpend} for the one case that is deliberately not metered.
     */
    public function assertEmbedding(): void
    {
        $allowance = $this->limits->spend();
        if ($allowance->isUnlimited() || $allowance->ownEmbedKey) {
            return;
        }

        $this->assert(
            'embed',
            $this->windows('ai_embed', $allowance->embedHourly, $allowance->embedDaily),
            'This knowledge base has stored as many new and edited notes as it may for now. Nothing is lost — try again shortly.',
        );
    }

    /**
     * The embedding a SEARCH buys, and the one refusal in this class that is
     * not a refusal (AUDIT3, 2026-08-25).
     *
     * `HybridSearch` reaches OpenAI only when the query matches nothing
     * literally — a query whose words appear in some note the caller may see
     * is answered from the full-text index and costs nothing. So the ordinary
     * use of the search box is mostly free, and what is left is exactly the
     * shape a limiter is for: random strings never match literally, so a loop
     * buys one embedding per request, unbounded, on any bearer token.
     *
     * **It returns false instead of throwing, and that difference is the whole
     * design.** The other three paths here guard a write or a button: refusing
     * one costs the caller nothing, because the note is not lost and the button
     * can be pressed again. Search is the READ path, and the key is the VAULT.
     * Throwing here would mean one agent loop locks the owner out of their own
     * search box, which is a worse outcome than the spend it prevents.
     *
     * Degrading instead is not a new branch: `$queryVector === null` is a state
     * `HybridSearch` already handles and {@see \App\Tests\Database\MlOutageTest}
     * already covers, because it is what an ml-processor outage looks like from
     * inside the query. Over-budget is the same state arrived at deliberately.
     * The caller is TOLD (`semantic_unavailable` on the result) — a degraded
     * search that reports itself is a feature working less well, and a silent
     * one is a search that lies about having found nothing.
     *
     * @return bool true if the caller may buy one embedding, false if it must
     *              fall back to keyword-only
     */
    public function allowSearchEmbedding(): bool
    {
        $allowance = $this->limits->spend();
        if ($allowance->isUnlimited() || $allowance->ownEmbedKey) {
            return true;
        }

        return $this->take(
            'search-embed',
            $this->windows('ai_search_embed', $allowance->searchHourly, $allowance->searchDaily),
        ) === null;
    }

    /**
     * What is left of today on each surface, without consuming any of it.
     *
     * The screens show a daily figure alone (operator, 2026-09-10). The hourly
     * window is still enforced — it is what actually stops a loop inside the
     * hour — but it is not a number anybody can act on, and two counters where
     * one is meaningful reads as a budget rather than as a loop-stopper.
     *
     * Null means no ceiling applies: an administrative account, or a surface
     * running on the account's own key. A zero would be a lie in both cases.
     *
     * `consume(0)` peeks. {@see take()} explains why the remaining count is the
     * only honest thing to read off one.
     *
     * @return array{embed: ?int, analyze: ?int, search: ?int, text: ?int}
     */
    public function dailyLeft(): array
    {
        $allowance = $this->limits->spend();
        $free = $allowance->isUnlimited();

        $left = function (string $id, int $daily, string $key): int {
            $peek = $this->window($id.'_daily', $daily, '1 day')->create($this->counter($key))->consume(0);

            return max(0, $peek->getRemainingTokens());
        };

        return [
            'embed' => $free || $allowance->ownEmbedKey ? null : $left('ai_embed', $allowance->embedDaily, 'embed'),
            'analyze' => $free || $allowance->ownEmbedKey ? null : $left('ai_analyze', $allowance->analyzeDaily, 'analyze'),
            'text' => $free || $allowance->ownTextKey ? null : $left('ai_text', $allowance->textDaily, 'text'),
            'search' => $free || $allowance->ownEmbedKey ? null : $left('ai_search_embed', $allowance->searchDaily, 'search-embed'),
        ];
    }

    /**
     * The hourly and daily windows for one surface, built from the numbers the
     * tier carries rather than from a compiled factory.
     *
     * A sliding window stores hits and compares them to the limit at read time,
     * which is why raising a tier in control takes effect on the next request
     * rather than at the next window boundary — and why lowering one can refuse
     * a caller who was inside the old number a moment ago.
     *
     * The lock is the same one the yaml asked for, and for the same reason: the
     * caller here is an agent loop that can open twenty connections at once,
     * and without one every request reads the counter before any of them writes
     * it. The sign-in limiters have no lock because there the job is stopping a
     * script rather than stopping a race.
     *
     * Factories rather than limiters, because the per-vault key is applied by
     * `create($key)` in {@see take()} — building the limiter here would key
     * every vault's counter to the same window.
     *
     * @return array<string, array{RateLimiterFactory, int}> each window with the limit it enforces
     */
    private function windows(string $id, int $hourly, int $daily): array
    {
        return [
            'hour' => [$this->window($id, $hourly, '1 hour'), max(1, $hourly)],
            'day' => [$this->window($id.'_daily', $daily, '1 day'), max(1, $daily)],
        ];
    }

    private function window(string $id, int $limit, string $interval): RateLimiterFactory
    {
        return new RateLimiterFactory(
            [
                'id' => $id,
                'policy' => 'sliding_window',
                'limit' => max(1, $limit),
                'interval' => $interval,
            ],
            new CacheStorage($this->cacheSpendLimits),
            $this->lockFactory,
        );
    }

    /**
     * Peek at every window before consuming from any of them.
     *
     * `consume(0)` asks without taking. Consuming straight through the list
     * would charge the hourly window for a request the daily one is about to
     * refuse — so a knowledge base that hit its daily limit would go on burning
     * hourly allowance it could not use, and the hour it came back would start
     * short. Ask first, then take from both.
     *
     * **Read `getRemainingTokens()`, not `isAccepted()`, on a peek.**
     * `SlidingWindowLimiter::reserve()` returns early for a zero-token request
     * and hard-codes `true` there, so a peek always reports itself accepted —
     * a check written the obvious way is a limiter that never refuses
     * anything. The remaining count is the real answer, and the reset time it
     * carries is correct.
     *
     * @param array<string, array{RateLimiterFactory, int}> $limiters
     */
    private function assert(string $surface, array $limiters, string $message): void
    {
        $seconds = $this->take($surface, $limiters);
        if ($seconds !== null) {
            throw new TooManyRequestsHttpException(
                $seconds,
                $message.' It will work again '.$this->inWords($seconds).'.',
            );
        }
    }

    /**
     * The peek-then-consume itself, with no opinion about what a refusal
     * means.
     *
     * Split out of `assert()` for {@see allowSearchEmbedding()}, which refuses
     * by degrading rather than by throwing. The split is not tidiness: the
     * `getRemainingTokens()` subtlety documented above is the kind of thing
     * that gets re-derived WRONG when it is copied, and there is now one
     * copy of it rather than two.
     *
     * @param array<string, array{RateLimiterFactory, int}> $limiters
     *
     * @return int|null null = allowed and consumed; otherwise the seconds until
     *                  the caller may try again
     */
    private function take(string $surface, array $limiters): ?int
    {
        $seconds = $this->peek($surface, $limiters);
        if ($seconds !== null) {
            return $seconds;
        }

        $this->consume($limiters, $this->counter($surface));

        return null;
    }

    /**
     * Several ceilings, all admitted before any is consumed.
     *
     * @param list<array{array<string, array{RateLimiterFactory, int}>, string, string}> $ceilings limiters, surface, refusal
     */
    private function assertAll(array $ceilings): void
    {
        foreach ($ceilings as [$limiters, $surface, $message]) {
            $seconds = $this->peek($surface, $limiters);
            if ($seconds !== null) {
                throw new TooManyRequestsHttpException(
                    $seconds,
                    $message.' It will work again '.$this->inWords($seconds).'.',
                );
            }
        }

        foreach ($ceilings as [$limiters, $surface]) {
            $this->consume($limiters, $this->counter($surface));
        }
    }

    /**
     * The one place a refusal is decided, so the one place the edition hears
     * about it.
     *
     * @param array<string, array{RateLimiterFactory, int}> $limiters
     *
     * @return int|null null = there is room; otherwise the seconds until there is
     */
    private function peek(string $surface, array $limiters): ?int
    {
        foreach ($limiters as $window => [$limiter, $limit]) {
            $peek = $limiter->create($this->counter($surface))->consume(0);
            if ($peek->getRemainingTokens() < 1) {
                $this->limits->spendLimitReached($surface, $window, $limit);
                $retryAfter = $peek->getRetryAfter();

                return max(1, $retryAfter->getTimestamp() - time());
            }
        }

        return null;
    }

    /** @param array<string, array{RateLimiterFactory, int}> $limiters */
    private function consume(array $limiters, string $key): void
    {
        foreach ($limiters as [$limiter]) {
            $limiter->create($key)->consume();
        }
    }

    /** One surface's counter for the bound vault. */
    private function counter(string $surface): string
    {
        return $surface.'-'.$this->context->current()->key;
    }

    /**
     * "in about an hour", not "in 3541 seconds". This sentence is read by a
     * person who has just been refused something, and by an assistant that has
     * to decide whether to wait or to tell its user.
     */
    private function inWords(int $seconds): string
    {
        if ($seconds < 90) {
            return 'in a minute';
        }
        if ($seconds < 3600) {
            return 'in about '.(int) ceil($seconds / 60).' minutes';
        }
        if ($seconds < 86400) {
            $hours = (int) ceil($seconds / 3600);

            return 'in about '.($hours === 1 ? 'an hour' : $hours.' hours');
        }

        return 'tomorrow';
    }
}
