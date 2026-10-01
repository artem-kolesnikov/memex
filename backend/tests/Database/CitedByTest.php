<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * Local blast radius, delivered in the write result.
 *
 * The thing under test is not "a list of backlinks comes back" — `get` has
 * returned those since MVP1. It is that **the agent that just changed a note
 * is told, unasked, which other notes talk about it**, at the one moment it
 * still knows what the change meant.
 *
 * That is a claim about reach as much as about correctness. `blast_radius`
 * already answers this question and cannot serve this purpose: it is a curator
 * verb, so the agent role — the default, the one every ordinary connected
 * assistant holds — is refused outright, and it has to be called on purpose by
 * something that thought to call it. So the properties worth pinning are:
 *
 *  - it reaches an AGENT-role token, on a HELD write, where nothing else does;
 *  - the number in the sentence is the true number, and a slice says it is one
 *    (the failure this feature would fail at silently — "3 notes cite this"
 *    when it is thirty reads as "you have seen them all");
 *  - the four kinds of write say the four different things that are true of
 *    them, because a merge does not break links and a delete does;
 *  - the write's own targets are never reported back to it as neighbours;
 *  - and it never crosses a team.
 */
final class CitedByTest extends ApiTestCase
{
    /** @return array<string, mixed> the tool result payload */
    private function callTool(string $tool, string $bearer, array $arguments = []): array
    {
        $this->request('POST', '/mcp', $bearer, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
        self::assertSame(200, $this->httpStatus(), "$tool did not answer: ".$this->body());

        $result = $this->jsonResponse()['result'];
        self::assertNotTrue($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));

        return json_decode($result['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);
    }

    // ---- the core case ----------------------------------------------------

    public function testEditingACitedNoteNamesTheNotesThatCiteIt(): void
    {
        $target = $this->kb->a->note('Hermes VM — operations runbook', 'How the box is run.');
        $one = $this->kb->a->note('Deploy checklist', 'Follow [[Hermes VM — operations runbook]] first.');
        $two = $this->kb->a->note('Incident log', 'Per [[Hermes VM — operations runbook]], restart nginx.');

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'note_id' => $target->getId(),
            'body_md' => 'The box is gone; everything runs on Lightsail now.',
        ]);

        self::assertFalse($result['applied'], 'an agent-role edit must still be held — this notice does not touch the gate');
        self::assertArrayHasKey('cited_by', $result,
            'the agent that just rewrote a cited note was told nothing about what cites it');
        self::assertSame(2, $result['cited_by']['total']);
        self::assertSame(
            [(int) $one->getId(), (int) $two->getId()],
            array_column($result['cited_by']['notes'], 'note_id')
        );
        self::assertStringContainsString('Cited by 2 notes', $result['cited_by']['says']);
        // The edit wording, not one of the other three.
        self::assertStringContainsString('no longer true', $result['cited_by']['says']);
    }

