<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * The rotation: which notes a run is handed, and why (2026-08-24).
 *
 * Written from a post-mortem rather than from a spec. A real unattended pass
 * called `curation_candidates`, read `reason_counts` as zero in every class,
 * and stopped — reporting an empty queue and a complete run. The queue held 76
 * notes. Every one of them was structurally sound and none of them had ever
 * been read, which is precisely the state the ranking's third and fourth bands
 * exist to serve.
 *
 * Two defects were behind it, and both are fixed here rather than described:
 *
 *  1. **Nothing recorded that a note had been READ.** The Curator log holds
 *     what a curator wrote, so a note examined and left alone was, to the next
 *     run, indistinguishable from one nobody had ever opened. Coverage could
 *     not accumulate: every pass was handed the same head of the queue.
 *  2. **Rest was flat.** Seven days for every note forever, so an evergreen
 *     cluster came back every week for the rest of time and consumed a budget
 *     of 15-25 notes that the unread tail never saw.
 *
 * What is pinned below is therefore the whole loop, not its parts: a pass that
 * records what it read is handed different notes next time; a note the passes
 * keep approving of rests longer each round; and the four things that mean
 * "something happened this note has not been read against" all cut that rest
 * short — including the one no timestamp on the note itself can show, which is
 * a neighbour moving.
 */
