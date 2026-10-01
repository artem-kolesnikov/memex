<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\MapSettings;

/**
 * What an account may store about how its map is drawn.
 *
 * These words select a renderer and a geometry, so the one that matters is the
 * word the SERVER agrees to keep: a select element that offers three options
 * proves nothing about the next client. Every test asserts the refusal before
 * the acceptance, and the vocabulary is pinned so it cannot drift away from the
 * union types in NoteMap3d.vue without a failure here.
 */
class MapSettingsTest extends ApiTestCase
{
    public function testAnAccountStartsWithNothingChosen(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/me');
        self::assertSame([], $this->jsonResponse()['map'], 'Absent means default, and the SPA owns the defaults');
    }

    public function testTheChoicesSurviveOnTheAccount(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PATCH', '/api/me/map', ['view' => '3d', 'labels' => 'id']);
        self::assertSame(200, $this->httpStatus());

        $this->sessionRequest('GET', '/api/me');
        self::assertSame(['view' => '3d', 'labels' => 'id'], $this->jsonResponse()['map']);
    }

    /** One control at a time, so a stale tab cannot undo a choice made in another. */
    public function testAPatchLeavesTheKeysItDoesNotName(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PATCH', '/api/me/map', ['view' => '3d', 'links' => 'arrow']);

        $this->sessionRequest('PATCH', '/api/me/map', ['labels' => 'none']);
        self::assertSame(
            ['view' => '3d', 'links' => 'arrow', 'labels' => 'none'],
            $this->jsonResponse()['map'],
        );
    }

    public function testNullPutsAControlBackOnItsDefault(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PATCH', '/api/me/map', ['view' => '3d', 'links' => 'arrow']);

        $this->sessionRequest('PATCH', '/api/me/map', ['view' => null]);
        self::assertSame(['links' => 'arrow'], $this->jsonResponse()['map']);

        $this->sessionRequest('PATCH', '/api/me/map', ['links' => null]);
        self::assertSame([], $this->jsonResponse()['map']);
    }

    /**
     * Read at the column, because the API cannot tell the two apart: `read()`
     * normalises NULL and `{}` to the same empty array. The row is the thing —
     * "never chose anything" and "chose every default back" have to be one
     * state, or every account that ever opened the map carries a blob saying
     * nothing.
     */
    public function testClearingEveryControlLeavesNoBlobBehind(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PATCH', '/api/me/map', ['view' => '3d']);

        $this->sessionRequest('PATCH', '/api/me/map', ['view' => null]);

        $this->in($this->kb->a);
        $stored = $this->em->getConnection()->fetchOne('SELECT map_settings FROM settings');
        self::assertNull($stored, 'An empty blob is stored as NULL, not as {}');
    }

    public function testAWordNoRendererKnowsIsRefused(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PATCH', '/api/me/map', ['view' => '4d']);
        self::assertSame(400, $this->httpStatus());

        $this->sessionRequest('PATCH', '/api/me/map', ['nodeShape' => 'card']);
        self::assertSame(400, $this->httpStatus(), 'Cards were tried and dropped; the word must not be storable');

        $this->sessionRequest('GET', '/api/me');
        self::assertSame([], $this->jsonResponse()['map'], 'A refusal stores nothing');
    }

    public function testAKeyNoRendererReadsIsRefused(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PATCH', '/api/me/map', ['background' => 'black']);
        self::assertSame(400, $this->httpStatus());

        $this->sessionRequest('PATCH', '/api/me/map', []);
        self::assertSame(400, $this->httpStatus(), 'An empty body changed nothing and said so');
    }

    public function testANonStringValueIsRefused(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('PATCH', '/api/me/map', ['view' => ['3d']]);
        self::assertSame(400, $this->httpStatus());

        $this->sessionRequest('PATCH', '/api/me/map', ['labels' => 3]);
        self::assertSame(400, $this->httpStatus());
    }

    /** An assistant has no business deciding what the owner's map looks like. */
    public function testABearerTokenCannotChangeTheMap(): void
    {
        foreach (['agent' => $this->kb->a->agentBearer, 'curator' => $this->kb->a->curatorBearer] as $role => $bearer) {
            $this->client->request(
                'PATCH',
                '/api/me/map',
                server: [
                    'HTTP_AUTHORIZATION' => 'Bearer '.$bearer,
                    'CONTENT_TYPE' => 'application/json',
                ],
                content: json_encode(['view' => '3d'], JSON_THROW_ON_ERROR),
            );
            self::assertSame(403, $this->httpStatus(), sprintf('A %s token is still not a browser', $role));
        }
    }

    public function testOneAccountsMapIsNotAnothers(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PATCH', '/api/me/map', ['view' => '3d']);

        $this->loginAs($this->kb->b);
        $this->sessionRequest('GET', '/api/me');
        self::assertSame([], $this->jsonResponse()['map']);
    }

    /**
     * The vocabulary, pinned against the SPA — by READING it.
     *
     * This test used to compare the server's constant with a copy of itself
     * typed out here, which passes whatever either side says. It parses the
     * frontend's own declaration now, so a word added on one side and not the
     * other fails: added here alone it stores a setting no renderer reads,
     * added there alone it is refused by the server and the control silently
     * does nothing. The first word of each list is that control's default,
     * which is where MAP_DEFAULTS gets its values.
     */
    public function testTheVocabularyMatchesTheRenderers(): void
    {
        $client = file_get_contents(__DIR__.'/../../../frontend/src/api/client.ts');
        self::assertIsString($client, 'The SPA client is where the other half of this vocabulary lives');

        $found = preg_match('/export const MAP_VOCABULARY = \{(.+?)\} as const/s', $client, $block);
        self::assertSame(1, $found, 'MAP_VOCABULARY is gone or renamed; this test cannot pin what it cannot find');

        preg_match_all("/(\w+): \[([^\]]+)\]/", $block[1], $rows, PREG_SET_ORDER);
        $spa = [];
        foreach ($rows as $row) {
            preg_match_all("/'([^']+)'/", $row[2], $words);
            $spa[$row[1]] = $words[1];
        }

        self::assertSame(MapSettings::vocabulary(), $spa);
    }

    /** A column written by another version must not put an unknown word on a canvas. */
    public function testAnUnreadableStoredValueIsDroppedRatherThanRepaired(): void
    {
        self::assertSame(['view' => '3d'], MapSettings::read([
            'view' => '3d',
            'links' => 'spiral',
            'nodeShape' => 42,
            'whatever' => 'dot',
        ]));
        self::assertSame([], MapSettings::read(null));
    }
}
