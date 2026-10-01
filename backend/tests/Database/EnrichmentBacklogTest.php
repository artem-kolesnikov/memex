<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;
use App\Service\EnrichmentBacklog;

/**
 * The backlog is about what is workable NOW, not about what a note lacks.
 *
 * `needs_enrichment` is the list a connected assistant is handed, and since
 * 2026-08-23 it is the only reader: enrichment fires on SAVE, so the only
 * notes left for a queue are the ones a save cannot reach — content that was
 * already there when enrichment was switched on, above all an imported vault,
 * which {@see \App\Controller\ImportController} creates without inline
 * enrichment on purpose.
 *
 * The property these tests exist for: **a held proposal is not a summary.** A
 * note whose description is already waiting on the operator still lacks
 * everything that put it in the queue, so without an exclusion the assistant
 * that just described it is handed it again on its next call and writes the
 * same summary twice. That was measured before it was fixed.
 */
final class EnrichmentBacklogTest extends ApiTestCase
{
    /** The backlog exactly as an assistant is handed it. */
    private function backlog(): array
    {
        $this->request('POST', '/mcp', $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'needs_enrichment', 'arguments' => ['limit' => 100]],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        return json_decode(
            $this->jsonResponse()['result']['content'][0]['text'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /** @return int[] */
    private function ids(array $backlog): array
    {
        return array_map(static fn (array $row) => $row['id'], $backlog['notes']);
    }

    private function undescribed(string $title): Note
    {
        $id = $this->undescribedFor($this->kb->a->agentBearer, $title);
        $this->in($this->kb->a);

        return $this->noteNumbered($id);
    }

    private function undescribedFor(string $bearer, string $title): int
    {
        $this->request('POST', '/api/notes', $bearer, [
            'title' => $title,
            'body_md' => 'A body nobody has described.',
        ]);
        self::assertSame(201, $this->httpStatus(), $this->body());

        return (int) $this->jsonResponse()['note']['id'];
    }

    /**
     * The agent half of the loop. Filing a description has to take the note out
     * of the queue, or the next call hands it straight back and the assistant
     * writes the same summary again.
     */
    public function testFilingADescriptionTakesTheNoteOutOfTheBacklog(): void
    {
        $note = $this->undescribed('Undescribed');
        self::assertContains($note->getId(), $this->ids($this->backlog()));

        $this->request('POST', '/mcp', $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'propose', 'arguments' => [
                'note_id' => $note->getId(),
                'summary' => 'A description written by the assistant.',
            ]],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $after = $this->backlog();
        self::assertNotContains($note->getId(), $this->ids($after));
        self::assertSame(0, $after['total']);
        self::assertSame(1, $after['awaiting_review'], 'and it says WHERE the note went');
    }

    /**
     * Nothing is subtracted silently. An assistant reading `total: 0` has to be
     * able to tell "there is nothing to describe" from "every one of them is
     * sitting in the operator's inbox", because the second is a thing to say to
     * the user and the first is not.
     */
    public function testAnEmptyBacklogSaysWhetherItIsDoneOrMerelyWaiting(): void
    {
        $done = $this->backlog();
        self::assertSame(0, $done['total']);
        self::assertSame(0, $done['awaiting_review']);

        $note = $this->undescribed('Undescribed');
        $this->request('POST', '/mcp', $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'propose', 'arguments' => [
                'note_id' => $note->getId(),
                'summary' => 'A description written by the assistant.',
            ]],
        ]);

        $waiting = $this->backlog();
        self::assertSame(0, $waiting['total'], 'Both answers are zero...');
        self::assertSame(1, $waiting['awaiting_review'], '...and only this tells them apart');
    }

    /**
     * A pass memex ran itself holds the note back from the ASSISTANT too. This
     * is the cross-worker half: without it the server describes a note
     * overnight and the assistant describes it again in the morning, and the
     * operator gets two proposals for one note from two authors.
     */
    public function testWorkMemexFiledItselfHoldsTheNoteBackFromAnAssistant(): void
    {
        $note = $this->undescribed('Undescribed');
        // What a pass leaves behind: a held proposal with no token, and the
        // note stamped as looked-at.
        $this->em->getConnection()->executeStatement(
            "INSERT INTO edit_proposals (note_id, proposed_by_token_id, proposed_summary, created_at, type, status)
             VALUES (:note, NULL, 'Described by the pass.', :now, 'edit', 'held')",
            [
                'note' => $note->getId(),
                'now' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            ],
        );

        $after = $this->backlog();

        self::assertNotContains($note->getId(), $this->ids($after));
        self::assertSame(1, $after['awaiting_review']);
    }

    /**
     * Team B's undescribed note is B's business, and B's held proposal holds
     * back B's note only.
     */
    public function testTheBacklogIsPerTeam(): void
    {
        // Two notes for B, one of each kind, so that EVERY part of the
        // predicate has something on B's side to reach across if it is not
        // scoped: one B note that wants describing and has no work filed, and
        // one whose description is already waiting.
        // `held` first, so `loose` takes a NUMBER team A does not hold — every
        // vault numbers from 1, so B's first note and A's first note are both 1
        // and a leak would be indistinguishable from a coincidence.
        $held = $this->undescribedFor($this->kb->b->agentBearer, 'B has filed one');
        $loose = $this->undescribedFor($this->kb->b->agentBearer, 'B has one too');
        $this->request('POST', '/mcp', $this->kb->b->agentBearer, [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'propose', 'arguments' => [
                'note_id' => $held,
                'summary' => 'Described by B, and no business of A.',
            ]],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $mine = $this->undescribed('Mine');
        $backlog = $this->backlog();

        self::assertSame([$mine->getId()], $this->ids($backlog), "A sees A's note and nothing else");
        self::assertNotContains($loose, $this->ids($backlog));
        self::assertSame(1, $backlog['total'], "B's loose note must not be counted into A's backlog");
        self::assertSame(0, $backlog['awaiting_review'], "nor B's held work into A's");
    }
}
