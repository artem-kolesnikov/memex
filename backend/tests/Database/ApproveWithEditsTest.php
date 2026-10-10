<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;

/**
 * Approving a held item with the operator's own text.
 *
 * The gate says an agent's write does not become the knowledge base until the
 * owner says so. It never said the owner's only two answers are yes and no —
 * "yes, but not like that" is the verdict a review inbox exists to make
 * possible, and until this file the only way to give it was to reject the note
 * and retype it.
 *
 * What is asserted here is the pair of properties that make the third answer
 * safe rather than convenient: the edit is a REAL save (a revision, the
 * operator's name on it, the version check the editor sends), and it is
 * VISIBLE afterwards, so a body the operator wrote is never read back as the
 * agent's work.
 */
final class ApproveWithEditsTest extends ApiTestCase
{
    public function testTagsOnlyAmendmentDoesNotBuySuggestionsForUnchangedBody(): void
    {
        $settings = self::getContainer()->get(\App\Service\EnrichmentSettings::class);
        $this->in($this->kb->a);
        $key = $settings->saveKey(\App\Service\AiProviders::OPENAI, 'OpenAI', 'sk-valid-key')['credential'];
        $settings->save(enabled: true, credential: $key);
        self::assertTrue($settings->forVault()->textEnabled, 'text must be on, or the assertions below are vacuous');
        $id = $this->pendingNote();
        $this->ml->calls = [];
        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/notes/'.$id.'/approve', [
            'body_md' => 'As the agent filed it.', 'tags' => ['owner-choice'],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame([], array_values(array_filter($this->ml->calls, static fn (array $call) => str_contains($call['url'], 'suggest-tags'))));
        self::assertSame([], array_values(array_filter($this->ml->calls, static fn (array $call) => str_contains($call['url'], 'create-embeddings'))));
    }

    public function testTitleOnlyAmendmentPreservesTheAssistantsSummaryAttribution(): void
    {
        $this->request('POST', '/api/notes', $this->kb->a->agentBearer, [
            'title' => 'Pending title', 'body_md' => 'Body', 'summary' => 'Assistant description',
        ]);
        self::assertSame(201, $this->httpStatus(), $this->body());
        $note = $this->jsonResponse()['note'];
        $params = ['id' => $note['id']];
        $query = 'SELECT summary, summary_by, enriched_at FROM notes WHERE id = :id';
        $this->in($this->kb->a);
        $before = $this->em->getConnection()->fetchAssociative($query, $params);
        self::assertNotNull($before['summary_by']);
        self::assertNotSame(Note::SUMMARY_BY_OPERATOR, $before['summary_by']);
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/notes/'.$note['id'].'/approve', [
            'title' => 'Corrected title', 'expected_version' => $note['version'],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $this->in($this->kb->a);
        self::assertSame($before, $this->em->getConnection()->fetchAssociative($query, $params));
    }

    private function pendingNote(string $title = 'Awaiting a verdict', string $body = 'As the agent filed it.'): int
    {
        $this->request('POST', '/api/notes', $this->kb->a->agentBearer, [
            'title' => $title,
            'body_md' => $body,
        ]);
        self::assertSame(201, $this->httpStatus(), $this->body());
        $id = (int) $this->jsonResponse()['note']['id'];
        self::assertSame(Note::STATUS_PENDING, $this->statusOf($id));

        return $id;
    }

    private function statusOf(int $id): string
    {
        $this->in($this->kb->a);

        return (string) $this->em->getConnection()->fetchOne('SELECT status FROM notes WHERE id = :id', ['id' => $id]);
    }

    /** @return array{title: string, body_md: string, last_actor: string, version: int} */
    private function noteRow(int $id): array
    {
        $this->in($this->kb->a);
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT title, body_md, last_actor, version FROM notes WHERE id = :id',
            ['id' => $id]
        );
        self::assertIsArray($row);

