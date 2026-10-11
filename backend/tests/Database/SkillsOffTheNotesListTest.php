<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * A skill is listed on the Skills page and nowhere on Notes: not in the list,
 * not in its search, not on its map. It stays a note everywhere else — its own
 * page, its links, what an assistant's search finds.
 */
final class SkillsOffTheNotesListTest extends ApiTestCase
{
    public function testTheNotesListAndItsSearchLeaveSkillsOut(): void
    {
        $note = $this->kb->a->note('Release checklist', 'Steps for a release.', ['ops']);
        $skill = $this->kb->a->note('Release skill', 'Steps for a release.', ['skill', 'ops']);
        $this->loginAs($this->kb->a);

        foreach (['/api/notes', '/api/notes?q=release', '/api/notes?tags='.$this->tagId('ops')] as $url) {
            $this->sessionRequest('GET', $url);
            self::assertSame(200, $this->httpStatus(), $this->body());
            $ids = array_column($this->jsonResponse()['items'], 'id');
            self::assertContains($note->getId(), $ids, $url);
            self::assertNotContains($skill->getId(), $ids, $url.' listed a skill among the notes');
        }

        $this->sessionRequest('GET', '/api/notes/ids?q=release');
        self::assertSame([(int) $note->getId()], $this->jsonResponse()['ids'], 'the map lit a skill the list leaves out');
    }

    public function testTheMapLeavesSkillsOut(): void
    {
        $note = $this->kb->a->note('Runbook', 'Uses [[Deploy skill]].');
        $this->kb->a->note('Deploy skill', 'Steps.', ['skill']);
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/graph');
        self::assertSame(200, $this->httpStatus(), $this->body());
        $map = $this->jsonResponse();

        self::assertSame([(int) $note->getId()], array_map(static fn (array $n) => (int) $n['id'], $map['nodes']));
        self::assertSame(1, $map['total_notes']);
        self::assertSame([], $map['edges']);
    }

    public function testATagCountsTheNotesTheListShows(): void
    {
        $this->kb->a->note('Release checklist', 'Steps.', ['ops']);
        $this->kb->a->note('Release skill', 'Steps.', ['skill', 'ops']);
        $this->kb->a->note('Tone skill', 'Steps.', ['skill', 'writing']);
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/tags');
        $counts = array_column(array_map(
            static fn (array $t) => [$t['name'], [$t['note_count'], $t['listed_count']]],
            $this->jsonResponse()['tags'],
        ), 1, 0);

        self::assertSame([2, 1], $counts['ops']);
        self::assertSame([1, 0], $counts['writing'], 'a tag only skills carry offered the notes list a filter that finds nothing');
        self::assertSame([2, 0], $counts['skill']);
    }

    public function testASkillIsStillANoteToItsPageAndToAnAssistantsSearch(): void
    {
        $skill = $this->kb->a->note('Release skill', 'Steps for a release.', ['skill'], summary: 'How a release goes.');
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/notes/'.$skill->getId());
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->request('POST', '/mcp', $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'search', 'arguments' => ['query' => 'release']],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertStringContainsString('Release skill', $this->jsonResponse()['result']['content'][0]['text']);
    }

    private function tagId(string $name): int
    {
        $this->sessionRequest('GET', '/api/tags');
        foreach ($this->jsonResponse()['tags'] as $tag) {
            if ($tag['name'] === $name) {
                return (int) $tag['id'];
            }
        }
        self::fail("no tag $name");
    }
}
