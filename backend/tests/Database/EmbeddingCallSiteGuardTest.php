<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\MlClient;
use App\Service\SpendLimiter;
use App\Tests\Support\PhpSource;
use PHPUnit\Framework\TestCase;

/**
 * Every line in this codebase that BUYS an embedding must have a spend limit in
 * the same method. Repo-wide, and read off the parser rather than off a
 * substring search.
 *
 * **This test exists because its predecessor was theatre.** The first version
 * scanned `HybridSearch.php` for `embedQuery(` and asked whether the three
 * preceding lines mentioned `allowSearchEmbedding(`. Codex broke it in one
 * line without trying hard:
 *
 *     // Not using allowSearchEmbedding() here — reusing an existing vector.
 *     $v = $this->mlClient->embedQuery($other);
 *
 * A comment SAYING the guard is absent counted as the guard being present,
 * because `str_contains()` cannot tell a call from a citation. It was also
 * scoped to one file while a second buying call already existed in
 * `CaptureController` — so it could not have caught a sibling call anywhere
 * else even if the text matching had been sound.
 *
 * Both mistakes are the same one, and this project has now made it four times
 * (a guard that counted `OwnerInitiated` in the source; an ERR trap checked
 * with `false` instead of `exit 1`; a `$STAGE` risk named in a comment and then
 * taken anyway): **the verification agreed with the code instead of testing
 * it.** So this version uses `token_get_all()`, where a comment is a token type
 * of its own and cannot be mistaken for code, and walks every file under
 * `src/`. The reading itself is {@see PhpSource}, shared with
 * {@see SpendOrderingGuardTest}.
 *
 * Not an {@see ApiTestCase}: it reads source, touches no database and needs no
 * kernel.
 */
class EmbeddingCallSiteGuardTest extends TestCase
{
    /**
     * The methods on MlClient that spend money at OpenAI. Both go out on the
     * OPERATOR's key for every team, which is the documented exception in
     * CLAUDE.md §Spend and the reason a bound is required rather than nice.
     */
    private const BUYS_AN_EMBEDDING = ['embedQuery', 'embedContent', 'embedContents'];

    public function testEveryEmbeddingCallSiteHasASpendLimitInTheSameMethod(): void
    {
        $sanctioned = $this->spendLimiterMethods();
        $unguarded = [];

        foreach ($this->sourceFiles() as $file) {
            // MlClient DEFINES these; it does not call them.
            if (basename($file) === 'MlClient.php') {
                continue;
            }

            foreach ($this->methodsIn($file) as $method) {
                $buys = array_intersect(self::BUYS_AN_EMBEDDING, $method['calls']);
                if ($buys === []) {
                    continue;
                }
                if (array_intersect($sanctioned, $method['calls']) !== []) {
                    continue;
                }
                $unguarded[] = sprintf(
                    '%s:%d %s() calls %s with no spend limit',
                    basename($file),
                    $method['line'],
                    $method['name'],
                    implode('/', $buys),
                );
            }
        }

        self::assertSame([], $unguarded, "These buy an embedding on the operator's key with nothing bounding them");
    }

    /**
     * The guard must be able to FAIL, and specifically it must fail on the two
     * shapes that defeated its predecessor: a mention of the limiter inside a
     * comment, and a buying call in a file the scan was not pointed at.
     *
     * Both are checked against synthetic source through the same parser the
     * test above uses, because a guard nobody has watched refuse anything is
     * indistinguishable from a guard that cannot.
     */
    public function testAMentionInACommentDoesNotCountAsAGuard(): void
    {
        $source = <<<'PHP'
            <?php
            class Pretender
            {
                public function analyse(): void
                {
                    // Deliberately not calling assertAnalyze() here, see below.
                    /** @see SpendLimiter::allowSearchEmbedding() */
                    $v = $this->mlClient->embedQuery($text);
                }
            }
            PHP;

        $methods = $this->parseMethods($source);
        self::assertCount(1, $methods);
        self::assertContains('embedQuery', $methods[0]['calls'], 'The parser missed the buying call');
        self::assertSame(
            [],
            array_intersect($this->spendLimiterMethods(), $methods[0]['calls']),
            'A limiter named only in a comment was counted as a call'
        );
    }

    /** And the complement: a real call in the same method IS seen. */
    public function testARealGuardCallIsSeen(): void
    {
        $source = <<<'PHP'
            <?php
            class Honest
            {
                public function analyse(): void
                {
                    $this->spendLimiter->assertAnalyze($team);
                    $v = $this->mlClient->embedQuery($text);
                }
            }
            PHP;

        $methods = $this->parseMethods($source);
        self::assertContains('assertAnalyze', $methods[0]['calls']);
        self::assertContains('embedQuery', $methods[0]['calls']);
    }

    /**
     * A guard call inside a CLOSURE still counts for the method holding it, and
     * this is the case that decides whether to take the innermost or outermost
     * enclosing function. Outermost: a limiter asked at the top of a method
     * guards a purchase made in a callback further down.
     */
    public function testAGuardOutsideAClosureStillCoversACallInsideIt(): void
    {
        $source = <<<'PHP'
            <?php
            class Nested
            {
                public function analyse(): void
                {
                    $this->spendLimiter->assertAnalyze($team);
                    $run = function () use ($text) {
                        return $this->mlClient->embedQuery($text);
                    };
                }
            }
            PHP;

        $methods = $this->parseMethods($source);
        $withBuy = array_values(array_filter($methods, static fn (array $m): bool => in_array('embedQuery', $m['calls'], true)));
        self::assertNotSame([], $withBuy, 'The call inside the closure was not attributed to any method');
        self::assertContains('assertAnalyze', $withBuy[0]['calls'], 'The outer guard did not cover the closure');
    }

    /**
     * The names are read off SpendLimiter, so adding a limiter method extends
     * this guard automatically and renaming one cannot leave it matching a
     * string nothing answers to.
     *
     * @return string[]
     */
    private function spendLimiterMethods(): array
    {
        $names = [];
        foreach ((new \ReflectionClass(SpendLimiter::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (preg_match('/^(assert|allow)/', $method->getName())) {
                $names[] = $method->getName();
            }
        }

        self::assertNotSame([], $names, 'SpendLimiter exposes no assert/allow methods — this guard is checking nothing');
        self::assertTrue(method_exists(MlClient::class, 'embedQuery'), 'MlClient::embedQuery is gone; revisit this guard');
        self::assertTrue(method_exists(MlClient::class, 'embedContent'), 'MlClient::embedContent is gone; revisit this guard');

        return $names;
    }

    /** @return string[] */
    private function sourceFiles(): array
    {
        $files = PhpSource::filesUnder(__DIR__.'/../../src');
        self::assertNotSame([], $files, 'No source files found — this guard would pass by default');

        return $files;
    }

    /** @return array<int, array{name: string, line: int, calls: string[]}> */
    private function methodsIn(string $file): array
    {
        return PhpSource::methodsIn($file);
    }

    /** @return array<int, array{name: string, line: int, calls: string[]}> */
    private function parseMethods(string $source): array
    {
        return PhpSource::parseMethods($source);
    }
}
