<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\EditProposal;
use App\Entity\Note;
use App\Entity\NoteRevision;
use App\Service\NoteLimbo;
use App\Service\ReviewVerdicts;

/**
 * Properties are named facts beside a note's text (operator, 2026-10-17): an
 * assistant writes them through `propose`, `search` filters on them in Basic
 * Memory's syntax, and an edit changes only the properties it names, under the
 * same review as the rest of the note.
 */
final class NotePropertiesTest extends ApiTestCase
{
    /** @return array<string, mixed> the decoded payload, or ['error' => text] */
    private function call(string $tool, array $arguments, ?string $bearer = null): array
    {
        $this->request('POST', '/mcp', $bearer ?? $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $result = $this->jsonResponse()['result'];
        $text = $result['content'][0]['text'];

        return ($result['isError'] ?? false) ? ['error' => $text] : json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> the titles a property filter keeps, sorted */
    private function found(array $filter): array
    {
        $result = $this->call('search', ['properties' => $filter, 'limit' => 25]);
        self::assertArrayNotHasKey('error', $result, json_encode($result, JSON_THROW_ON_ERROR));
        $titles = array_column($result['items'], 'title');
        sort($titles);

        return $titles;
    }

    private function approve(int $proposalId, ?array $ownProperties = null): void
    {
        $this->in($this->kb->a);
        $proposal = $this->em->find(EditProposal::class, $proposalId);
        self::assertNotNull($proposal);
        $snapshot = ['expected_revision' => $proposal->getRevision(), 'expected_version' => $proposal->getNote()->getVersion()]
            + ($proposal->getMergeIntoNote() === null ? [] : ['expected_merge_version' => $proposal->getMergeIntoNote()->getVersion()]);
        if ($ownProperties !== null) {
            $proposal->amend(null, null, null, null, $ownProperties);
        }
        self::getContainer()->get(ReviewVerdicts::class)->approveProposal($proposal, ['comment' => null, 'precedent' => false], $snapshot);
    }

    /** @return array<string, mixed> */
    private function propertiesOf(int $noteId): array
    {
        $this->in($this->kb->a);
        $this->em->clear();

        return $this->em->find(Note::class, $noteId)->getProperties();
    }

    private function ownerSaves(int $noteId, array $properties): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$noteId, ['properties' => $properties]);
        self::assertSame(200, $this->httpStatus(), $this->body());
    }

    public function testSearchKeepsTheNotesWhosePropertiesMatch(): void
    {
        foreach ([
            'Kitchen remodel' => ['status' => 'active', 'due' => '2026-11-01', 'priority' => 'high', 'budget' => 12000, 'topics' => ['kitchen', 'budget'], 'client' => ['name' => 'Acme'], 'done' => false],
            'Garden plan' => ['status' => 'done', 'due' => '2026-09-15', 'priority' => 'low', 'budget' => 300.5, 'topics' => ['garden'], 'done' => true],
            'Roof repair' => ['status' => 'active', 'due' => '2027-01-10', 'priority' => 'critical', 'topics' => ['roof', 'budget']],
        ] as $title => $properties) {
            $filed = $this->call('propose', ['title' => $title, 'body_md' => 'Notes on the '.$title.'.', 'summary' => 'Plans.', 'properties' => $properties]);
            self::assertArrayNotHasKey('error', $filed, json_encode($filed, JSON_THROW_ON_ERROR));
        }

        self::assertSame(['Kitchen remodel', 'Roof repair'], $this->found(['status' => 'active']));
        self::assertSame(['Kitchen remodel', 'Roof repair'], $this->found(['topics' => 'budget']), 'a list property matches a value it holds');
        self::assertSame(['Kitchen remodel'], $this->found(['topics' => ['budget', 'kitchen']]), 'a list in the filter wants every one');
        self::assertSame(['Kitchen remodel', 'Roof repair'], $this->found(['priority' => ['$in' => ['high', 'critical']]]));
        self::assertSame(['Garden plan', 'Kitchen remodel'], $this->found(['due' => ['$lt' => '2026-12-01']]), 'dates compare as the text they are written in');
        self::assertSame(['Garden plan'], $this->found(['budget' => ['$between' => [100, 1000]]]));
        self::assertSame(['Kitchen remodel'], $this->found(['budget' => ['$gte' => 1000]]));
        self::assertSame(['Kitchen remodel'], $this->found(['client.name' => 'Acme']), 'a dot reaches inside an object');
        self::assertSame(['Garden plan'], $this->found(['done' => true]));
        self::assertSame(['Kitchen remodel'], $this->found(['done' => false]));
        self::assertSame(['Roof repair'], $this->found(['status' => 'active', 'due' => ['$gt' => '2026-12-01']]), 'every name must match');
        self::assertSame([], $this->found(['budget' => '12000']), 'text never equals a number');

        $hit = $this->call('search', ['properties' => ['client.name' => 'Acme']])['items'][0];
        self::assertSame('2026-11-01', $hit['properties']['due'], 'a hit carries its properties');

        $wrong = $this->call('search', ['properties' => ['due' => ['lt' => '2026-12-01']]]);
        self::assertStringContainsString('unknown operator', $wrong['error']);
        $wrong = $this->call('search', ['properties' => ['budget' => ['$between' => [1, 'z']]]]);
        self::assertStringContainsString('two numbers or two texts', $wrong['error']);

        $vocabulary = array_column($this->call('list_properties', [])['properties'], null, 'name');
        self::assertSame(3, $vocabulary['status']['note_count']);
        self::assertSame([['value' => 'active', 'note_count' => 2], ['value' => 'done', 'note_count' => 1]], $vocabulary['status']['values']);
        self::assertSame(['value' => 'budget', 'note_count' => 2], $vocabulary['topics']['values'][0], 'a list counts each of its items');
        self::assertSame([], $vocabulary['client']['values'], 'an object offers no value a filter could match whole');
        self::assertEqualsCanonicalizing([['value' => true, 'note_count' => 1], ['value' => false, 'note_count' => 1]], $vocabulary['done']['values'], 'true and false come back as themselves');
    }

