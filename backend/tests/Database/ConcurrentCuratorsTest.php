<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * Two curators working one knowledge base at the same time (operator
 * 2026-08-26: "user may delegate multiple agents with curation
 * capabilities, who will run independently and may work on the same documents
 * in concurrency — memex must have a mechanism how to deal with this cases").
 *
 * It is two problems and they have different answers, which is why this file
 * has two halves.
 *
 * **Waste.** The cooldown that keeps two passes apart is derived from
 * `curator_log`, and a pass writes its `examined` rows at the END of a run. So
 * nothing at all separated two curators starting an hour apart: both were
 * handed the same ranked list. Leases fix that, and they are advisory — an
 * expired one means duplicated work, never a wrong write.
 *
 * **Silent overwrite.** There is no locking anywhere in this codebase, and a
 * curator's safe writes apply with no review step, so two curators sending
 * whole bodies for one note was last-writer-wins with nobody in the room. The
 * answer is not a lock: an anchored patch already fails loudly when the text it
 * names has moved, so a curator's whole-body edit is held for review and an
 * anchored one still applies on the spot.
 */
final class ConcurrentCuratorsTest extends ApiTestCase
{
    /** @return array<string, mixed> the tool's own payload, decoded */
    private function payload(string $tool, string $bearer, array $arguments = []): array
    {
        $this->request('POST', '/mcp', $bearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $result = $this->jsonResponse()['result'];
        self::assertNotTrue($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));

        return json_decode($result['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);
    }

    /** A second curator connection in vault A, so two can run at once. */
    private function secondCuratorBearer(): string
    {
        $bearer = 'mxt_curator_second_'.bin2hex(random_bytes(4));
        $this->kb->a->connection('second-curator', $bearer, \App\Entity\ApiToken::ROLE_CURATOR);

        return $bearer;
    }

    private function notesNeedingWork(int $n): array
    {
        $ids = [];
        for ($i = 1; $i <= $n; ++$i) {
            // Untagged and undescribed, so every one of them is genuinely in
            // the queue rather than resting.
            $ids[] = (int) $this->kb->a->note("Note $i", "Body of note $i.")->getId();
        }

        return $ids;
    }

    public function testASecondRunIsNotHandedTheNotesTheFirstIsWorking(): void
    {
        $this->notesNeedingWork(4);
        $second = $this->secondCuratorBearer();

        $first = $this->payload('curation_candidates', $this->kb->a->curatorBearer, ['limit' => 2]);
        $firstIds = array_column($first['candidates'], 'note_id');
        self::assertCount(2, $firstIds);

        $other = $this->payload('curation_candidates', $second, ['limit' => 2]);
        $otherIds = array_column($other['candidates'], 'note_id');

        self::assertSame([], array_intersect($firstIds, $otherIds),
            'two curation runs were handed the same notes, which is the duplicated pass C-9 exists to stop');
        self::assertCount(2, $otherIds, 'and the second run still got a full batch — there were four notes');

        // Never a silent cap: the second run is told why its slice is not the
        // whole queue, and `total` still describes the knowledge base.
        self::assertSame(2, $other['leased_elsewhere']);
        self::assertSame(4, $other['total']);
        self::assertArrayNotHasKey('leased_elsewhere', $first, 'the first run had nobody to share with');
    }

    public function testARunIsHandedItsOwnWorkAgainRatherThanAnEmptyQueue(): void
    {
        $this->notesNeedingWork(2);

        $first = $this->payload('curation_candidates', $this->kb->a->curatorBearer, ['limit' => 2]);
        $again = $this->payload('curation_candidates', $this->kb->a->curatorBearer, ['limit' => 2]);

        // A pass that was interrupted, or that works in batches, must not watch
        // the queue empty in front of it because of its own claim.
        self::assertSame(
            array_column($first['candidates'], 'note_id'),
            array_column($again['candidates'], 'note_id'),
        );
    }

    public function testRecordingTheRunReleasesWhatItWasHolding(): void
    {
        $ids = $this->notesNeedingWork(2);
        $second = $this->secondCuratorBearer();

        $this->payload('curation_candidates', $this->kb->a->curatorBearer, ['limit' => 2]);
        $during = $this->payload('curation_candidates', $second, ['limit' => 2]);
        self::assertSame([], $during['candidates'], 'fixture: everything is claimed while the first run works');
        self::assertSame(2, $during['leased_elsewhere']);

        $this->payload('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary',
            'description' => 'A pass over both notes.',
            'examined' => $ids,
        ]);

        // Released — and what keeps the notes out of the next run now is the
        // cooldown the `examined` rows carry, which is the durable version of
        // the same statement.
        $after = $this->payload('curation_candidates', $second, ['limit' => 2]);
        self::assertArrayNotHasKey('leased_elsewhere', $after, 'the claim outlived the run that made it');
    }

    public function testTheBrowsersOwnViewOfTheQueueIgnoresLeasesEntirely(): void
    {
        $this->notesNeedingWork(2);
        $this->payload('curation_candidates', $this->kb->a->curatorBearer, ['limit' => 2]);

        // The owner is not doing the work — they are looking at their own
        // knowledge base — so what another connection has claimed is none of
        // this screen's business. A settings pane that went empty while a
        // curator ran would be reporting somebody else's turn as the state of
        // the vault.
        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/curation/attention');
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertNotEmpty($this->jsonResponse()['candidates']);
    }

    public function testWalkingTheQueueByOffsetIsNotFilteredAtAll(): void
    {
        $ids = $this->notesNeedingWork(6);
        $second = $this->secondCuratorBearer();

        // One run takes a batch, so there ARE leases to interfere with.
        $first = $this->payload('curation_candidates', $this->kb->a->curatorBearer, ['limit' => 2]);
        self::assertCount(2, $first['candidates']);

        // A caller walking offsets is enumerating the queue, not taking a
        // batch, and gets it whole. Filtering here cannot be made to work: the
        // lease set changes between pages — one run paging forward releases
        // what it held — so page 2 of an excluded walk can re-serve page 1 and
        // skip what was just freed (codex, 2026-08-26).
        $walked = [];
        for ($offset = 0; $offset < 6; $offset += 2) {
            $page = $this->payload('curation_candidates', $second, ['limit' => 2, 'offset' => $offset]);
            self::assertArrayNotHasKey('leased_elsewhere', $page, 'an offset walk withholds nothing');
            $walked = array_merge($walked, array_column($page['candidates'], 'note_id'));
        }

        sort($walked);
        sort($ids);
        self::assertSame($ids, $walked, 'the walk neither skipped nor repeated a note');

        // And it claimed nothing on the way, so the first run still holds what
        // it was working.
        $after = $this->payload('curation_candidates', $this->kb->a->curatorBearer, ['limit' => 2]);
        self::assertSame(
            array_column($first['candidates'], 'note_id'),
            array_column($after['candidates'], 'note_id'),
        );
    }

    public function testTheWithheldCountIsWhatThisQueryLostAndNotTheSizeOfTheLeaseList(): void
    {
        // One note with no tags AND no description, one with neither but
        // filtered out by the reason below.
        $untagged = (int) $this->kb->a->note('Untagged and undescribed', 'Body.')->getId();
        $this->kb->a->note('Also undescribed', 'Body.', ['a-tag']);
        $second = $this->secondCuratorBearer();

        $this->payload('curation_candidates', $this->kb->a->curatorBearer, ['limit' => 1, 'reason' => 'untagged']);

        // The lease list holds one note, and that note IS untagged — so a
        // caller asking for untagged notes really did lose a row.
        $narrow = $this->payload('curation_candidates', $second, ['limit' => 5, 'reason' => 'untagged']);
        self::assertSame(1, $narrow['leased_elsewhere']);
        self::assertNotContains($untagged, array_column($narrow['candidates'], 'note_id'));

        // A caller asking for something the leased note does not carry lost
        // NOTHING, and must not be told somebody is in its way. Counting the
        // raw lease list would have reported 1 here.
        $other = $this->payload('curation_candidates', $second, ['limit' => 5, 'reason' => 'nonstandard_tags']);
        self::assertArrayNotHasKey('leased_elsewhere', $other);
    }

    public function testTheRestRouteSaysWhetherACuratorsEditActuallyApplied(): void
    {
        $note = $this->kb->a->note('The runbook', 'Deploys go to the old box.');

        // Anchored: applies on the spot, and the route must say so. It used to
        // answer "held for review — the note is unchanged" to every token
        // write, so a caller that believed it and re-sent the edit collected an
        // anchor conflict caused by its own first call.
        $this->request('PUT', '/api/notes/'.$note->getId(), $this->kb->a->curatorBearer, [
            'patch' => [['find' => 'the old box', 'replace' => 'the new box']],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertTrue($this->jsonResponse()['applied']);
        self::assertSame('Deploys go to the new box.', $this->noteBody((int) $note->getId()));

        // A whole body from the same connection is held, and says why.
        $this->request('PUT', '/api/notes/'.$note->getId(), $this->kb->a->curatorBearer, [
            'body_md' => 'Deploys go somewhere else entirely.',
        ]);
        self::assertSame(202, $this->httpStatus(), $this->body());
        self::assertFalse($this->jsonResponse()['applied']);
        self::assertStringContainsString('replaces the whole body', $this->jsonResponse()['review']);
        self::assertSame('Deploys go to the new box.', $this->noteBody((int) $note->getId()));

        // An agent token is held as it always was, and is told nothing about
        // anchoring — the rule is about connections whose writes apply.
        $this->request('PUT', '/api/notes/'.$note->getId(), $this->kb->a->agentBearer, [
            'body_md' => 'An agent rewrite.',
        ]);
        self::assertSame(202, $this->httpStatus(), $this->body());
        self::assertFalse($this->jsonResponse()['applied']);
        self::assertStringNotContainsString('replaces the whole body', $this->jsonResponse()['review']);
    }

    public function testACuratorsWholeBodyEditIsHeldWhileAnAnchoredOneApplies(): void
    {
        $note = $this->kb->a->note('The runbook', 'Deploys go to the old box.');
        $id = (int) $note->getId();

        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'propose', 'arguments' => [
                'note_id' => $id,
                'body_md' => 'Deploys go to the new box.',
            ]],
        ]);
        $held = $this->jsonResponse()['result'];
        self::assertFalse($held['structuredContent']['applied']);
        self::assertStringContainsString('replaces the whole body', json_encode($held, JSON_THROW_ON_ERROR),
            'and the caller is told what to send instead, since the same edit anchored would apply');
        self::assertSame('Deploys go to the old box.', $this->noteBody($id),
            'the note is untouched — a full body from a curator no longer wins in silence');

        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'propose', 'arguments' => [
                'note_id' => $id,
                'patch' => [['find' => 'the old box', 'replace' => 'the new box']],
            ]],
        ]);
        self::assertTrue($this->jsonResponse()['result']['structuredContent']['applied'],
            'an anchored edit still applies on the spot — this is a change of SHAPE, not of privilege');
        self::assertSame('Deploys go to the new box.', $this->noteBody($id));
    }

    public function testTheSecondCuratorsAnchoredEditIsRefusedRatherThanReverting(): void
    {
        $note = $this->kb->a->note('The runbook', "Deploys go to the old box.\n\nEverything else stays.");
        $id = (int) $note->getId();
        $second = $this->secondCuratorBearer();

        // Both read the note, then both act. The first lands.
        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'propose', 'arguments' => [
                'note_id' => $id,
                'patch' => [['find' => 'the old box', 'replace' => 'the new box']],
            ]],
        ]);
        self::assertTrue($this->jsonResponse()['result']['structuredContent']['applied']);

        // The second is working from what it read a moment ago. Its anchor is
        // gone, and THAT is the whole mechanism: it fails in front of the
        // author instead of quietly putting the old sentence back.
        $this->request('POST', '/mcp', $second, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'propose', 'arguments' => [
                'note_id' => $id,
                'patch' => [['find' => 'the old box', 'replace' => 'the other box']],
            ]],
        ]);
        $result = $this->jsonResponse()['result'];
        self::assertTrue($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame("Deploys go to the new box.\n\nEverything else stays.", $this->noteBody($id),
            "the first curator's work survived the second's stale edit");
    }

    public function testAHumanEditingInTheirOwnBrowserIsUnaffected(): void
    {
        // The rule is about connections whose writes apply without review. A
        // person editing their own note in the editor sends a whole body every
        // time, and holding THAT for their own review would be absurd.
        $note = $this->kb->a->note('The runbook', 'Deploys go to the old box.');
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$note->getId(), ['body_md' => 'Deploys go to the new box.']);

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame('Deploys go to the new box.', $this->noteBody((int) $note->getId()));
    }

    /** `body()` is the response body on ApiTestCase; this is the note's. */
    private function noteBody(int $noteId): string
    {
        $this->in($this->kb->a);

        return (string) $this->em->getConnection()->fetchOne('SELECT body_md FROM notes WHERE id = ?', [$noteId]);
    }
}
