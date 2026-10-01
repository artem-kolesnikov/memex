<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Directory\Account;
use App\Entity\VaultSettings;
use App\Service\EnrichmentSettings;
use App\Service\SpendLimiter;
use App\Tests\Support\PhpSource;
use PHPUnit\Framework\TestCase;

/**
 * **No method may both change whose key pays and then spend.**
 *
 * The exemption from the per-vault ceiling is granted to a section running on
 * the account's own key, because a bound memex invents has nothing behind it when
 * the bill is theirs. {@see SpendLimiter} asks for that exemption and the
 * clients resolve the key, and both read the same per-request answer — so they
 * agree, until something between them changes the answer. Then the check says
 * exempt, the client sends nothing, and the request runs uncapped on the
 * operator's account: the one state the limiter exists to prevent.
 *
 * Codex raised this twice (2026-09-08). The first version was worse — the
 * allowance was a cached copy nothing could invalidate — and is fixed. What is
 * left cannot be fixed by caching, because it is a check and an act being two
 * things: any request that mutates a credential BETWEEN them diverges, however
 * fresh both reads are.
 *
 * No route composes that sequence today. The reason this is a guard rather than
 * a comment is that the settings pane offering these keys is a route that writes
 * a credential pointer, and it must not also spend in the same request. A warning
 * in a document is the shape of thing this codebase has already watched fail
 * four times; a parser is not.
 *
 * The alternative fix — resolving the credentials once and threading the same
 * object through the limiter and the client — was weighed and not taken. It
 * would touch every embedding call site to close a window nothing can currently
 * reach, and it argues against the design the two sibling guards enforce: the
 * check belongs at the caller, where a static reading can prove it is there.
 *
 * Not an {@see ApiTestCase}: it reads source, touches no database and needs no
 * kernel.
 */
class SpendOrderingGuardTest extends TestCase
{
    /**
     * Changing whose key pays. Read off {@see EnrichmentSettings} rather than
     * listed, so a new writer joins this guard by existing.
     */
    private const CHANGES_THE_PAYER = ['save', 'saveSections', 'saveKey', 'deleteKey', 'forget'];

    /** Pointing a section at a key, one level below any route, and the class that holds each. */
    private const REPOINTS_A_SECTION = [
        'setCredential' => VaultSettings::class,
        'setEmbedCredential' => VaultSettings::class,
        'setTier' => Account::class,
    ];

    /** Asking whether this vault may spend. */
    private const CHECKS_THE_CEILING = ['assertAnalyze', 'assertEmbedding'];

    /** Spending it. */
    private const SPENDS = ['embedQuery', 'embedContent', 'embedContents', 'summarize', 'suggestTitle', 'suggestTags'];

    /**
     * The files that DEFINE these methods, which necessarily name them.
     * EnrichmentSettings' own writers call each other; the entity holds the
     * setters; the limiter and the resolver are the two halves being kept in
     * agreement rather than call sites of them.
     */
    private const DEFINES_THEM = ['EnrichmentSettings.php', 'VaultSettings.php', 'Account.php', 'SpendLimiter.php', 'SpendLimits.php', 'MlClient.php'];

    public function testNoMethodChangesThePayerAndThenSpends(): void
    {
        $offenders = [];

        foreach (PhpSource::filesUnder(__DIR__.'/../../src') as $file) {
            if (in_array(basename($file), self::DEFINES_THEM, true)) {
                continue;
            }

            foreach (PhpSource::methodsIn($file) as $method) {
                $offence = self::offence($method['calls']);
                if ($offence === null) {
                    continue;
                }
                $offenders[] = sprintf('%s:%d %s() %s', basename($file), $method['line'], $method['name'], $offence);
            }
        }

        self::assertSame([], $offenders, 'These change whose key pays and spend in the same request, so the ceiling and the key can disagree');
    }

    /**
     * The guard must be able to FAIL, and on the shape that would actually be
     * written: a settings route that saves a key and buys an embedding to show
     * the user it works.
     */
    public function testTheGuardRefusesARouteThatSavesAKeyAndThenSpends(): void
    {
        $source = <<<'PHP'
            <?php
            class Pretender
            {
                public function saveAndPreview(): void
                {
                    $this->settings->saveKey('openai', 'OpenAI', $key);
                    $this->spendLimiter->assertEmbedding();
                    $this->mlClient->embedQuery($text);
                }
            }
            PHP;

        $methods = PhpSource::parseMethods($source);

        self::assertNotSame([], $methods);
        self::assertNotNull(self::offence($methods[0]['calls']), 'The guard would have let a save-then-spend route through');
    }

    /** A method that only writes, or only spends, is not an offence. */
    public function testTheGuardDoesNotRefuseWritingAloneOrSpendingAlone(): void
    {
        $writes = PhpSource::parseMethods('<?php class A { public function f() { $this->settings->saveKey($a, $b, $c); } }');
        $spends = PhpSource::parseMethods('<?php class B { public function g() { $this->spendLimiter->assertEmbedding(); $this->mlClient->embedQuery($u); } }');

        self::assertNull(self::offence($writes[0]['calls']));
        self::assertNull(self::offence($spends[0]['calls']));
    }

    /**
     * The names are read off the classes, so a renamed writer cannot leave this
     * guard matching a string nothing answers to.
     */
    public function testTheNamesThisGuardWatchesStillExist(): void
    {
        foreach (self::CHANGES_THE_PAYER as $name) {
            self::assertTrue(method_exists(EnrichmentSettings::class, $name), 'EnrichmentSettings::'.$name.' is gone; revisit this guard');
        }
        foreach (self::CHECKS_THE_CEILING as $name) {
            self::assertTrue(method_exists(SpendLimiter::class, $name), 'SpendLimiter::'.$name.' is gone; revisit this guard');
        }
        foreach (self::REPOINTS_A_SECTION as $name => $class) {
            self::assertTrue(method_exists($class, $name), $class.'::'.$name.' is gone; revisit this guard');
        }
    }

    /** @param string[] $calls */
    private static function offence(array $calls): ?string
    {
        $changes = array_intersect([...self::CHANGES_THE_PAYER, ...array_keys(self::REPOINTS_A_SECTION)], $calls);
        if ($changes === []) {
            return null;
        }
        $spends = array_intersect([...self::CHECKS_THE_CEILING, ...self::SPENDS], $calls);
        if ($spends === []) {
            return null;
        }

        return sprintf('calls %s and then %s', implode('/', $changes), implode('/', $spends));
    }
}