    public function testAnEditChangesOnlyThePropertiesItNames(): void
    {
        $id = (int) $this->kb->a->note('Launch checklist', 'Steps.')->getId();
        $this->ownerSaves($id, ['status' => 'draft', 'owner' => 'Ann', 'due' => '2026-11-01']);

        $held = $this->call('propose', ['note_id' => $id, 'change_title' => 'Hand over for review', 'properties' => ['status' => 'review', 'owner' => null, 'reviewer' => 'Bo']]);
        self::assertFalse($held['applied']);
        self::assertSame(['status' => 'draft', 'owner' => 'Ann', 'due' => '2026-11-01'], $this->propertiesOf($id), 'held until the owner decides');
        self::assertContains('properties', $held['proposal']['kinds']);

        $read = $this->call('get', ['id' => $id], $this->kb->a->curatorBearer);
        self::assertSame(['status' => 'review', 'owner' => null, 'reviewer' => 'Bo'], $read['waiting_changes'][0]['properties']);

        $this->ownerSaves($id, ['status' => 'draft', 'owner' => 'Ann', 'due' => '2026-12-01']);
        $this->approve((int) $held['proposal']['id']);

        self::assertSame(['status' => 'review', 'due' => '2026-12-01', 'reviewer' => 'Bo'], $this->propertiesOf($id), 'the owner\'s change to another property stands');
        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/notes/'.$id.'/revisions');
        self::assertSame(200, $this->httpStatus(), $this->body());
        $replaced = $this->jsonResponse()['revisions'][0];
        self::assertSame(['status' => 'draft', 'owner' => 'Ann', 'due' => '2026-12-01'], $replaced['properties'], 'History shows what the edit replaced');

        $this->sessionRequest('POST', '/api/notes/'.$id.'/revisions/'.$replaced['id'].'/restore');
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame(['status' => 'draft', 'owner' => 'Ann', 'due' => '2026-12-01'], $this->propertiesOf($id), 'and Restore puts it back');
    }

    public function testAPropertyOneAssistantHoldsIsRefusedToAnother(): void
    {
        $id = (int) $this->kb->a->note('Vendor list', 'Vendors.')->getId();
        $second = 'mxt_agent_second_a';
        $this->kb->a->connection('Orchestrator', $second);

        self::assertArrayNotHasKey('error', $this->call('propose', ['note_id' => $id, 'properties' => ['status' => 'review']]));
        $refused = $this->call('propose', ['note_id' => $id, 'properties' => ['status' => 'final']], $second);
        self::assertStringContainsString('the property “status”', $refused['error']);
        self::assertArrayNotHasKey('error', $this->call('propose', ['note_id' => $id, 'properties' => ['region' => 'EU']], $second), 'another property is free');
    }

