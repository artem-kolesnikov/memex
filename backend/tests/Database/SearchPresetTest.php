<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\SearchPreset;
use App\Entity\Tag;
use App\Tests\Support\Tenant;

class SearchPresetTest extends ApiTestCase
{
    private function tagOf(Tenant $tenant, string $name): Tag
    {
        $this->in($tenant);
        $tag = $this->em->getRepository(Tag::class)->findOneBy(['name' => $name]);
        if ($tag === null) {
            $tag = new Tag($name);
            $this->em->persist($tag);
            $this->em->flush();
        }

        return $tag;
    }

    private function countFor(Tenant $tenant): int
    {
        $this->in($tenant);
        $this->em->clear();

        return $this->em->getRepository(SearchPreset::class)->count([]);
    }

    public function testAPresetIsSavedListedEditedAndRemoved(): void
    {
        $tag = $this->tagOf($this->kb->a, 'infra');
        $this->loginAs($this->kb->a);

        $this->sessionRequest('POST', '/api/presets', [
            'name' => '  Infra   to review ',
            'q' => 'postgres',
            'tags' => [$tag->getId(), $tag->getId()],
            'status' => 'pending',
        ]);
        self::assertSame(201, $this->httpStatus());
        $preset = $this->jsonResponse()['preset'];
        self::assertSame('Infra to review', $preset['name']);
        self::assertSame('postgres', $preset['q']);
        self::assertSame([['id' => $tag->getId(), 'name' => 'infra']], $preset['tags'], 'Tags are deduped and resolved to names');
        self::assertSame('pending', $preset['status']);
        self::assertNull($preset['added_by']);
        self::assertSame('filter', $preset['icon'], 'The default glyph when none is chosen');
        self::assertSame('fa-solid fa-filter', $preset['icon_class']);

        $this->sessionRequest('GET', '/api/presets');
        self::assertSame(200, $this->httpStatus());
        self::assertSame([$preset['id']], array_column($this->jsonResponse()['presets'], 'id'));

        $this->sessionRequest('PATCH', '/api/presets/'.$preset['id'], ['name' => 'Flagged infra', 'icon' => 'flag', 'tags' => [$tag->getId()], 'status' => 'flagged']);
        self::assertSame(200, $this->httpStatus());
        $edited = $this->jsonResponse()['preset'];
        self::assertSame('Flagged infra', $edited['name']);
        self::assertSame('flag', $edited['icon']);
        self::assertSame('fa-solid fa-flag', $edited['icon_class']);
        self::assertSame('', $edited['q'], 'An edit replaces the criteria whole');
        self::assertSame('flagged', $edited['status']);

        $this->sessionRequest('DELETE', '/api/presets/'.$preset['id']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(0, $this->countFor($this->kb->a));
    }

    public function testAPresetWithNoCriterionIsRefused(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('POST', '/api/presets', ['name' => 'Everything']);

        self::assertSame(400, $this->httpStatus());
        self::assertSame(0, $this->countFor($this->kb->a));
    }

    public function testANamelessPresetIsRefused(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('POST', '/api/presets', ['name' => '   ', 'status' => 'pending']);

        self::assertSame(400, $this->httpStatus());
        self::assertSame(0, $this->countFor($this->kb->a));
    }

    public function testAnUnknownStatusIsRefused(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('POST', '/api/presets', ['name' => 'Odd', 'status' => 'deleted']);

        self::assertSame(400, $this->httpStatus());
    }

    public function testAnIconOutsideTheCatalogueIsRefused(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('POST', '/api/presets', ['name' => 'Odd', 'icon' => 'fa-solid fa-skull', 'status' => 'pending']);

        self::assertSame(400, $this->httpStatus());
        self::assertSame(0, $this->countFor($this->kb->a));
    }

    public function testTheCatalogueIsServedWithTheList(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/presets');

        $keys = array_column($this->jsonResponse()['icons'], 'key');
        self::assertContains('filter', $keys);
        self::assertContains('flag', $keys);
    }

    public function testTwoPresetsCannotShareAName(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('POST', '/api/presets', ['name' => 'Pending', 'status' => 'pending']);
        self::assertSame(201, $this->httpStatus());
        $this->sessionRequest('POST', '/api/presets', ['name' => 'Pending', 'status' => 'flagged']);

        self::assertSame(409, $this->httpStatus());
        self::assertSame(1, $this->countFor($this->kb->a));
    }

    public function testAnotherTenantsTagCannotBeSaved(): void
    {
        $foreign = $this->tagOf($this->kb->b, 'their-tag');
        $this->loginAs($this->kb->a);

        $this->sessionRequest('POST', '/api/presets', ['name' => 'Theirs', 'tags' => [$foreign->getId()]]);

        self::assertSame(400, $this->httpStatus());
        self::assertStringNotContainsString('their-tag', $this->body());
        self::assertSame(0, $this->countFor($this->kb->a));
    }

    public function testAnotherTenantsPresetIsInvisible(): void
    {
        $this->loginAs($this->kb->b);
        $this->sessionRequest('POST', '/api/presets', ['name' => 'B only', 'status' => 'pending']);
        self::assertSame(201, $this->httpStatus());
        $id = $this->jsonResponse()['preset']['id'];

        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/presets');
        self::assertSame([], $this->jsonResponse()['presets']);
        self::assertStringNotContainsString('B only', $this->body());

        $this->sessionRequest('PATCH', '/api/presets/'.$id, ['name' => 'Taken', 'status' => 'pending']);
        self::assertSame(404, $this->httpStatus());

        $this->sessionRequest('DELETE', '/api/presets/'.$id);
        self::assertSame(404, $this->httpStatus());
        self::assertSame(1, $this->countFor($this->kb->b));
    }

    public function testABearerTokenCannotTouchPresets(): void
    {
        $this->request('POST', '/api/presets', $this->kb->a->curatorBearer, ['name' => 'By token', 'status' => 'pending']);
        self::assertSame(403, $this->httpStatus());

        $this->request('GET', '/api/presets', $this->kb->a->curatorBearer);
        self::assertSame(403, $this->httpStatus());
        self::assertSame(0, $this->countFor($this->kb->a));
    }
}
