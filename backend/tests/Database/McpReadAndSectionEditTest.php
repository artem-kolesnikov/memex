<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\EditProposal;
use App\Entity\Note;
use App\Service\ReviewVerdicts;

/**
 * Reading and editing a note by its shape over MCP (operator, 2026-10-17):
 * `get` by title, one section with the note's outline, neighbours a link or
 * two away; `propose` adding or replacing by heading, held and approved like
 * any anchored edit.
 */
final class McpReadAndSectionEditTest extends ApiTestCase
{
    private const NOTE = "Opening paragraph.\n\n## Plan\n\nDig the beds.\n\n## Costs\n\nAbout 40 euros.\n\n## Sources\n\n- The shop's leaflet.\n";

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

    private function noteBody(int $id): string
    {
        $this->in($this->kb->a);

        return (string) $this->em->getConnection()->fetchOne('SELECT body_md FROM notes WHERE id = ?', [$id]);
    }

    private function approve(int $proposalId): void
    {
        $this->in($this->kb->a);
        $proposal = $this->em->find(EditProposal::class, $proposalId);
        self::getContainer()->get(ReviewVerdicts::class)->approveProposal($proposal, ['comment' => null, 'precedent' => false], [
            'expected_revision' => $proposal->getRevision(),
            'expected_version' => $proposal->getNote()->getVersion(),
        ]);
    }

    public function testGetFindsANoteByItsTitleAsALinkWould(): void
    {
        $note = $this->kb->a->note('Garden plan', self::NOTE);

        $got = $this->call('get', ['title' => 'garden PLAN']);

        self::assertSame($note->getId(), $got['id']);
        self::assertArrayNotHasKey('same_title', $got);
    }

    public function testGetByATitleTheNoteGaveUpFindsItAndSaysItWasRenamed(): void
    {
        $note = $this->kb->a->note('Garden plan', self::NOTE);
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$note->getId(), ['title' => 'Garden plan — 2027']);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $got = $this->call('get', ['title' => 'Garden plan']);

