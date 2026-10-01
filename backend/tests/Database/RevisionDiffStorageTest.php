<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\NoteRevision;
use App\Service\NoteRevisions;

/**
 * Previous states stored as reverse deltas — the chain, and what breaks it.
 *
 * The property is not "a column holds JSON". It is that **every previous
 * version of a note still reads back byte for byte** after the storage stopped
 * holding them. That is a promise about somebody's only copy of their own
 * writing, so it is asserted against exact strings at every step, never
 * against a length or a substring.
 *
 * Driven over HTTP, because the reconstruction that matters is the one the
 * history page and the restore button perform. `LineDiffTest` covers the
 * engine underneath; this covers the chain built out of it, and the three
 * things that can break a chain: pruning its far end, a note leaving the
 * `notes` table, and a row that will not decode.
 */
final class RevisionDiffStorageTest extends ApiTestCase
{
    /** @return array<int, array{body_md: ?string, body_diff: ?string}> newest first */
    private function storedRows(int $noteId): array
    {
        $this->in($this->kb->a);

        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, body_md, body_diff FROM note_revisions WHERE note_id = :n ORDER BY replaced_at DESC, id DESC',
            ['n' => $noteId]
        );
    }

    private function edit(int $id, string $body, bool $legacy = true): void
    {
        $this->sessionRequest('PUT', '/api/notes/'.$id, ['body_md' => $body]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        if ($legacy) {
            $row = $this->storedRows($id)[0];
            if ($row['body_md'] !== null) {
                $patch = json_encode(\App\Service\LineDiff::diff($body, $row['body_md']), JSON_THROW_ON_ERROR);
                if (strlen($patch) < strlen($row['body_md'])) {
                    $this->em->getConnection()->executeStatement(
                        'UPDATE note_revisions SET body_md = NULL, body_diff = ? WHERE id = ?',
                        [$patch, $row['id']],
                    );
                    $this->em->clear();
                }
            }
        }
    }

    /** @return list<string|null> the reconstructed bodies, newest first */
    private function history(int $id): array
    {
        $this->sessionRequest('GET', '/api/notes/'.$id.'/revisions');
        self::assertSame(200, $this->httpStatus(), $this->body());

        return array_column($this->jsonResponse()['revisions'], 'body_md');
    }

    /**
     * A body long enough that a patch against it is smaller than it is.
     *
     * Load-bearing, and the first version of this file did not have it: with a
     * three-line body the JSON patch is bigger than the text, so the writer
     * correctly falls back to storing the body — and five tests that believed
     * they were exercising the chain were exercising the anchor path instead.
     * Every one of them passed. `assertStoredAsDiff()` is what stops that
     * happening again.
     *
     * @param array<int, string> $overrides line index => replacement
     */
    private function longBody(array $overrides = []): string
    {
        $lines = array_map(static fn (int $i): string => "Paragraph $i of the operational runbook.", range(1, 40));
        foreach ($overrides as $at => $text) {
            $lines[$at] = $text;
        }

        return implode("\n", $lines);
    }

    /** The row really is a patch, not a whole copy — a precondition, not a result. */
    private function assertStoredAsDiff(int $noteId, int $index = 0): void
    {
        $rows = $this->storedRows($noteId);
        self::assertArrayHasKey($index, $rows, 'Expected a revision row to exist');
        self::assertNull(
            $rows[$index]['body_md'],
            'Precondition failed: this revision was stored as a whole body, so the test is not exercising the chain'
        );
        self::assertNotNull($rows[$index]['body_diff']);
        self::assertTrue(array_is_list(json_decode($rows[$index]['body_diff'], true, 512, JSON_THROW_ON_ERROR)), 'Compatibility fixtures must remain legacy hashless operation lists.');
    }

    public function testEveryStateInAChainOfEditsReadsBackExactly(): void
    {
        // Long enough that a diff is worth taking, and edited in a different
        // place each time so no two patches are alike.
        $lines = array_map(static fn (int $i): string => "Paragraph $i of the runbook.", range(1, 40));
        $states = [implode("\n", $lines)];
        foreach ([5, 20, 39, 1] as $n => $line) {
            $lines[$line] = "Rewritten in edit $n.";
            $states[] = implode("\n", $lines);
        }

        $note = $this->kb->a->note('Runbook', $states[0]);
        $id = (int) $note->getId();
        $this->loginAs($this->kb->a);

        foreach (array_slice($states, 1) as $body) {
            $this->edit($id, $body);
        }

        // Four edits, four revisions, newest first — so the history is every
        // state except the current one, in reverse.
        $expected = array_reverse(array_slice($states, 0, 4));
        self::assertSame($expected, $this->history($id));
    }

    public function testNewWritesKeepIndependentSnapshots(): void
    {
        $original = $this->longBody();
        $note = $this->kb->a->note('Runbook', $original);
        $id = (int) $note->getId();
        $this->loginAs($this->kb->a);
        $this->edit($id, $this->longBody([10 => 'edited']), legacy: false);

        $rows = $this->storedRows($id);
        self::assertCount(1, $rows);
        self::assertSame($original, $rows[0]['body_md']);
        self::assertNull($rows[0]['body_diff']);
    }

    public function testAnEditWritesOneRowAndRewritesNone(): void
    {
        // The property that makes reverse deltas cheap: the row that was
        // newest already holds the text the next-oldest row was diffed
        // against, so nothing behind the new row has to be recomputed. If this
        // ever stops being true, every edit becomes O(history).
        $note = $this->kb->a->note('Runbook', $this->longBody());
        $id = (int) $note->getId();
        $this->loginAs($this->kb->a);

        $this->edit($id, $this->longBody([5 => 'first edit']));
        $this->assertStoredAsDiff($id);
        $afterFirst = $this->storedRows($id);
        self::assertCount(1, $afterFirst);

        $this->edit($id, $this->longBody([5 => 'first edit', 20 => 'second edit']));
        $afterSecond = $this->storedRows($id);
        self::assertCount(2, $afterSecond);
        self::assertSame(
            $afterFirst[0],
            $afterSecond[1],
            'The existing revision row must be untouched, byte for byte, by a later edit'
        );
    }

    public function testATotalRewriteKeepsTheBodyRatherThanABiggerPatch(): void
    {
        // A patch describing a full replacement is larger than the text it
        // describes (JSON overhead), so the writer keeps the body. This is
        // what makes the worst case exactly the old behaviour rather than
        // slightly worse than it — and it leaves anchors in long chains.
        $note = $this->kb->a->note('Runbook', implode("\n", array_fill(0, 30, 'the original text')));
        $id = (int) $note->getId();
        $this->loginAs($this->kb->a);
        $this->edit($id, implode("\n", array_fill(0, 30, 'nothing whatsoever in common')));

        $rows = $this->storedRows($id);
        self::assertNotNull($rows[0]['body_md'], 'A rewrite must fall back to storing the body');
        self::assertNull($rows[0]['body_diff']);
        self::assertSame(
            [implode("\n", array_fill(0, 30, 'the original text'))],
            $this->history($id),
            'and it must still read back exactly'
        );
    }

    public function testPruningTheOldestDoesNotBreakWhatIsLeft(): void
    {
        // The reason the deltas point backwards at all. A forward chain is
        // anchored on its OLDEST row, which is precisely the row `prune()`
        // deletes when the window fills — the anchor would be the first thing
        // thrown away. Here the anchor is the note itself.
        $note = $this->kb->a->note('Runbook', 'version 0');
        $id = (int) $note->getId();
        $this->loginAs($this->kb->a);

        $keep = NoteRevision::KEEP_PER_NOTE;
        for ($i = 1; $i <= $keep + 5; ++$i) {
            $this->edit($id, "version $i");
        }

        $history = $this->history($id);
        self::assertCount($keep, $history, 'The window is still exactly the window');
        // Newest first: the state before the last edit, and so on back.
        $expected = [];
        for ($i = $keep + 4; $i >= 5; --$i) {
            $expected[] = "version $i";
        }
        self::assertSame($expected, $history);
    }

    public function testARowWrittenBeforeDiffsExistedStillReads(): void
    {
        // No backfill was run, so the live table has 175 rows holding whole
        // bodies. A legacy row is an anchor: it needs no chain in front of it,
        // and it must not break the rows behind it either.
        $v0 = $this->longBody();
        $v1 = $this->longBody([5 => 'first edit']);
        $v2 = $this->longBody([5 => 'first edit', 20 => 'second edit']);

        $note = $this->kb->a->note('Runbook', $v0);
        $id = (int) $note->getId();
        $this->loginAs($this->kb->a);
        $this->edit($id, $v1);
        $this->edit($id, $v2);
        $this->assertStoredAsDiff($id, 0);
        $this->assertStoredAsDiff($id, 1);

        // Turn the OLDER row back into the pre-2026-08-23 shape by hand, with
        // a body the chain could NOT have produced — otherwise this test
        // passes whether or not the anchor is ever read.
        $anchor = $v0."\nA LINE ONLY THE ANCHOR ROW HAS";
        $rows = $this->storedRows($id);
        $this->em->getConnection()->executeStatement(
            'UPDATE note_revisions SET body_md = :b, body_diff = NULL WHERE id = :id',
            ['b' => $anchor, 'id' => $rows[1]['id']]
        );
        // Raw SQL is invisible to entities Doctrine already has in memory, and
        // `forNote()` reads through the ORM. Without this the assertion below
        // is made against the rows as they were before the UPDATE.
        $this->em->clear();

        self::assertSame([$v1, $anchor], $this->history($id));
    }

    public function testANoteInLimboStillHasAReadableHistory(): void
    {
        // The case a base read only from `notes` would have silently broken:
        // a retired note has no row there, and "what did this look like before
        // I deleted it" is the question somebody restoring it is asking.
        $v0 = $this->longBody();
        $note = $this->kb->a->note('Runbook', $v0);
        $id = (int) $note->getId();
        $this->loginAs($this->kb->a);
        $this->edit($id, $this->longBody([5 => 'edited before deleting']));
        $this->assertStoredAsDiff($id);

        $this->sessionRequest('DELETE', '/api/notes/'.$id);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $this->in($this->kb->a);
        self::assertFalse(
            (bool) $this->em->getConnection()->fetchOne('SELECT 1 FROM notes WHERE id = :i', ['i' => $id]),
            'Precondition: the note really has left the notes table'
        );

        self::assertSame([$v0], $this->history($id));
    }

    public function testARestoreReadsThroughTheChainAndComesBackExact(): void
    {
        $v0 = $this->longBody();
        $note = $this->kb->a->note('Runbook', $v0);
        $id = (int) $note->getId();
        $this->loginAs($this->kb->a);
        $this->edit($id, $this->longBody([5 => 'first edit']));
        $this->edit($id, $this->longBody([5 => 'first edit', 20 => 'second edit']));
        $this->assertStoredAsDiff($id, 1);

        // The OLDEST revision — two patches deep, so a restore that only read
        // the row would get nothing at all.
        $this->sessionRequest('GET', '/api/notes/'.$id.'/revisions');
        $oldest = end($this->jsonResponse()['revisions']);

        $this->sessionRequest('POST', '/api/notes/'.$id.'/revisions/'.$oldest['id'].'/restore');
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->sessionRequest('GET', '/api/notes/'.$id);
        self::assertSame($v0, $this->jsonResponse()['body_md']);
    }

    public function testACorruptRowLosesItselfAndOlderOnes_notTheNote(): void
    {
        // The deliberate failure mode. A patch that will not apply must not
        // 500 the note page: the note is not in this table and is never at
        // risk, and a list of dates beats an error page.
        $current = $this->longBody([5 => 'first edit', 20 => 'second edit']);
        $note = $this->kb->a->note('Runbook', $this->longBody());
        $id = (int) $note->getId();
        $this->loginAs($this->kb->a);
        $this->edit($id, $this->longBody([5 => 'first edit']));
        $this->edit($id, $current);
        $this->assertStoredAsDiff($id, 0);
        $this->assertStoredAsDiff($id, 1);

        $rows = $this->storedRows($id);
        // Break the NEWER of the two, so the assertion covers both halves:
        // the bad row itself and the good row behind it that depends on it.
        $this->em->getConnection()->executeStatement(
            'UPDATE note_revisions SET body_diff = :d WHERE id = :id',
            ['d' => '[[0,9999]]', 'id' => $rows[0]['id']]
        );
        // See the note in the legacy-row test: without this, the ORM hands
        // back the uncorrupted entity it is still holding and this test passes
        // against a chain that was never broken.
        $this->em->clear();

        $history = $this->history($id);
        self::assertCount(2, $history, 'Both rows are still listed');
        self::assertNull($history[0], 'The unreadable row reports no body rather than a wrong one');
        self::assertNull($history[1], 'and so does the row behind it, which was resolved against it');

        // And the note itself is untouched by any of this.
        $this->sessionRequest('GET', '/api/notes/'.$id);
        self::assertSame(200, $this->httpStatus());
        self::assertSame($current, $this->jsonResponse()['body_md']);
    }

    public function testForgettingHistoryStillDestroysEverything(): void
    {
        // The redaction path. Diffs must not have left a copy of the removed
        // text anywhere — this is the operation whose whole value is that
        // nothing survives it.
        $note = $this->kb->a->note('Runbook', $this->longBody([7 => 'AKIAIOSFODNN7EXAMPLE']));
        $id = (int) $note->getId();
        $this->loginAs($this->kb->a);
        $this->edit($id, $this->longBody([7 => '(removed)']));
        $this->assertStoredAsDiff($id);

        $this->sessionRequest('DELETE', '/api/notes/'.$id.'/revisions');
        self::assertSame(200, $this->httpStatus(), $this->body());

        self::assertSame([], $this->history($id));
        $this->in($this->kb->a);
        $leftovers = $this->em->getConnection()->fetchOne(
            "SELECT count(*) FROM note_revisions WHERE note_id = :n
             AND (coalesce(body_md,'') LIKE '%AKIAIOSFODNN7EXAMPLE%' OR coalesce(body_diff,'') LIKE '%AKIAIOSFODNN7EXAMPLE%')",
            ['n' => $id]
        );
        self::assertSame(0, (int) $leftovers, 'The redacted text must not survive in a patch either');
    }

    public function testTheServiceAndTheEndpointAgree(): void
    {
        // `bodyFor()` walks the chain on its own for a single revision, and
        // `bodiesFor()` walks it once for a list. Two code paths over the same
        // data is how they drift, so this pins them together.
        $note = $this->kb->a->note('Runbook', $this->longBody());
        $id = (int) $note->getId();
        $this->loginAs($this->kb->a);
        $this->edit($id, $this->longBody([5 => 'first edit']));
        $this->edit($id, $this->longBody([5 => 'first edit', 20 => 'second edit']));
        $this->assertStoredAsDiff($id, 0);
        $this->assertStoredAsDiff($id, 1);

        /** @var NoteRevisions $service */
        $service = static::getContainer()->get(NoteRevisions::class);
        $this->in($this->kb->a);
        $rows = $service->forNote($id);
        $bulk = $service->bodiesFor($id, $rows);

        foreach ($rows as $row) {
            self::assertSame(
                $bulk[(int) $row->getId()],
                $service->bodyFor($row),
                'bodyFor() and bodiesFor() must agree on revision '.$row->getId()
            );
        }
    }
}
