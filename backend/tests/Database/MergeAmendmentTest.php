<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;
use App\Service\NoteWriter;

/**
 * Amending a merge before approving it.
 *
 * Approving a merge produces a new version of the note that SURVIVES — merged
 * tags, retargeted links, one fewer note beside it — and the operator may take
 * over any of it, including on a merge that proposed no new wording at all
 * (operator, 2026-09-07). Two properties make that safe rather than merely
 * possible, and both are asserted here.
 *
 * The first is that the card and the server agree about whose fields these
 * are. A merge's `note` is the note being destroyed; its tags and description
 * describe the corpse. Everything the operator edits belongs to the KEEPER, so
 * an amendment read off the wrong note would quietly overwrite the survivor's
 * description with the absorbed note's.
 *
 * The second is that unlocking is not amending. "Approved as amended" is a
 * claim about authorship, and it must be false unless a character actually
 * differs — otherwise every operator who opened the pane to read it would be
 * recorded as having rewritten what they only looked at.
 */
final class MergeAmendmentTest extends ApiTestCase
{
    /**
     * @param string[] $absorbTags what the absorbed note brings. The default
     *                             brings one tag the keeper does not have; pass
     *                             a subset to make the union a no-op, which is
     *                             how a change to ONE field can be isolated.
     * @return array{absorb: int, keeper: int, proposal: int}
     */
    private function heldMerge(?string $mergedBody = null, array $absorbTags = ['research', 'shared']): array
    {
        $absorb = $this->agentNote('The duplicate', 'What the duplicate says.', $absorbTags);
        $keeper = $this->agentNote('The note that is kept', 'What the keeper says.', ['decision', 'shared']);
        $this->verify($absorb);
        $this->verify($keeper);

        // Filed through the service: `propose_merge` is an MCP verb with no
        // REST route, so this is the same call the tool handler makes.
        $writer = static::getContainer()->get(NoteWriter::class);
        $proposal = $writer->proposeMerge(
            $this->findNumbered($absorb),
            $this->findNumbered($keeper),
            $this->kb->a->agentToken(),
            $mergedBody,
            'The same thing written twice.',
        );

        return ['absorb' => $absorb, 'keeper' => $keeper, 'proposal' => $proposal->getId()];
    }

    /** @param string[] $tags */
    private function agentNote(string $title, string $body, array $tags): int
    {
        $this->request('POST', '/api/notes', $this->kb->a->agentBearer, [
            'title' => $title,
            'body_md' => $body,
            'tags' => $tags,
        ]);
        self::assertSame(201, $this->httpStatus(), $this->body());

        return (int) $this->jsonResponse()['note']['id'];
    }

    /** An agent's note arrives pending; a merge is about notes that are real. */
    private function verify(int $id): void
    {
        $this->in($this->kb->a);
        $this->em->getConnection()->executeStatement(
            "UPDATE notes SET status = 'verified' WHERE id = :id",
            ['id' => $id]
        );
    }

    /** @return array{summary: ?string, summary_by: ?string} */
    private function noteRow(int $id): array
    {
        $this->in($this->kb->a);
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT summary, summary_by FROM notes WHERE id = :id',
            ['id' => $id]
        );
        self::assertIsArray($row);