        self::assertSame($note->getId(), $got['id']);
        self::assertSame('Garden plan — 2027', $got['title']);
        self::assertSame(['Garden plan'], $got['former_titles']);
    }

    public function testGetByASharedTitleNamesTheOtherNotesThatCarryIt(): void
    {
        $first = $this->kb->a->note('Minutes', 'First.');
        $second = $this->kb->a->note('Minutes', 'Second.');

        $got = $this->call('get', ['title' => 'Minutes']);

        self::assertSame($first->getId(), $got['id'], 'the lowest id, as a link resolves');
        self::assertSame([['id' => $second->getId(), 'title' => 'Minutes']], $got['same_title']);
    }

    public function testGetRefusesATitleNoNoteAnswersTo(): void
    {
        self::assertStringContainsString('No note answers to the title “Nothing here”', $this->call('get', ['title' => 'Nothing here'])['error'] ?? '');
        self::assertStringContainsString('`id`, or its `title`', $this->call('get', [])['error'] ?? '');
        self::assertStringContainsString('not both', $this->call('get', ['id' => 1, 'title' => 'x'])['error'] ?? '');
    }

    public function testGetReturnsOneSectionAndTheOutlineToChooseFrom(): void
    {
        $note = $this->kb->a->note('Garden plan', self::NOTE);

        $got = $this->call('get', ['id' => $note->getId(), 'section' => 'costs']);

        self::assertSame("## Costs\n\nAbout 40 euros.\n\n", $got['body_md']);
        self::assertSame('Costs', $got['section']);
        self::assertSame(mb_strlen(self::NOTE), $got['body_chars'], 'body_chars stays the note\'s true length');
        self::assertSame(
            [['level' => 2, 'heading' => 'Plan'], ['level' => 2, 'heading' => 'Costs'], ['level' => 2, 'heading' => 'Sources']],
            $got['outline'],
        );
    }

    public function testAnUnknownSectionIsRefusedWithTheHeadingsThereAre(): void
    {
        $note = $this->kb->a->note('Garden plan', self::NOTE);

        $error = $this->call('get', ['id' => $note->getId(), 'section' => 'Budget'])['error'] ?? '';

        self::assertStringContainsString('No heading “Budget”', $error);
        self::assertStringContainsString('“Plan”, “Costs”, “Sources”', $error);
    }

    public function testAShortNoteCarriesNoOutlineUnlessASectionIsAskedFor(): void
    {
        $note = $this->kb->a->note('Garden plan', self::NOTE);

        self::assertArrayNotHasKey('outline', $this->call('get', ['id' => $note->getId()]));
        $long = $this->kb->a->note('Long record', "## One\n\n".str_repeat('Words. ', 2000)."\n\n## Two\n\nEnd.\n");
        self::assertSame(['One', 'Two'], array_column($this->call('get', ['id' => $long->getId()])['outline'], 'heading'));
    }

    public function testDepthListsTheNotesALinkOrTwoAwayWithTheirSummaries(): void
    {
        $centre = $this->kb->a->note('House', 'See [[Kitchen]].');
        $kitchen = $this->kb->a->note('Kitchen', 'See [[Oven]].', [], 'The kitchen refit.');
        $oven = $this->kb->a->note('Oven', 'A gas oven.', [], 'The oven we chose.');
        $index = $this->kb->a->note('Index', 'All about the [[House]].');

        $one = $this->call('get', ['id' => $centre->getId(), 'depth' => 1]);
        self::assertSame([$index->getId(), $kitchen->getId()], array_column($one['neighbours'], 'id'));
        self::assertSame(2, $one['neighbours_total']);

        $two = $this->call('get', ['id' => $centre->getId(), 'depth' => 2]);
        self::assertSame([$index->getId(), $kitchen->getId(), $oven->getId()], array_column($two['neighbours'], 'id'));
        self::assertSame(['summary' => 'The oven we chose.', 'links_away' => 2], array_intersect_key($two['neighbours'][2], ['summary' => 0, 'links_away' => 0]));

        self::assertStringContainsString('depth must be 1 or 2', $this->call('get', ['id' => $centre->getId(), 'depth' => 3])['error'] ?? '');
        self::assertArrayNotHasKey('neighbours', $this->call('get', ['id' => $centre->getId()]));
    }

    public function testAnAgentsNewSectionAboveSourcesIsHeldAndLandsOnApproval(): void
    {
        $note = $this->kb->a->note('Garden plan', self::NOTE);

        $filed = $this->call('propose', [
            'note_id' => $note->getId(),
            'patch' => [['section' => 'Sources', 'before' => "## Risks\n\nFrost in May."]],
            'change_title' => 'Add the frost risk before the sources',
        ]);
        self::assertArrayNotHasKey('error', $filed, json_encode($filed, JSON_THROW_ON_ERROR));
        self::assertSame(self::NOTE, $this->noteBody((int) $note->getId()), 'held, like any agent edit');

        // Someone else's change to another part of the note meanwhile: the
        // held edit is laid onto the note as it then stands.
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$note->getId(), ['body_md' => str_replace('Dig the beds.', 'Dig the beds in March.', self::NOTE)]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->approve((int) $filed['proposal']['id']);

        self::assertSame(
            "Opening paragraph.\n\n## Plan\n\nDig the beds in March.\n\n## Costs\n\nAbout 40 euros.\n\n## Risks\n\nFrost in May.\n\n## Sources\n\n- The shop's leaflet.\n",
            $this->noteBody((int) $note->getId()),
        );
    }

    public function testAnAppendToAHeldRewriteKeepsTheRewrite(): void
    {
        $note = $this->kb->a->note('Garden plan', self::NOTE);
        $rewrite = "A new opening.\n\n## Plan\n\nRaised beds this year.\n\n## Costs\n\nAbout 90 euros.\n\n## Sources\n\n- The builder's quote.\n";

        $first = $this->call('propose', ['note_id' => $note->getId(), 'body_md' => $rewrite, 'change_title' => 'Rewrite the plan for raised beds']);
        self::assertArrayNotHasKey('error', $first, json_encode($first, JSON_THROW_ON_ERROR));
        $second = $this->call('propose', ['note_id' => $note->getId(), 'patch' => [['section' => 'Sources', 'append' => "- The neighbour's advice."]]]);
        self::assertArrayNotHasKey('error', $second, json_encode($second, JSON_THROW_ON_ERROR));
        self::assertSame($first['proposal']['id'], $second['proposal']['id'], 'one draft per note per connection');

        $this->approve((int) $second['proposal']['id']);

        self::assertSame($rewrite."\n- The neighbour's advice.\n", $this->noteBody((int) $note->getId()));
    }

    public function testASectionTheHeldRewriteDroppedIsRefusedRatherThanLaidOnTheNote(): void
    {
        $note = $this->kb->a->note('Garden plan', self::NOTE);
        $rewrite = "A new opening.\n\n## Plan\n\nRaised beds this year.\n\n## Sources\n\n- The builder's quote.\n";
        $first = $this->call('propose', ['note_id' => $note->getId(), 'body_md' => $rewrite]);
        self::assertArrayNotHasKey('error', $first, json_encode($first, JSON_THROW_ON_ERROR));

        $error = $this->call('propose', ['note_id' => $note->getId(), 'patch' => [['section' => 'Costs', 'append' => 'Plus delivery.']]])['error'] ?? '';

        self::assertStringContainsString('Your held edit of this note is a whole new body', $error);
        self::assertStringContainsString('No heading “Costs”', $error);
    }

    public function testACuratorsAppendToASectionAppliesAtOnce(): void
    {
        $note = $this->kb->a->note('Garden plan', self::NOTE);

        $filed = $this->call('propose', [
            'note_id' => $note->getId(),
            'patch' => [['section' => 'Sources', 'append' => "- The neighbour's advice."]],
            'change_title' => 'Add the neighbour as a source',
        ], $this->kb->a->curatorBearer);

        self::assertArrayNotHasKey('error', $filed, json_encode($filed, JSON_THROW_ON_ERROR));
        self::assertSame(self::NOTE."\n- The neighbour's advice.\n", $this->noteBody((int) $note->getId()));
    }

    public function testAnAppendSentInBase64IsDecodedBeforeItIsPlaced(): void
    {
        $note = $this->kb->a->note('Runbook', "Restart the service.\n");

        $this->call('propose', [
            'note_id' => $note->getId(),
            'patch' => [['append' => base64_encode('Then run `systemctl status memex`.')]],
            'body_encoding' => 'base64',
        ], $this->kb->a->curatorBearer);

        self::assertSame("Restart the service.\n\nThen run `systemctl status memex`.\n", $this->noteBody((int) $note->getId()));
    }

    public function testAnAmbiguousHeadingIsRefusedAndNothingIsFiled(): void
    {
        $note = $this->kb->a->note('Two parts', "## A\n\n### Notes\n\na\n\n## B\n\n### Notes\n\nb\n");

        $error = $this->call('propose', [
            'note_id' => $note->getId(),
            'patch' => [['section' => 'Notes', 'append' => 'c']],
        ])['error'] ?? '';

        self::assertStringContainsString('appears 2 times', $error);
        $this->in($this->kb->a);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM edit_proposals WHERE note_id = ?', [$note->getId()]));
        self::assertInstanceOf(Note::class, $this->em->find(Note::class, $note->getId()));
    }
}