    public function testANoteNobodyCitesCarriesNoSectionAtAll(): void
    {
        $lonely = $this->kb->a->note('A note nothing points at', 'Body.');

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'note_id' => $lonely->getId(),
            'body_md' => 'Rewritten.',
        ]);

        // Absent, not an empty array: every MCP write of every user pays for
        // this key, and most notes have no citers.
        self::assertArrayNotHasKey('cited_by', $result);
    }

    public function testANoteThatLinksToItselfIsNotItsOwnBlastRadius(): void
    {
        $self = $this->kb->a->note('Self-referential', 'See [[Self-referential]] for more.');

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'note_id' => $self->getId(),
            'body_md' => 'Rewritten, still [[Self-referential]].',
        ]);

        self::assertArrayNotHasKey('cited_by', $result,
            'telling an agent to go and check the note it is already editing is noise');
    }

    // ---- the number in the sentence ---------------------------------------

    public function testASliceSaysItIsOneAndQuotesTheTrueTotal(): void
    {
        $hub = $this->kb->a->note('Hub', 'Everything points here.');
        for ($i = 1; $i <= 12; ++$i) {
            $this->kb->a->note("Citer $i", 'Refer to [[Hub]].');
        }

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'note_id' => $hub->getId(),
            'body_md' => 'The hub moved.',
        ]);

        $cited = $result['cited_by'];
        self::assertSame(12, $cited['total'], 'the exact number, not the number that fitted');
        self::assertCount(10, $cited['notes'], 'ten named is the bound; see CurationQueue::CITERS_NAMED');
        self::assertStringContainsString('Cited by 12 notes', $cited['says']);
        // A list that looks complete and is not is worse than no list.
        self::assertStringContainsString('10 least recently updated', $cited['says']);
        self::assertStringContainsString('all 12 backlinks', $cited['says']);
    }

    public function testTenCitersAreNamedWithoutClaimingToBeASlice(): void
    {
        $hub = $this->kb->a->note('Hub', 'Everything points here.');
        for ($i = 1; $i <= 10; ++$i) {
            $this->kb->a->note("Citer $i", 'Refer to [[Hub]].');
        }

        $cited = $this->callTool('propose', $this->kb->a->agentBearer, [
            'note_id' => $hub->getId(),
            'body_md' => 'The hub moved.',
        ])['cited_by'];

        self::assertSame(10, $cited['total']);
        self::assertStringNotContainsString('least recently updated', $cited['says'],
            'ten shown out of ten is not a truncation and must not be announced as one');
    }

    public function testTwoLinksFromOneNoteAreOneCiter(): void
    {
        $target = $this->kb->a->note('Postgres', 'The database.');
        $this->kb->a->note('Storage', 'We use [[Postgres]]. More on [[Postgres]] below.');

        $cited = $this->callTool('propose', $this->kb->a->agentBearer, [
            'note_id' => $target->getId(),
            'body_md' => 'Replaced.',
        ])['cited_by'];

        self::assertSame(1, $cited['total'], 'a note that links twice is still one note to go and read');
        self::assertCount(1, $cited['notes']);
        // One citer, and the sentence has to say so in English. A live probe
        // said "1 notes already used this title" before the count became a
        // slot with a singular form; a number-neutral remainder is what keeps
        // that from needing ten strings instead of five.
        self::assertStringContainsString('Cited by 1 note.', $cited['says']);
        self::assertStringNotContainsString('1 notes', $cited['says']);
    }

    // ---- the four kinds of write ------------------------------------------

    public function testADeleteSaysTheLinksWillStopResolving(): void
    {
        $target = $this->kb->a->note('Doomed', 'Body.');
        $this->kb->a->note('Citer', 'See [[Doomed]].');

        $result = $this->callTool('propose_delete', $this->kb->a->agentBearer, [
            'note_id' => $target->getId(),
            'reason' => 'Superseded.',
        ]);

        self::assertArrayHasKey('cited_by', $result);
        // NoteWriter sets to_note_id NULL on inbound links and keeps
        // raw_target: the citing note is left with a `[[name]]` that resolves
        // to nothing. That is a different fact from an edit's.
        self::assertStringContainsString('stop resolving', $result['cited_by']['says']);
    }

    public function testAMergeSaysTheLinksSurviveAndPointSomewhereElse(): void
    {
        $absorb = $this->kb->a->note('Old page', 'Body.');
        $keeper = $this->kb->a->note('New page', 'Body.');
        $citesAbsorbed = $this->kb->a->note('Cites the old one', 'See [[Old page]].');
        $citesKeeper = $this->kb->a->note('Cites the new one', 'See [[New page]].');

        $result = $this->callTool('propose_merge', $this->kb->a->agentBearer, [
            'note_id' => $absorb->getId(),
            'into_note_id' => $keeper->getId(),
            'merged_body_md' => 'Both, together.',
        ]);

        $cited = $result['cited_by'];
        // BOTH notes' citers: the keeper's body is being rewritten too, so its
        // own citers are equally in the radius.
        self::assertSame(
            [(int) $citesAbsorbed->getId(), (int) $citesKeeper->getId()],
            array_column($cited['notes'], 'note_id')
        );
        self::assertStringContainsString('does not break their links', $cited['says']);
    }

    public function testAMergeDoesNotReportTheTwoNotesToEachOther(): void
    {
        $absorb = $this->kb->a->note('Old page', 'Superseded by [[New page]].');
        $keeper = $this->kb->a->note('New page', 'Replaces [[Old page]].');

        $result = $this->callTool('propose_merge', $this->kb->a->agentBearer, [
            'note_id' => $absorb->getId(),
            'into_note_id' => $keeper->getId(),
        ]);

        self::assertArrayNotHasKey('cited_by', $result,
            'the only notes citing these two are these two — the agent has them both in hand');
    }

    public function testANewNoteThatFilledADanglingLinkSaysToCheckTheMatch(): void
    {
        // Written BEFORE the note exists, so the link dangles and catch-up
        // resolution claims it when the title finally appears.
        $waiting = $this->kb->a->note('Waiting', 'One day there will be a [[Glossary]].');

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'title' => 'Glossary',
            'body_md' => 'Terms of art.',
        ]);

        self::assertArrayHasKey('cited_by', $result,
            'catch-up resolution just pointed a dangling link here and said nothing');
        self::assertSame([(int) $waiting->getId()], array_column($result['cited_by']['notes'], 'note_id'));
        // The create wording: a name match is not a meaning match, and this is
        // the one write where the citers arrived because of the note rather
        // than the other way round.
        self::assertStringContainsString('by name alone', $result['cited_by']['says']);
    }

    public function testAnOrdinaryNewNoteCarriesNothing(): void
    {
        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'title' => 'Something nobody was waiting for',
            'body_md' => 'Body.',
        ]);

        self::assertArrayNotHasKey('cited_by', $result);
    }

    // ---- tenancy ----------------------------------------------------------

    public function testTheSameTitleInAnotherTeamDoesNotBringItsCitersAlong(): void
    {
        $target = $this->kb->a->note('Shared title', 'A version.');
        $mine = $this->kb->a->note('A cites it', 'See [[Shared title]].');
        // Team B has a note of the same title and a note citing it. Neither is
        // A's business, and B's citer must not appear in A's answer.
        $theirs = $this->kb->b->note('Shared title', 'B version.');
        $this->kb->b->note('B cites it', 'See [[Shared title]].');
        self::assertSame($target->getId(), $theirs->getId(), 'Precondition: B\'s cited note holds the number of A\'s');

        $cited = $this->callTool('propose', $this->kb->a->agentBearer, [
            'note_id' => $target->getId(),
            'body_md' => 'Rewritten.',
        ])['cited_by'];

        self::assertSame(1, $cited['total'], 'a neighbour was counted across a team boundary');
        self::assertSame([(int) $mine->getId()], array_column($cited['notes'], 'note_id'));
    }
}