        return $row;
    }

    /** @return array<int, array<string, mixed>> */
    private function revisionsOf(int $id): array
    {
        $this->in($this->kb->a);

        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT title, body_md, replaced_by, operation, amended_by_operator FROM note_revisions
             WHERE note_id = :id ORDER BY id',
            ['id' => $id]
        );
    }

    /** Every verdict is a journal row now; one approved as filed must not claim the operator's text. */
    private function assertApprovedAsFiled(string $message = ''): void
    {
        $descriptions = $this->journalDescriptions();
        self::assertCount(1, $descriptions, $message);
        self::assertStringNotContainsString('own edits', $descriptions[0], $message);
        self::assertStringNotContainsString('own text', $descriptions[0], $message);
    }

    /** @return array<int, string> */
    private function journalDescriptions(): array
    {
        $this->in($this->kb->a);

        return $this->em->getConnection()->fetchFirstColumn(
            "SELECT description FROM curator_log WHERE action = 'approved' ORDER BY id"
        );
    }

    public function testApprovingWithAnEditedBodySavesTheOperatorsTextAndVerifiesTheNote(): void
    {
        $id = $this->pendingNote();

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/notes/'.$id.'/approve', [
            'title' => 'As the operator wants it',
            'body_md' => 'Rewritten before approving.',
        ]);

        self::assertSame(200, $this->httpStatus(), $this->body());
        $note = $this->noteRow($id);
        self::assertSame(Note::STATUS_VERIFIED, $this->statusOf($id));
        self::assertSame('As the operator wants it', $note['title']);
        self::assertSame('Rewritten before approving.', $note['body_md']);
    }

    public function testTheOperatorsEditIsCreditedToThemAndKeepsTheAgentsTextInTheHistory(): void
    {
        $id = $this->pendingNote();

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/notes/'.$id.'/approve', [
            'body_md' => 'Rewritten before approving.',
        ]);

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame('human', $this->noteRow($id)['last_actor'], 'The text now standing is the operator’s');
        $revisions = $this->revisionsOf($id);
        self::assertCount(1, $revisions, 'The approval writes exactly one revision');
        self::assertSame(
            'As the agent filed it.',
            $revisions[0]['body_md'],
            'What the agent actually sent has to survive the operator overwriting it'
        );
        self::assertSame('human', $revisions[0]['replaced_by']);
        self::assertSame('edit', $revisions[0]['operation']);
    }

    public function testApprovingWithNothingButAReasonWritesNoRevision(): void
    {
        // The verdict's own fields must not be read as an edit: a comment is
        // prose about the decision, not a change to the note.
        $id = $this->pendingNote();

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/notes/'.$id.'/approve', [
            'comment' => 'Good as filed.',
            'is_precedent' => true,
        ]);

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame(Note::STATUS_VERIFIED, $this->statusOf($id));
        self::assertSame('As the agent filed it.', $this->noteRow($id)['body_md']);
        self::assertSame([], $this->revisionsOf($id), 'Approving as filed changes nothing, so it replaces nothing');

        // And the journal must not claim otherwise. A row reading "with their
        // own edits" over a note the operator only commented on is a false
        // statement about who wrote the text — the exact thing the mark exists
        // to prevent, told backwards.
        $descriptions = $this->journalDescriptions();
        self::assertCount(1, $descriptions);
        self::assertStringNotContainsString('with their own edits', $descriptions[0]);
    }

    public function testTheJournalSaysTheOperatorEditedItEvenWhenNoReasonWasGiven(): void
    {
        // A silent approval is not worth a row. An amended one is: the note
        // holds text the agent never sent, and nothing else on the record says
        // whose it was.
        $id = $this->pendingNote();

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/notes/'.$id.'/approve', ['body_md' => 'Rewritten before approving.']);

        self::assertSame(200, $this->httpStatus(), $this->body());
        $descriptions = $this->journalDescriptions();
        self::assertCount(1, $descriptions);
        self::assertStringContainsString('with their own edits', $descriptions[0]);
    }

    public function testASilentApprovalAsFiledIsLoggedAsFiled(): void
    {
        $id = $this->pendingNote();

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/notes/'.$id.'/approve');

        self::assertSame(200, $this->httpStatus(), $this->body());
        $this->assertApprovedAsFiled();
    }

    public function testAnAgentTokenCannotApproveWithEditsEither(): void
    {
        // The obvious way to smuggle an unreviewed write past the gate: send
        // the edit as part of the verdict, from the connection that filed it.
        $id = $this->pendingNote();

        $this->request('POST', '/api/notes/'.$id.'/approve', $this->kb->a->agentBearer, [
            'body_md' => 'Approved by me, myself.',
        ]);

        self::assertGreaterThanOrEqual(400, $this->httpStatus());
        self::assertSame(Note::STATUS_PENDING, $this->statusOf($id));
        self::assertSame('As the agent filed it.', $this->noteRow($id)['body_md']);
    }

    public function testACuratorTokenCannotEither(): void
    {
        $id = $this->pendingNote();

        $this->request('POST', '/api/notes/'.$id.'/approve', $this->kb->a->curatorBearer, [
            'body_md' => 'Approved by me, myself.',
        ]);

        self::assertGreaterThanOrEqual(400, $this->httpStatus());
        self::assertSame(Note::STATUS_PENDING, $this->statusOf($id));
        self::assertSame('As the agent filed it.', $this->noteRow($id)['body_md']);
    }

    public function testAStaleVersionIsRefusedRatherThanOverwritten(): void
    {
        // The card was open while something else rewrote the note. Approving
        // from that card would apply the operator's text to a note they have
        // not read — the same hazard the editor's own save refuses.
        $id = $this->pendingNote();
        $stale = $this->noteRow($id)['version'];

        // Another browser session, because a token's full-body edit is itself
        // held for review — it would leave the note exactly where it was and
        // the test would pass on a build with no version check at all.
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$id, ['body_md' => 'Moved on since the card was opened.']);
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertNotSame((int) $stale, (int) $this->noteRow($id)['version'], 'The fixture must actually move the note on');

        $this->reviewedRequest('POST', '/api/notes/'.$id.'/approve', [
            'body_md' => 'Written against the version that is gone.',
            'expected_version' => (int) $stale,
        ]);

        self::assertSame(409, $this->httpStatus(), $this->body());
        self::assertSame('Moved on since the card was opened.', $this->noteRow($id)['body_md']);
        self::assertSame(Note::STATUS_PENDING, $this->statusOf($id), 'A refused verdict is a verdict still to be made');
    }

    public function testAnAlreadyVerifiedNoteCannotBeEditedThroughTheApprovalRoute(): void
    {
        // Otherwise this is an ordinary edit wearing an approval's clothes,
        // reachable on any note in the knowledge base.
        $note = $this->kb->a->note('Long since verified', 'Settled text.');
        $id = (int) $note->getId();

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/notes/'.$id.'/approve', ['body_md' => 'Quietly rewritten.']);

        self::assertSame(400, $this->httpStatus(), $this->body());
        self::assertSame('Settled text.', $this->noteRow($id)['body_md']);
    }

    public function testAnEmptyBodyIsRefused(): void
    {
        $id = $this->pendingNote();

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/notes/'.$id.'/approve', ['body_md' => '   ']);

        self::assertSame(400, $this->httpStatus(), $this->body());
        self::assertSame(Note::STATUS_PENDING, $this->statusOf($id));
        self::assertSame('As the agent filed it.', $this->noteRow($id)['body_md']);
    }

    public function testAnEmptyTitleIsRefused(): void
    {
        $id = $this->pendingNote();

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/notes/'.$id.'/approve', ['title' => '  ', 'body_md' => 'Fine.']);

        self::assertSame(400, $this->httpStatus(), $this->body());
        self::assertSame(Note::STATUS_PENDING, $this->statusOf($id));
    }

    public function testTagsSentWithTheApprovalReplaceTheOnesTheAgentChose(): void
    {
        $this->request('POST', '/api/notes', $this->kb->a->agentBearer, [
            'title' => 'Filed with the wrong tags',
            'body_md' => 'Body.',
            'tags' => ['misfiled'],
        ]);
        $id = (int) $this->jsonResponse()['note']['id'];

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/notes/'.$id.'/approve', [
            'body_md' => 'Body.',
            'tags' => ['filed-right'],
        ]);

        self::assertSame(200, $this->httpStatus(), $this->body());
        $this->in($this->kb->a);
        $tags = $this->em->getConnection()->fetchFirstColumn(
            'SELECT t.name FROM tags t JOIN note_tag nt ON nt.tag_id = t.id WHERE nt.note_id = :id ORDER BY t.name',
            ['id' => $id]
        );
        self::assertSame(['filed-right'], $tags);
    }

    public function testAnAmendedProposalMarksTheRevisionAndSaysSoInTheJournal(): void
    {
        // The other half of the same promise, on the path that already shipped:
        // approving is a gate rather than authorship, so the proposer keeps the
        // credit — which is exactly why the amendment needs a mark of its own.
        $note = $this->kb->a->note('Standing note', 'The sentence as it stands.');
        $id = (int) $note->getId();

        $this->request('PUT', '/api/notes/'.$id, $this->kb->a->agentBearer, [
            'body_md' => 'The sentence as the agent would have it.',
        ]);
        self::assertSame(202, $this->httpStatus(), $this->body());
        $proposalId = (int) $this->jsonResponse()['proposal']['id'];

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$proposalId.'/approve', [
            'body_md' => 'The sentence as the operator would have it.',
        ]);

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame('The sentence as the operator would have it.', $this->noteRow($id)['body_md']);

        $revisions = $this->revisionsOf($id);
        self::assertCount(1, $revisions);
        self::assertTrue((bool) $revisions[0]['amended_by_operator'], 'The history has to say the text applied was not the text filed');

        $descriptions = $this->journalDescriptions();
        self::assertCount(1, $descriptions, 'An amendment is worth a row even with nothing said');
        self::assertStringContainsString('applying their own text', $descriptions[0]);
    }

    public function testAProposalApprovedAsFiledIsNotMarkedAsAmended(): void
    {
        $note = $this->kb->a->note('Standing note', 'The sentence as it stands.');
        $id = (int) $note->getId();

        $this->request('PUT', '/api/notes/'.$id, $this->kb->a->agentBearer, [
            'body_md' => 'The sentence as the agent would have it.',
        ]);
        $proposalId = (int) $this->jsonResponse()['proposal']['id'];

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$proposalId.'/approve');

        self::assertSame(200, $this->httpStatus(), $this->body());
        $revisions = $this->revisionsOf($id);
        self::assertCount(1, $revisions);
        self::assertFalse((bool) $revisions[0]['amended_by_operator']);
        $this->assertApprovedAsFiled('A silent verdict is a row, and not an amendment');
    }
    /** @return array{0: int, 1: int} the note and the proposal held against it */
    private function heldEdit(string $body = 'The sentence as the agent would have it.'): array
    {
        $note = $this->kb->a->note('Standing note', 'The sentence as it stands.', ['as-filed'], 'The description as it stands.');
        $id = (int) $note->getId();
        $this->request('PUT', '/api/notes/'.$id, $this->kb->a->agentBearer, ['body_md' => $body]);
        self::assertSame(202, $this->httpStatus(), $this->body());

        return [$id, (int) $this->jsonResponse()['proposal']['id']];
    }

    /** @return string[] */
    private function tagsOf(int $id): array
    {
        $this->in($this->kb->a);

        return $this->em->getConnection()->fetchFirstColumn(
            'SELECT t.name FROM tags t JOIN note_tag nt ON nt.tag_id = t.id WHERE nt.note_id = :id ORDER BY t.name',
            ['id' => $id]
        );
    }

    public function testAProposalsTitleTagsAndDescriptionCanAllBeAmended(): void
    {
        // The gap this closes: the amend box took a body and nothing else, so
        // a proposal filing the right text under the wrong tags could only be
        // rejected and retyped.
        [$id, $proposalId] = $this->heldEdit();

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$proposalId.'/approve', [
            'title' => 'The title the operator wants',
            'tags' => ['as-the-operator-files-it'],
            'summary' => 'The description the operator wants.',
        ]);

        self::assertSame(200, $this->httpStatus(), $this->body());
        $note = $this->noteRow($id);
        self::assertSame('The title the operator wants', $note['title']);
        self::assertSame(['as-the-operator-files-it'], $this->tagsOf($id));
        self::assertSame(
            'The description the operator wants.',
            $this->em->getConnection()->fetchOne('SELECT summary FROM notes WHERE id = :id', ['id' => $id])
        );
        // The body was never amended, so what lands is what the agent filed.
        self::assertSame('The sentence as the agent would have it.', $note['body_md']);
    }

    public function testAmendingOneFieldLeavesTheRestAsFiled(): void
    {
        [$id, $proposalId] = $this->heldEdit();

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$proposalId.'/approve', ['summary' => 'Only this was rewritten.']);

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame('The sentence as the agent would have it.', $this->noteRow($id)['body_md']);
        self::assertSame(['as-filed'], $this->tagsOf($id), 'A field nobody touched must not be replaced by an empty one');
    }

    public function testADeleteCannotBeAmended(): void
    {
        // There is no proposed content on a delete to edit, and accepting one
        // would mean approving a deletion while appearing to change the note.
        $note = $this->kb->a->note('Proposed for deletion', 'Still here.');
        $id = (int) $note->getId();
        $this->request('DELETE', '/api/notes/'.$id, $this->kb->a->agentBearer, ['reason' => 'Superseded.']);
        self::assertSame(202, $this->httpStatus(), $this->body());
        $proposalId = (int) $this->jsonResponse()['proposal']['id'];

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$proposalId.'/approve', ['body_md' => 'Kept after all.']);

        self::assertSame(400, $this->httpStatus(), $this->body());
        self::assertSame('Still here.', $this->noteRow($id)['body_md'], 'A refused amendment must not have applied the delete');
    }

    public function testAReportCannotBeAmended(): void
    {
        $note = $this->kb->a->note('Reported as stale', 'The text somebody says is wrong.');
        $id = (int) $note->getId();
        $this->request('PUT', '/api/notes/'.$id, $this->kb->a->agentBearer, ['comment' => 'This is out of date and I cannot tell what replaces it.']);
        self::assertSame(202, $this->httpStatus(), $this->body());
        $proposalId = (int) $this->jsonResponse()['proposal']['id'];

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$proposalId.'/approve', ['body_md' => 'What I think it should say.']);

        self::assertSame(400, $this->httpStatus(), $this->body());
        self::assertSame('The text somebody says is wrong.', $this->noteRow($id)['body_md']);
    }

    public function testAMergeIsAmendedInTheKeepersBodyAndNowhereElse(): void
    {
        $absorb = $this->kb->a->note('The duplicate', 'What the duplicate says.');
        $keeper = $this->kb->a->note('The one that is kept', 'What the keeper says.');
        $keeperId = (int) $keeper->getId();

        $this->request('POST', '/api/notes/'.$absorb->getId().'/propose-merge', $this->kb->a->agentBearer, [
            'into_note_id' => $keeperId,
            'merged_body_md' => 'What the agent would have the keeper say.',
        ]);
        self::assertSame(202, $this->httpStatus(), $this->body());
        $proposalId = (int) $this->jsonResponse()['proposal']['id'];

        // A merge leaves the keeper's own title, tags and description alone, so
        // offering to edit them here would be offering a change that does not
        // happen.
        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$proposalId.'/approve', ['title' => 'A title the merge cannot set']);
        self::assertSame(400, $this->httpStatus(), $this->body());

        $this->reviewedRequest('POST', '/api/proposals/'.$proposalId.'/approve', ['body_md' => 'What the OPERATOR would have the keeper say.']);
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame('What the OPERATOR would have the keeper say.', $this->noteRow($keeperId)['body_md']);
        self::assertSame('The one that is kept', $this->noteRow($keeperId)['title']);
    }

    public function testAnAmendedTitleIsRefusedWhenItIsEmpty(): void
    {
        [$id, $proposalId] = $this->heldEdit();

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$proposalId.'/approve', ['title' => '   ']);

        self::assertSame(400, $this->httpStatus(), $this->body());
        self::assertSame('Standing note', $this->noteRow($id)['title']);
    }

    public function testAnEchoedProposalIsApprovedRatherThanRewritten(): void
    {
        // The client sends only the fields that MOVED, so difference detection
        // lived entirely in it: anything sending the proposed text back — a
        // skewed build, a script, a second client — was recorded as the
        // operator's own text, on the revision and in the journal both (Codex,
        // 2026-09-07).
        [$id, $proposalId] = $this->heldEdit();

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$proposalId.'/approve', [
            'body_md' => 'The sentence as the agent would have it.',
            'title' => 'Standing note',
            'tags' => ['as-filed'],
            'summary' => 'The description as it stands.',
        ]);

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame('The sentence as the agent would have it.', $this->noteRow($id)['body_md']);

        $revisions = $this->revisionsOf($id);
        self::assertCount(1, $revisions);
        self::assertFalse(
            (bool) $revisions[0]['amended_by_operator'],
            'Echoing the proposal back is an approval, not a rewrite'
        );
        $this->assertApprovedAsFiled('A verdict that changed nothing says it approved as filed');
    }

    public function testAnEchoedPatchIsApprovedRatherThanRewritten(): void
    {
        // A patch is stored unresolved, so the text the card SHOWED is the
        // patch applied to the note. That is what an echo sends back, and it
        // is what the comparison has to resolve against.
        $note = $this->kb->a->note('Anchored', "Alpha stands.\n\nBravo stands.");
        $id = (int) $note->getId();
        $this->request('PUT', '/api/notes/'.$id, $this->kb->a->agentBearer, [
            'patch' => [['find' => 'Alpha stands.', 'replace' => 'Alpha has moved.']],
        ]);
        self::assertSame(202, $this->httpStatus(), $this->body());
        $proposalId = (int) $this->jsonResponse()['proposal']['id'];

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$proposalId.'/approve', [
            'body_md' => "Alpha has moved.\n\nBravo stands.",
        ]);

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame("Alpha has moved.\n\nBravo stands.", $this->noteRow($id)['body_md']);
        self::assertFalse(
            (bool) $this->revisionsOf($id)[0]['amended_by_operator'],
            'The body a patch resolves to is what the operator was shown, so sending it back changes nothing'
        );
    }

    public function testApprovingAPendingNoteWithItsOwnFieldsIsNotAnAmendment(): void
    {
        // The pending-note route reads `amended` from whether a payload
        // ARRIVED, and the editor sends the whole note rather than the fields
        // that moved — so opening the pane and approving without typing put the
        // operator's name on the agent's text.
        $id = $this->pendingNote();

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/notes/'.$id.'/approve', [
            'title' => 'Awaiting a verdict',
            'body_md' => 'As the agent filed it.',
            'tags' => [],
        ]);

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame(Note::STATUS_VERIFIED, $this->statusOf($id));
        self::assertSame([], $this->revisionsOf($id), 'Approving a note as filed rewrites nothing, so it revises nothing');
        $this->assertApprovedAsFiled('and the journal says it was approved as filed');
    }

    /** @return array<string, array{0: string}> every spelling of "no description" */
    public static function blankSummaries(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'a newline' => ["\n"],
        ];
    }

    /** @dataProvider blankSummaries */
    public function testABlankSummaryOnANoteWithNoneIsNotAnAmendment(string $blank): void
    {
        // `'' !== null`, so an echo carrying a blank description for a note
        // that has none was a rewrite — and normalisation then wrote nothing,
        // so the journal claimed the operator applied their own text over a
        // change that never happened (Codex, 2026-09-07). Every spelling of
        // blank, because normaliseSummary trims before it decides.
        $note = $this->kb->a->note('Undescribed', 'The sentence as it stands.');
        $id = (int) $note->getId();
        self::assertNull($note->getSummary(), 'Fixture: this note has to start with no description');

        $this->request('PUT', '/api/notes/'.$id, $this->kb->a->agentBearer, [
            'body_md' => 'The sentence as the agent would have it.',
        ]);
        self::assertSame(202, $this->httpStatus(), $this->body());
        $proposalId = (int) $this->jsonResponse()['proposal']['id'];

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$proposalId.'/approve', [
            'body_md' => 'The sentence as the agent would have it.',
            'summary' => $blank,
        ]);

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertFalse(
            (bool) $this->revisionsOf($id)[0]['amended_by_operator'],
            'A blank description where there was none changes nothing, so it rewrites nothing'
        );
        $this->assertApprovedAsFiled();
    }
}
