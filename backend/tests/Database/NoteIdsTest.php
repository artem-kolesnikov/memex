<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * `/api/notes/ids`: what the notes page's map lights.
 *
 * The map is the list drawn differently, so the one property that matters is
 * that it answers the same search with the same notes — all of them, where the
 * list shows a page — and nothing from another vault.
 */
final class NoteIdsTest extends ApiTestCase
{
    /** @return list<int> */
    private function ids(string $query, ?string $bearer = null): array
    {
        $this->request('GET', '/api/notes/ids'.$query, $bearer ?? $this->kb->a->agentBearer);
        self::assertSame(200, $this->httpStatus(), $this->body());

        return $this->jsonResponse()['ids'];
    }

    /** @return list<int> */
    private function listed(string $query): array
    {
        $this->request('GET', '/api/notes'.$query.($query === '' ? '?' : '&').'per_page=100', $this->kb->a->agentBearer);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $ids = array_column($this->jsonResponse()['items'], 'id');
        sort($ids);

        return $ids;
    }

    private function tagId(string $name): int
    {
        $this->in($this->kb->a);

        return (int) $this->em->getConnection()->fetchOne('SELECT id FROM tags WHERE name = :name', ['name' => $name]);
    }

    public function testAKeywordSearchLightsWhatTheListLists(): void
    {
        $runbook = $this->kb->a->note('Deploy runbook', 'How kubernetes is deployed.');
        $checklist = $this->kb->a->note('Release checklist', 'Check kubernetes first.');
        $this->kb->a->note('Grocery list', 'Milk and bread.');

        $ids = $this->ids('?q=kubernetes');

        self::assertSame([$runbook->getId(), $checklist->getId()], $ids);
        self::assertSame($this->listed('?q=kubernetes'), $ids);
    }

    public function testTheFacetsNarrowTheSameWayTheyNarrowTheList(): void
    {
        $tagged = $this->kb->a->note('Tagged and described', 'Body.', ['infra'], summary: 'Described.');
        $bare = $this->kb->a->note('Tagged, never described', 'Body.', ['infra']);
        $this->kb->a->note('Untagged', 'Body.', [], summary: 'Described.');

        $infra = '?tags='.$this->tagId('infra');
        self::assertSame([$tagged->getId(), $bare->getId()], $this->ids($infra));
        self::assertSame($this->listed($infra), $this->ids($infra));

        $undescribed = $infra.'&undescribed=1';
        self::assertSame([$bare->getId()], $this->ids($undescribed));
        self::assertSame($this->listed($undescribed), $this->ids($undescribed));
    }

    public function testEveryMatchIsReturnedWhateverPageTheListIsOn(): void
    {
        $numbers = [];
        foreach (['One', 'Two', 'Three'] as $title) {
            $numbers[] = $this->kb->a->note($title)->getId();
        }

        self::assertSame($numbers, $this->ids('?page=2&per_page=1'));
    }

    public function testAnotherTeamsNotesAreNeverLit(): void
    {
        // Numbers restart at 1 per vault, so A is given more notes than B
        // has: any of its numbers past B's own can only have come from A. The
        // stub embeds every note alike, so B's own note may come back on the
        // semantic leg; what must be absent is everything else.
        $this->kb->a->note('Alpha private note', 'A distinctive phrase only team A wrote.');
        $this->kb->a->note('Alpha second note', 'Another distinctive phrase.');
        $bravo = $this->kb->b->note('Bravo private note', 'Team B body.');

        self::assertSame([$bravo->getId()], $this->ids('', $this->kb->b->agentBearer));
        self::assertSame([], array_diff($this->ids('?q=distinctive', $this->kb->b->agentBearer), [$bravo->getId()]));
    }

    public function testASearchTheListRefusesIsRefusedHereToo(): void
    {
        $this->request('GET', '/api/notes/ids?status=deleted', $this->kb->a->agentBearer);
        self::assertSame(400, $this->httpStatus());

        // Connection ids restart per vault too: B's third is one A does not hold.
        $foreign = $this->kb->b->connection('agent-b-2', 'mxt_agent_b_2');
        $this->request('GET', '/api/notes/ids?added_by='.$foreign, $this->kb->a->agentBearer);
        self::assertSame(400, $this->httpStatus());
    }
}