    public function testADraftIsStaleOnceSomeoneChangesItsProperty(): void
    {
        $id = (int) $this->kb->a->note('Budget', 'Numbers.')->getId();
        $this->ownerSaves($id, ['status' => 'draft']);
        $held = $this->call('propose', ['note_id' => $id, 'properties' => ['status' => 'review']]);

        $this->ownerSaves($id, ['status' => 'blocked']);

        $this->in($this->kb->a);
        $this->em->clear();
        self::assertSame(['properties'], $this->em->find(EditProposal::class, (int) $held['proposal']['id'])->staleFields());
    }

    public function testACuratorsPropertyEditAppliesAtOnce(): void
    {
        $id = (int) $this->kb->a->note('Release notes', 'Text.')->getId();

        $applied = $this->call('propose', ['note_id' => $id, 'properties' => ['status' => 'done']], $this->kb->a->curatorBearer);

        self::assertTrue($applied['applied']);
        self::assertSame(['status' => 'done'], $this->propertiesOf($id));
        $line = $this->em->getConnection()->fetchOne("SELECT description FROM curator_log WHERE note_id = ? AND action = 'edit' ORDER BY id DESC LIMIT 1", [$id]);
        self::assertStringContainsString('properties', (string) $line);
    }

    public function testANameThatIsNotAPropertysIsRefused(): void
    {
        $own = $this->call('propose', ['title' => 'A', 'body_md' => 'B.', 'properties' => ['tags' => ['x']]]);
        self::assertStringContainsString('the note\'s own field', $own['error']);
        $dotted = $this->call('propose', ['title' => 'A', 'body_md' => 'B.', 'properties' => ['client.name' => 'Acme']]);
        self::assertStringContainsString('no dots', $dotted['error']);

        $id = (int) $this->kb->a->note('Plain', 'Text.')->getId();
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$id, ['properties' => ['summary' => 'x']]);
        self::assertSame(400, $this->httpStatus());
    }

    public function testTheOwnersAmendmentAtApprovalIsTheSetTheNoteKeeps(): void
    {
        $id = (int) $this->kb->a->note('Hiring plan', 'Roles.')->getId();
        $held = $this->call('propose', ['note_id' => $id, 'properties' => ['status' => 'review']]);

        $this->approve((int) $held['proposal']['id'], ['status' => 'approved', 'quarter' => 'Q1']);

        self::assertSame(['status' => 'approved', 'quarter' => 'Q1'], $this->propertiesOf($id));
        $operations = $this->em->getConnection()->fetchFirstColumn('SELECT operation FROM note_revisions WHERE note_id = ? ORDER BY id', [$id]);
        self::assertSame([NoteRevision::OP_PROPOSAL, NoteRevision::OP_AMENDMENT], $operations, 'the author\'s change, then the owner\'s words');
    }

    public function testAMergeKeepsTheKeepersPropertiesAndTakesTheRest(): void
    {
        $keeper = (int) $this->kb->a->note('Trip to Lisbon', 'Flights.')->getId();
        $absorbed = (int) $this->kb->a->note('Lisbon trip', 'Hotel.')->getId();
        $this->ownerSaves($keeper, ['status' => 'booked', 'month' => 'May']);
        $this->ownerSaves($absorbed, ['status' => 'idea', 'budget' => 900]);

        $merge = $this->call('propose_merge', ['note_id' => $absorbed, 'into_note_id' => $keeper, 'reason' => 'The same trip.']);
        self::assertArrayNotHasKey('error', $merge, json_encode($merge, JSON_THROW_ON_ERROR));
        $this->approve((int) $merge['proposal']['id']);

        self::assertSame(['status' => 'booked', 'month' => 'May', 'budget' => 900], $this->propertiesOf($keeper));
    }

    public function testADeletedNoteComesBackWithItsProperties(): void
    {
        $id = (int) $this->kb->a->note('Old plan', 'Gone.')->getId();
        $this->ownerSaves($id, ['status' => 'archived', 'year' => 2024]);
        $this->in($this->kb->a);
        $limbo = self::getContainer()->get(NoteLimbo::class);
        $limbo->retire($this->em->find(Note::class, $id), Note::ACTOR_HUMAN);

        $limbo->restore($id);

        self::assertSame(['status' => 'archived', 'year' => 2024], $this->propertiesOf($id));
    }
}