final class CurationRotationTest extends ApiTestCase
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

    /** @return array<string, mixed> the tool result payload, expected to be an error */
    private function callToolExpectingError(string $tool, string $bearer, array $arguments = []): array
    {
        $this->request('POST', '/mcp', $bearer, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);

        return json_decode($this->jsonResponse()['result']['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);
    }

    /**
     * A structurally sound note: tagged, summarised, embedded and linked.
     *
     * It has to be genuinely clean, not merely plausible. A note with no links
     * is `disconnected` and one with no tags is `untagged`, and either would
     * put a defect in the census — which would make every test below pass for
     * the wrong reason, since a defective note is eligible whatever the
     * rotation does. So each call makes a PAIR that cite each other, and
     * returns the first.
     */
    private function soundNote(string $title, string $body = 'Settled content.'): \App\Entity\Note
    {
        $partner = $title.' — companion';
        $note = $this->kb->a->note($title, $body."\n\nSee [[$partner]].", ['reference'], summary: 'A description.');
        $this->kb->a->note($partner, "See [[$title]].", ['reference'], summary: 'A description.');

        return $note;
    }

    /**
     * Rewrite a note as a person would, so `last_actor` is not the curator.
     *
     * By id and through a freshly-resolved container: every HTTP call above
     * reboots the kernel, so a Note handed over by the fixture is detached by
     * the time a test wants to edit it directly.
     *
     * @param string[]|null $tags null leaves tags alone; [] strips them
     */
    private function humanEdit(int $id, ?string $body, ?array $tags = null): void
    {
        $container = self::getContainer();
        $em = $container->get(\Doctrine\ORM\EntityManagerInterface::class);
        $this->in($this->kb->a);
        $note = $this->noteNumbered($id);
        $container->get(\App\Service\NoteWriter::class)
            ->update($note, null, $body, $tags, \App\Service\EmbeddingSpend::Metered, actor: \App\Entity\Note::ACTOR_HUMAN);
    }

    /** @return array<string, mixed>|null the candidate row for this note, if the queue returns it */
    private function row(int $id, array $args = []): ?array
    {
        foreach ($this->candidates($args)['candidates'] as $row) {
            if ($row['note_id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Close a pass, recording what it read — then push the whole log an hour
     * into the past.
     *
     * The rewinds are not a fudge, they are the clock a real run has and a
     * test does not. `curator_log.created_at` and `notes.updated_at` are both
     * whole seconds, and a test writes a note, curates it and edits it inside
     * one second — so every comparison the rotation makes ("was this edited
     * since it was read?") is a tie, and ties are what the rotation must not
     * be tested on. Real events are hours apart.
     *
     * So the sequence a real pass has is imposed: existing notes are aged past
     * the pass that is about to read them, and the pass is then aged past
     * whatever the test does next. Every row shifts by the same interval, so
     * the ORDER of everything is untouched — only the ties are broken.
     *
     * @param int[] $ids
     */
    private function closeRun(array $ids, string $summary = 'A pass.'): array
    {
        $this->in($this->kb->a);
        $conn = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->getConnection();
        // Everything that already exists happened BEFORE this pass read it.
        $conn->executeStatement("UPDATE notes SET updated_at = datetime(updated_at, '-2 hours')");

        $result = $this->callTool('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary',
            'description' => $summary,
            'examined' => $ids,
        ]);

        // ...and this pass happened before whatever the test does next.
        $this->ageLog();

        return $result;
    }

    /**
     * Push the Curator log an hour into the past — see {@see closeRun()}.
     *
     * Needed after any write that logs, not only a pass: a curator edit lands
     * a log row too, and an edit that follows it in the same second would
     * otherwise tie with it.
     */
    private function ageLog(): void
    {
        $this->in($this->kb->a);
        self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)
            ->getConnection()
            ->executeStatement("UPDATE curator_log SET created_at = datetime(created_at, '-1 hour')");
    }

    private function candidates(array $args = []): array
    {
        return $this->callTool('curation_candidates', $this->kb->a->curatorBearer, $args);
    }

    /** @return int[] */
    private function candidateIds(array $args = []): array
    {
        return array_column($this->candidates($args)['candidates'], 'note_id');
    }

    // ---- the post-mortem itself -------------------------------------------

    public function testAZeroDefectCensusStillReturnsAQueueOfWork(): void
    {
        // The exact shape of the run that failed: nothing broken anywhere, and
        // notes nobody has ever read.
        foreach (range(1, 5) as $n) {
            $this->soundNote('Sound note '.$n);
        }

        $result = $this->candidates();

        self::assertSame(
            0,
            array_sum($result['reason_counts']),
            'the fixture is meant to be structurally clean — if it is not, this test is not '
            .'reproducing the run it was written for'
        );
        self::assertGreaterThan(0, $result['total'],
            'a zero defect census emptied the queue. That is the post-mortem bug: the census '
            .'counts what is BROKEN, and a note nobody has read is not broken — it is unread, '
            .'which is the third band of the ranking and most of the work in a mature vault');
        self::assertNotEmpty($result['candidates']);
    }

    public function testAnUnreadNoteIsDistinguishableFromOneThreePassesApprovedOf(): void
    {
        $note = $this->soundNote('Evergreen');

        $before = $this->row((int) $note->getId());
        self::assertNotNull($before);
        self::assertNull($before['last_curated_at']);
        self::assertSame(0, $before['clean_passes'],
            'a note nobody has opened must report zero clean passes, not silence');

        $this->closeRun([(int) $note->getId()]);

        // Cooldown 0 = a deliberate re-sweep, which is the only way to see the
        // note again immediately. What matters is what it now SAYS about
        // itself: `last_curated_at: null` and an empty `reasons` was the whole
        // ambiguity — it read identically for "never opened" and "read and
        // found sound", and a run could not tell the two apart.
        $row = $this->row((int) $note->getId(), ['cooldown_days' => 0]);

        self::assertNotNull($row, 'a deliberate re-sweep must still return the note');
        self::assertNotNull($row['last_curated_at'],
            'a pass read this note and said so, and the queue still reports it as never curated');
        self::assertSame(1, $row['clean_passes']);
    }

    // ---- the rest ladder ---------------------------------------------------

    public function testEachCleanPassIsCountedAndTheCountIsUncapped(): void
    {
        $note = $this->soundNote('Evergreen');
        $id = (int) $note->getId();

        // `cooldown_days: 0` is the deliberate re-sweep — the only way to see
        // a resting note at all. It also zeroes `rest_days` (0 x anything),
        // which is correct: the caller asked for no rest. So the ladder's
        // POSITION is read here and its LENGTH in the test below.
        $sweep = ['cooldown_days' => 0];

        foreach ([1, 2, 3, 4, 5] as $expected) {
            $this->closeRun([$id]);
            self::assertSame($expected, $this->row($id, $sweep)['clean_passes'],
                'a pass read this note and found nothing, and the streak did not advance');
        }
    }

    public function testTheRestANoteEarnsDoublesAndThenStops(): void
    {
        $note = $this->soundNote('Evergreen');
        $id = (int) $note->getId();

        // Woken each round by a human edit, which is what makes the note
        // visible under the DEFAULT cooldown — the only setting under which
        // `rest_days` means anything. The edit is not a clean pass, so it does
        // not touch the streak; only `examined` rows do.
        $ladder = static fn (int $n): int => [7, 14, 28, 56, 56][$n];
        foreach ([0, 1, 2, 3, 4] as $passes) {
            $this->humanEdit($id, 'Revised '.$passes.'.');
            self::assertSame($ladder($passes), $this->row($id)['rest_days'],
                "after $passes clean passes the earned rest is wrong");
            $this->closeRun([$id]);
        }
        // The cap held at the fourth and fifth entries above. An uncapped
        // ladder is indistinguishable from deletion — after eight clean passes
        // a note would rest for five years — and "evergreen" must never
        // quietly become "never looked at again".
    }

    public function testARestingNoteLeavesTheQueueSoTheUnreadTailIsReached(): void
    {
        $evergreen = $this->soundNote('Evergreen');
        $unread = $this->soundNote('Never opened');

        $this->closeRun([(int) $evergreen->getId()]);

        $ids = $this->candidateIds();
        self::assertNotContains((int) $evergreen->getId(), $ids,
            'a note a pass just read and approved of came straight back — this is what made every '
            .'run examine the same head of the queue');
        self::assertContains((int) $unread->getId(), $ids,
            'the note nobody has read must be what the budget goes to instead');
    }

    public function testRealWorkBreaksTheStreakSoTheLadderRestarts(): void
    {
        $note = $this->soundNote('Drifting');
        $id = (int) $note->getId();

        $this->closeRun([$id]);
        $this->closeRun([$id]);

        // An edit filed against the note is not a clean pass — it is the note
        // needing something. The streak is the count of consecutive `examined`
        // rows, so anything else at the head of the log resets it.
        $this->callTool('propose', $this->kb->a->curatorBearer, [
            'note_id' => $id,
            'body_md' => 'Corrected.',
            'comment' => 'Fixed the stale line.',
        ]);
        $this->ageLog();

        // Woken by a human edit so the row is visible under the real cooldown,
        // which is the only setting where `rest_days` is meaningful.
        $this->humanEdit($id, 'Somebody else revised it.');
        $row = $this->row($id);

        self::assertNotNull($row);
        self::assertSame(0, $row['clean_passes'],
            'the note needed work, so it is not evergreen and must not keep the rest it had earned');
        self::assertSame(7, $row['rest_days']);
    }

    // ---- what cuts the rest short -----------------------------------------

    public function testANeighbourChangingWakesARestingNoteAndLiftsIt(): void
    {
        $neighbour = $this->soundNote('Hermes VM — operations runbook');
        $note = $this->kb->a->note(
            'Deploy checklist',
            'Follow [[Hermes VM — operations runbook]] first.',
            ['reference'],
            summary: 'A description.'
        );
        $resting = $this->soundNote('Unrelated and settled');

        $this->closeRun([(int) $note->getId(), (int) $resting->getId()]);
        self::assertNotContains((int) $note->getId(), $this->candidateIds(),
            'both notes were just read, so both should be resting before the neighbour moves');

        // The neighbour changes, by a human. The checklist's own `updated_at`
        // does not move — that is the entire point: nothing on the note itself
        // can tell anyone it might now be wrong.
        $this->humanEdit((int) $neighbour->getId(), 'The box is gone; everything runs on Lightsail now.');

        $rows = $this->candidates()['candidates'];
        $ids = array_column($rows, 'note_id');

        self::assertContains((int) $note->getId(), $ids,
            'the note that links to what just changed is still resting — this is the staleness '
            .'no other producer sees, and the rotation is supposed to wake it');
        self::assertNotContains((int) $resting->getId(), $ids,
            'a note with no changed neighbour must stay at rest — waking everything on every edit '
            .'is the same as having no rest at all');

        $row = $this->row((int) $note->getId());
        self::assertSame(1, $row['changed_neighbours']);
        self::assertNotNull($row['neighbour_changed_at']);
        self::assertSame((int) $note->getId(), $ids[0],
            'a disturbed note must rank above the merely unread — it is the one with a reason to '
            .'be wrong');
    }

    public function testACuratorsOwnEditDoesNotWakeEverythingItCites(): void
    {
        $neighbour = $this->soundNote('Hermes VM — operations runbook');
        $note = $this->kb->a->note(
            'Deploy checklist',
            'Follow [[Hermes VM — operations runbook]] first.',
            ['reference'],
            summary: 'A description.'
        );

        $this->closeRun([(int) $note->getId()]);

        // The curator edits the neighbour itself, as a pass does. If that woke
        // every note citing it, a run would spend the next pass on the wreckage
        // of its own last one and the vault would never go quiet.
        $this->callTool('propose', $this->kb->a->curatorBearer, [
            'note_id' => (int) $neighbour->getId(),
            'body_md' => 'Tidied by the curator.',
            'comment' => 'Housekeeping.',
        ]);

        self::assertNotContains((int) $note->getId(), $this->candidateIds(),
            'the curator woke a note by editing its neighbour during its own pass — chasing its '
            .'own tail, which is what the last_actor exclusion exists to prevent');
    }

    public function testADefectAppearingCutsTheRestShort(): void
    {
        $note = $this->soundNote('Was fine');
        $id = (int) $note->getId();

        $this->closeRun([$id]);
        self::assertNotContains($id, $this->candidateIds());

        $this->humanEdit($id, null, []);

        $rows = $this->candidates()['candidates'];
        self::assertContains($id, array_column($rows, 'note_id'),
            'a note that BECAME broken stayed at rest — rest is earned by being sound, and it '
            .'stops the moment the note is not');
        self::assertSame($id, $rows[0]['note_id'],
            'and a broken note outranks every kind of merely-unread one');
        self::assertContains('untagged', $rows[0]['reasons']);
    }

    // ---- recording what was read ------------------------------------------

    public function testABrokenNoteListedAsExaminedIsReportedBack(): void
    {
        $untagged = $this->kb->a->note('Nobody filed this', 'Body.', [], summary: 'A description.');

        $result = $this->closeRun([(int) $untagged->getId()]);

        self::assertSame(1, $result['examined']);
        self::assertSame([(int) $untagged->getId()], array_column($result['still_defective'], 'note_id'),
            'a pass claimed to have read a note that is still broken and was told nothing. The '
            .'row is written either way — the curator may have judged the defect a false positive '
            .'— but it must be SAID, or a defect goes quiet on the strength of a claim');
        self::assertContains('untagged', $result['still_defective'][0]['reasons'],
            'and the response has to name WHICH defect, or the curator cannot tell whether it is '
            .'the one they judged harmless');
    }

    public function testAnIdFromAnotherKnowledgeBaseIsRefusedRatherThanSkipped(): void
    {
        $theirs = $this->kb->b->note('Not yours', 'Body.');

        $result = $this->callToolExpectingError('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary',
            'description' => 'A pass.',
            'examined' => [(int) $theirs->getId()],
        ]);

        // Skipping it silently would let a pass believe it had recorded a sweep
        // it had not, and the notes it thought it covered would never rest.
        self::assertStringContainsString('not in this knowledge base', $result['error']);
    }

    public function testASummaryWithoutExaminedStillLogsAndChangesNothing(): void
    {
        $note = $this->soundNote('Untouched');

        $result = $this->callTool('log', $this->kb->a->curatorBearer, [
            'action' => 'run-summary',
            'description' => 'A pass that recorded nothing.',
        ]);

        self::assertTrue($result['logged']);
        self::assertArrayNotHasKey('examined', $result);
        self::assertContains((int) $note->getId(), $this->candidateIds(),
            'a run-summary with no examined list must leave the rotation exactly where it was');
    }
}
