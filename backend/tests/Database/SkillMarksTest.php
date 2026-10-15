<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * Every list the web app shows that can hold notes and skills together says
 * which rows are skills, so the page can mark them apart.
 */
final class SkillMarksTest extends ApiTestCase
{
    public function testHeldProposalsSayWhetherTheirNoteIsASkill(): void
    {
        $note = $this->kb->a->note('Release checklist', 'Steps.', ['ops']);
        $skill = $this->kb->a->note('Release skill', 'Steps.', ['skill']);
        foreach ([$note, $skill] as $target) {
            $this->request('PUT', '/api/notes/'.$target->getId(), $this->kb->a->agentBearer, ['title' => $target->getTitle().' v2']);
            self::assertSame(202, $this->httpStatus(), $this->body());
        }
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/proposals');
        $skillByNote = array_column(array_column($this->jsonResponse()['proposals'], 'note'), 'skill', 'id');

        self::assertSame([$note->getId() => false, $skill->getId() => true], $skillByNote);
    }

    public function testTheActivityJournalSaysWhichNotesAreSkills(): void
    {
        $this->kb->a->note('Release checklist', 'Steps.', ['ops']);
        $this->kb->a->note('Release skill', 'Steps.', ['skill']);
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/curator-log');
        self::assertSame(200, $this->httpStatus(), $this->body());
        $skillByTitle = [];
        foreach ($this->jsonResponse()['entries'] as $entry) {
            foreach ([...$entry['affected'], ...($entry['note'] === null ? [] : [$entry['note']])] as $ref) {
                $skillByTitle[$ref['title']] = $ref['skill'];
            }
        }

        self::assertSame(['Release checklist' => false, 'Release skill' => true], $this->sorted($skillByTitle));
    }

    public function testLinkSuggestionsAndBacklinksSayWhichAreSkills(): void
    {
        $runbook = $this->kb->a->note('Runbook', 'Plain.');
        $this->kb->a->note('Release skill', 'Read [[Runbook]] first.', ['skill']);
        $this->kb->a->note('Deploy notes', 'See [[Runbook]].');
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/notes/titles');
        self::assertSame(
            ['Deploy notes' => false, 'Release skill' => true, 'Runbook' => false],
            $this->sorted(array_column($this->jsonResponse()['titles'], 'skill', 'title')),
        );

        $this->sessionRequest('GET', '/api/notes/'.$runbook->getId());
        self::assertSame(
            ['Deploy notes' => false, 'Release skill' => true],
            $this->sorted(array_column($this->jsonResponse()['backlinks'], 'skill', 'title')),
        );
    }

    public function testNamedButNotLinkedSaysWhichAreSkills(): void
    {
        $this->kb->a->note('Release skill', 'Steps.', ['skill']);
        $this->kb->a->note('Runbook', 'Plain.');

        $this->request('POST', '/api/analyze', $this->kb->a->agentBearer, [
            'body_md' => 'Follow the Release skill, then open the Runbook.',
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        self::assertSame(
            ['Release skill' => true, 'Runbook' => false],
            $this->sorted(array_column($this->jsonResponse()['hints']['links'], 'skill', 'title')),
        );
    }

    public function testACurationPassSaysWhichOfItsNotesAreSkills(): void
    {
        $note = $this->kb->a->note('Release checklist', 'Steps.', ['ops']);
        $skill = $this->kb->a->note('Release skill', 'Steps.', ['skill']);
        foreach ([$note, $skill] as $target) {
            $this->mcp('propose', ['note_id' => $target->getId(), 'patch' => [['find' => 'Steps.', 'replace' => 'Steps, checked.']]]);
        }
        $this->mcp('log', ['action' => 'run-summary', 'description' => 'A pass.']);
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/curation/digest');
        self::assertSame(200, $this->httpStatus(), $this->body());

        self::assertSame(
            ['Release checklist' => false, 'Release skill' => true],
            $this->sorted(array_column($this->jsonResponse()['runs'][0]['notes'], 'skill', 'title')),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function mcp(string $tool, array $arguments): void
    {
        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertNotTrue($this->jsonResponse()['result']['isError'] ?? false, $this->body());
    }

    /**
     * @param array<string, bool> $map
     *
     * @return array<string, bool>
     */
    private function sorted(array $map): array
    {
        ksort($map);

        return $map;
    }
}