        return $row;
    }

    /** @return string[] */
    private function tagsOf(int $id): array
    {
        $this->in($this->kb->a);

        return $this->em->getConnection()->fetchFirstColumn(
            'SELECT t.name FROM note_tag nt JOIN tags t ON t.id = nt.tag_id
             WHERE nt.note_id = :id ORDER BY t.name',
            ['id' => $id]
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function revisionsOf(int $id): array
    {
        $this->in($this->kb->a);

        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT operation, amended_by_operator, body_md, summary, tags FROM note_revisions WHERE note_id = :id ORDER BY id',
            ['id' => $id]
        );
    }

    /** @return string[] the tag names one revision says the note held */
    private static function revisionTags(array $revision): array
    {
        $tags = json_decode((string) $revision['tags'], true);
        self::assertIsArray($tags);
        sort($tags);

        return $tags;
    }

    public function testTheMergeDetailCarriesTheKeepersOwnFields(): void
    {
        // The card is titled by the note that survives and shows the document
        // it becomes, so the keeper's text, tags and description all have to
        // reach the browser. Without them the tag row would render the
        // ABSORBED note's tags as the result of the merge.
        $ids = $this->heldMerge();

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/proposals/'.$ids['proposal']);

        self::assertSame(200, $this->httpStatus(), $this->body());
        $keeper = $this->jsonResponse()['merge_into'];
        self::assertSame($ids['keeper'], $keeper['id']);
        self::assertSame('What the keeper says.', $keeper['body_md']);
        $tags = $keeper['tags'];
        sort($tags);
        self::assertSame(['decision', 'shared'], $tags);
        self::assertArrayHasKey('summary', $keeper, 'The keeper\'s description is a field of the document being reviewed');
    }

    public function testABodylessMergeAcceptsTheOperatorsOwnTextForTheKeeper(): void
    {
        // Every merge is amendable, including one that proposes no wording:
        // approving it still produces a new version of the surviving note.
        $ids = $this->heldMerge();

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$ids['proposal'].'/approve', [
            'body_md' => "What the keeper says.\n\nAnd what I added on approval.",
        ]);

        self::assertSame(200, $this->httpStatus(), $this->body());
        $this->in($this->kb->a);
        self::assertSame(
            "What the keeper says.\n\nAnd what I added on approval.",
            $this->em->getConnection()->fetchOne(
                'SELECT body_md FROM notes WHERE id = :id',
                ['id' => $ids['keeper']]
            )
        );
    }

    public function testAnAmendedMergeIsRecordedAsAmended(): void
    {
        // `mergeNotes()` never passed the mark to `update()`, so a merge the
        // operator rewrote was filed in the note's history as the proposer's
        // text. Wrong about authorship, and invisible: the body was right, the
        // credit was not.
        $ids = $this->heldMerge();

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$ids['proposal'].'/approve', [
            'body_md' => 'Text the operator wrote.',
        ]);

        self::assertSame(200, $this->httpStatus(), $this->body());
        $revisions = $this->revisionsOf($ids['keeper']);
        self::assertCount(1, $revisions);
        self::assertSame('merge', $revisions[0]['operation']);
        self::assertTrue((bool) $revisions[0]['amended_by_operator']);
    }

    public function testAMergeApprovedAsFiledIsNotRecordedAsAmended(): void
    {
        // The other half, and the one that matters more: opening the pane to
        // READ it must not be recorded as rewriting it. The client sends no
        // fields when nothing differs; this pins the server's answer when it
        // receives none.
        $ids = $this->heldMerge("What the keeper says.\n\nAnd what the duplicate said.");

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$ids['proposal'].'/approve');

        self::assertSame(200, $this->httpStatus(), $this->body());
        $revisions = $this->revisionsOf($ids['keeper']);
        self::assertCount(1, $revisions);
        self::assertFalse(
            (bool) $revisions[0]['amended_by_operator'],
            'A merge approved exactly as filed is the proposer\'s work, not the operator\'s'
        );
    }

    public function testAmendedTagsAndDescriptionLandOnTheKeeper(): void
    {
        $ids = $this->heldMerge();

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$ids['proposal'].'/approve', [
            'tags' => ['decision', 'shared', 'research', 'mine'],
            'summary' => 'The description the operator wrote.',
        ]);

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame(['decision', 'mine', 'research', 'shared'], $this->tagsOf($ids['keeper']));
        $keeper = $this->noteRow($ids['keeper']);
        self::assertSame('The description the operator wrote.', $keeper['summary']);
        self::assertSame('operator', $keeper['summary_by']);
    }

    public function testAmendingOnlyTheTagsStillWritesThemThoughNoBodyChanged(): void
    {
        // `mergeNotes()` reached `update()` only when a body was present, so a
        // tags-only or description-only amendment on a bodyless merge was
        // accepted by the route and then silently dropped.
        $ids = $this->heldMerge();

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$ids['proposal'].'/approve', [
            'tags' => ['decision', 'kept-by-hand'],
        ]);

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame(['decision', 'kept-by-hand'], $this->tagsOf($ids['keeper']));
    }

    public function testAMergeRefusesATitleAmendment(): void
    {
        // The card is headed by the keeper's own name, which the merge never
        // proposes to change — so a title here would be an edit nothing on
        // screen asked about.
        $ids = $this->heldMerge();

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$ids['proposal'].'/approve', [
            'title' => 'A title the merge never proposed',
        ]);

        self::assertSame(400, $this->httpStatus(), $this->body());
        $this->in($this->kb->a);
        self::assertNotNull(
            $this->em->getConnection()->fetchOne(
                "SELECT id FROM edit_proposals WHERE id = :id AND status = 'held'",
                ['id' => $ids['proposal']]
            ),
            'A refused amendment leaves the proposal held rather than half-applied'
        );
    }

    public function testAnUnamendedMergeDoesNotWriteTheAbsorbedNotesFieldsOntoTheKeeper(): void
    {
        // The proposal's `proposed_tags`/`proposed_summary` are null on a merge
        // an agent filed, and its `note` is the note being destroyed. Reading
        // either as the keeper's would replace the survivor's description with
        // the corpse's on every ordinary merge.
        $ids = $this->heldMerge();
        $this->em->getConnection()->executeStatement(
            "UPDATE notes SET summary = 'The keeper described itself.' WHERE id = :id",
            ['id' => $ids['keeper']]
        );
        $this->em->getConnection()->executeStatement(
            "UPDATE notes SET summary = 'The duplicate described itself.' WHERE id = :id",
            ['id' => $ids['absorb']]
        );

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$ids['proposal'].'/approve');

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame('The keeper described itself.', $this->noteRow($ids['keeper'])['summary']);
        self::assertSame(['decision', 'research', 'shared'], $this->tagsOf($ids['keeper']));
    }

    public function testAMergeApprovedWithTheTextItAlreadyProposedIsNotAnAmendment(): void
    {
        // The client sends only the fields that MOVED, so an approval carrying
        // fields is normally a rewrite. That made the client the only thing
        // telling the two apart: anything echoing the proposal back — a skewed
        // build, a script, a second client — was recorded as the operator's own
        // text (Codex, 2026-09-07). The server answers it now.
        $ids = $this->heldMerge();

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$ids['proposal'].'/approve', [
            // Exactly the document the card showed: a bodyless merge keeps the
            // keeper's own body, and the tag row shows the union.
            'body_md' => 'What the keeper says.',
            'tags' => ['decision', 'shared', 'research'],
        ]);

        self::assertSame(200, $this->httpStatus(), $this->body());
        $revisions = $this->revisionsOf($ids['keeper']);
        self::assertCount(1, $revisions);
        self::assertFalse(
            (bool) $revisions[0]['amended_by_operator'],
            'A payload that echoes the proposal is an approval, not a rewrite'
        );
    }

    public function testReorderingTheTagsIsNotAnAmendment(): void
    {
        // Removing a tag and adding it back sends a full replacement list in a
        // different order and nothing else. Compared by position, that was a
        // rewrite; compared as a set, it is what it looks like.
        $ids = $this->heldMerge();

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$ids['proposal'].'/approve', [
            'tags' => ['shared', 'research', 'decision'],
        ]);

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame(['decision', 'research', 'shared'], $this->tagsOf($ids['keeper']));
        foreach ($this->revisionsOf($ids['keeper']) as $revision) {
            self::assertFalse(
                (bool) $revision['amended_by_operator'],
                'Re-ordering the same tags is not the operator rewriting the merge'
            );
        }
    }

    public function testADescriptionOnlyAmendmentOnABodylessMergeIsRecorded(): void
    {
        // `NoteRevisions::record` compared title, body and tags and ignored the
        // summary, so this write produced no revision — and the amend mark,
        // which lives on the revision, had nowhere to land (Codex, 2026-09-07).
        //
        // The absorbed note brings no tag the keeper lacks, so the description
        // is the ONLY thing that changes. Without that the tag union writes the
        // revision on its own and this passes without the fix.
        $ids = $this->heldMerge(absorbTags: ['shared']);

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$ids['proposal'].'/approve', [
            'summary' => 'The description the operator wrote.',
        ]);

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame('The description the operator wrote.', $this->noteRow($ids['keeper'])['summary']);
        $revisions = $this->revisionsOf($ids['keeper']);
        self::assertCount(1, $revisions, 'A description the operator rewrote is a change, and history has to hold it');
        self::assertTrue((bool) $revisions[0]['amended_by_operator']);
    }

    public function testTheMergeRevisionHoldsTheTagsTheKeeperActuallyHad(): void
    {
        // `mergeNotes` unioned the tags onto the keeper and flushed BEFORE
        // update() took its snapshot, so the row recording what the keeper was
        // before the merge already carried the absorbed note's tag — history
        // claiming a state that never existed (Codex, 2026-09-07).
        $ids = $this->heldMerge("What the keeper says.\n\nAnd what the duplicate said.");

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$ids['proposal'].'/approve');

        self::assertSame(200, $this->httpStatus(), $this->body());
        $revisions = $this->revisionsOf($ids['keeper']);
        self::assertCount(1, $revisions);
        self::assertSame(
            ['decision', 'shared'],
            self::revisionTags($revisions[0]),
            'The revision is what the keeper was BEFORE the merge, so it cannot hold a tag the merge brought'
        );
        self::assertSame(['decision', 'research', 'shared'], $this->tagsOf($ids['keeper']));
    }
}
