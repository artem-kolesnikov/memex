<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\Appearance;

/**
 * What an account may store about how memex looks.
 *
 * The colour reaches a style property on the document element, so the value
 * that matters is the one the SERVER agrees to keep: a browser that validates
 * its own input proves nothing about the next client. Every test here asserts
 * the refusal before the acceptance, and the colour vocabulary is pinned so it
 * cannot drift away from theme-boot.js without a failure.
 */
class AppearanceTest extends ApiTestCase
{
    public function testAnAccountStartsWithNoAppearanceChosen(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/me');
        self::assertSame([], $this->jsonResponse()['appearance']);
    }

    public function testTheColoursSurviveOnTheAccount(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PATCH', '/api/me/appearance', [
            'accent' => ['dark' => '#86EFAC'],
            'link' => ['dark' => '#8FA4E8'],
        ]);
        self::assertSame(200, $this->httpStatus());

        // Lowercased on the way in: one spelling in the column keeps the
        // picker's "is this the current one" honest.
        $this->sessionRequest('GET', '/api/me');
        self::assertSame(
            ['accent' => ['dark' => '#86efac'], 'link' => ['dark' => '#8fa4e8']],
            $this->jsonResponse()['appearance']
        );
    }

    /** The picker changes one theme at a time; the other must not be collateral. */
    public function testPatchingOneThemeLeavesTheOtherAlone(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PATCH', '/api/me/appearance', ['accent' => ['light' => '#3a52a4', 'dark' => '#8fa4e8']]);
        $this->sessionRequest('PATCH', '/api/me/appearance', ['accent' => ['dark' => '#86efac']]);

        $this->sessionRequest('GET', '/api/me');
        self::assertSame(
            ['light' => '#3a52a4', 'dark' => '#86efac'],
            $this->jsonResponse()['appearance']['accent'],
            'A dark-mode change repainted light mode'
        );
    }

    /** Reset travels as null, and must leave no residue behind it. */
    public function testNullClearsAValueAndAnEmptyBlobBecomesNull(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PATCH', '/api/me/appearance', ['accent' => ['dark' => '#86efac']]);
        $this->sessionRequest('PATCH', '/api/me/appearance', ['accent' => ['dark' => null]]);
        self::assertSame(200, $this->httpStatus());

        $this->sessionRequest('GET', '/api/me');
        self::assertSame([], $this->jsonResponse()['appearance'], 'Clearing the last value left an empty shell');

        // Read back through the API this looks the same either way, so the
        // column itself is the assertion: "never chose" and "chose, then reset"
        // have to be the same row.
        self::assertNull($this->kb->a->settings()->getAppearance(), 'Reset left an empty container in the column');
    }

    /**
     * @dataProvider notAColour
     */
    public function testAnAccentThatIsNotSixHexDigitsIsRefused(mixed $value): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PATCH', '/api/me/appearance', ['accent' => ['dark' => $value]]);
        self::assertSame(400, $this->httpStatus(), 'Accepted '.var_export($value, true).' as a colour');

        $this->sessionRequest('GET', '/api/me');
        self::assertSame([], $this->jsonResponse()['appearance'], 'A refused write still landed');
    }

    public static function notAColour(): iterable
    {
        yield 'shorthand' => ['#fff'];
        yield 'named' => ['red'];
        yield 'no hash' => ['86efac'];
        yield 'eight digits' => ['#86efacff'];
        yield 'not hex' => ['#zzzzzz'];
        yield 'a function' => ['rgb(1,2,3)'];
        yield 'markup' => ['#fff;} body{display:none'];
        yield 'a number' => [255];
        yield 'a list' => [['#ffffff']];
        // PCRE's `$` matches before a trailing newline, so this spelling was
        // stored verbatim until the pattern was anchored with \z.
        yield 'trailing newline' => ["#123456\n"];
        yield 'trailing space' => ['#123456 '];
        yield 'leading newline' => ["\n#123456"];
    }

    /**
     * There is one palette per theme now, and the interface greys are not a
     * setting. Both keys were storable until 2026-08-29, so the client sending
     * them is a stale tab rather than an attacker — it is still told, because a
     * write that is silently dropped looks exactly like a write that worked.
     */
    public function testARetiredKeyIsRefusedRatherThanStored(): void
    {
        $this->loginAs($this->kb->a);

        foreach (['surface' => 'code', 'muted' => '#67727f'] as $which => $value) {
            $this->sessionRequest('PATCH', '/api/me/appearance', [$which => ['dark' => $value]]);
            self::assertSame(400, $this->httpStatus(), sprintf('"%s" is still storable', $which));
        }

        $this->sessionRequest('GET', '/api/me');
        self::assertSame([], $this->jsonResponse()['appearance']);
    }

    public function testAnUnknownKeyIsRefusedRatherThanIgnored(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PATCH', '/api/me/appearance', ['font-size' => ['dark' => 'huge']]);
        self::assertSame(400, $this->httpStatus());

        // The value here is a legitimate colour, so only the check on the KEY
        // can refuse it. Without that check this is stored under a name nothing
        // reads, and the 400 above would still pass on the value alone.
        $this->sessionRequest('PATCH', '/api/me/appearance', ['fontSize' => ['dark' => '#86efac']]);
        self::assertSame(400, $this->httpStatus(), '"fontSize" is not something you can set');

        $this->sessionRequest('GET', '/api/me');
        self::assertSame([], $this->jsonResponse()['appearance'], 'An unknown key was stored anyway');

        $this->sessionRequest('PATCH', '/api/me/appearance', ['accent' => ['sepia' => '#86efac']]);
        self::assertSame(400, $this->httpStatus(), '"sepia" is not a theme');

        $this->sessionRequest('PATCH', '/api/me/appearance', []);
        self::assertSame(400, $this->httpStatus(), 'An empty body changed nothing and said so');
    }

    /** An assistant has no business repainting somebody's screen. */
    public function testABearerTokenCannotChangeAppearance(): void
    {
        $this->client->request(
            'PATCH',
            '/api/me/appearance',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->kb->a->agentBearer,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['accent' => ['dark' => '#86efac']], JSON_THROW_ON_ERROR),
        );
        self::assertSame(403, $this->httpStatus());

        $this->client->request(
            'PATCH',
            '/api/me/appearance',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->kb->a->curatorBearer,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['accent' => ['dark' => '#86efac']], JSON_THROW_ON_ERROR),
        );
        self::assertSame(403, $this->httpStatus(), 'A curator token is still not a browser');
    }

    public function testOneAccountsAppearanceIsNotAnothers(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PATCH', '/api/me/appearance', ['accent' => ['dark' => '#86efac']]);

        $this->loginAs($this->kb->b);
        $this->sessionRequest('GET', '/api/me');
        self::assertSame([], $this->jsonResponse()['appearance'], 'Appearance leaked across vaults');
    }

    /**
     * A column written by another version must never put a value the browser
     * will refuse back onto a page.
     */
    public function testGarbageInTheColumnIsDroppedOnRead(): void
    {
        self::assertSame([], Appearance::read(['accent' => ['dark' => 'javascript:alert(1)']]));
        // A background chosen before the themes were cut to two. The column
        // still holds it; nothing may hand it back.
        self::assertSame([], Appearance::read(['surface' => ['dark' => 'code']]));
        self::assertSame([], Appearance::read(['muted' => ['dark' => '#a9b0b7']]));
        self::assertSame([], Appearance::read(['nonsense' => ['dark' => '#ffffff']]));
        self::assertSame([], Appearance::read(null));
        self::assertSame(
            ['accent' => ['light' => '#ffffff']],
            Appearance::read(['accent' => ['light' => '#FFFFFF', 'dark' => 'nope']]),
            'One bad value took a good one with it'
        );
    }

    /**
     * The colours an account may store live in two places too — COLOURS here
     * and THEMED in public/theme-boot.js. A key the browser writes and the
     * server refuses is a preference that works until the page is reloaded on
     * another device, which is the failure this catches.
     */
    public function testTheColourVocabularyIsTheOneTheBrowserStores(): void
    {
        $js = file_get_contents(__DIR__.'/../../../frontend/public/theme-boot.js');
        self::assertIsString($js);

        // Comments are stripped first: `bodyText: {` inside a block comment
        // satisfies the pattern while THEMED.bodyText is undefined, and
        // applyThemed then throws on a render-blocking script.
        $code = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $js);
        self::assertIsString($code);

        $this->loginAs($this->kb->a);

        foreach (['accent', 'link', 'bodyText'] as $which) {
            self::assertMatchesRegularExpression(
                sprintf('/^\s*%s:\s*\{/m', preg_quote($which, '/')),
                $code,
                sprintf('theme-boot.js no longer stores a "%s" colour', $which)
            );

            foreach (['light', 'dark'] as $theme) {
                $this->sessionRequest('PATCH', '/api/me/appearance', [$which => [$theme => '#123456']]);
                self::assertSame(
                    200,
                    $this->httpStatus(),
                    sprintf('The server refuses a %s "%s", which the browser stores', $theme, $which)
                );
            }
        }

        // Cleared so the GET below loads the row rather than reading the same
        // managed object back out of the identity map: without this the
        // assertion passes against a PATCH that never flushed.
        $this->em->clear();

        $this->sessionRequest('GET', '/api/me');
        $stored = $this->jsonResponse()['appearance'];
        foreach (['accent', 'link', 'bodyText'] as $which) {
            self::assertSame(
                ['light' => '#123456', 'dark' => '#123456'],
                $stored[$which] ?? null,
                sprintf('A stored "%s" did not survive the round trip', $which)
            );
        }
    }

    /**
     * Two themes, two palettes, and no background choice on top of them.
     *
     * The palettes live in the browser because it must paint before any request
     * completes, so what is pinned here is the SHAPE: a `PALETTES` object keyed
     * by the two themes, and nothing left storing a background. A third preset
     * reintroduced in theme-boot.js would be one nothing can select.
     */
    public function testTheBrowserShipsOnePaletteForEachTheme(): void
    {
        $js = file_get_contents(__DIR__.'/../../../frontend/public/theme-boot.js');
        self::assertIsString($js);

        $code = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $js);
        self::assertIsString($code);

        self::assertMatchesRegularExpression(
            '/const PALETTES = \{\s*light: \{[^\n]+\},\s*dark: \{[^\n]+\},\s*\}/',
            $code,
            'theme-boot.js no longer ships one palette per theme'
        );

        // The browser's own list of what it stores per theme, which is the
        // list this class validates. A fourth entry here is a preference the
        // server would refuse the moment it left the device it was set on.
        self::assertSame(1, preg_match('/const THEMED = \{(.+?)\n  \}/s', $code, $themed), 'THEMED is no longer an object literal');
        preg_match_all('/^\s{4}([a-zA-Z]+): \{/m', $themed[1], $keys);
        self::assertSame(['accent', 'link', 'bodyText'], $keys[1]);
    }
}
