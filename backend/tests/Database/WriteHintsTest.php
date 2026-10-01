<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;
use App\Service\TagAdmin;
use App\Tests\Support\Embeddings;
use App\Tests\Support\MockMlResponder;

/**
 * Write-time prevention — the first step of the curation redevelopment: when a note is written, say what the server can already
 * see about it.
 *
 * The property under test is not "a hint appears". It is that each of the
 * three hints is **actionable, free and correctly bounded**:
 *
 *  - a near-duplicate is named, a distant neighbour is not, and neither ever
 *    crosses a vault;
 *  - a link candidate carries a patch that `propose` really applies, which is
 *    the difference between a suggestion and a decoration;
 *  - a tag candidate comes from the vault's own vocabulary and never from a
 *    word memex invented or the owner retired;
 *  - and the whole thing costs zero provider calls beyond the embedding the
 *    save was buying anyway.
 *
 * That last one is why this test counts calls rather than trusting the
 * docblock. A hint that quietly bought an embedding would be a per-write
 * charge on the operator's account for every connected assistant on the box,
 * and it would be invisible — the feature would work perfectly.
 */
final class WriteHintsTest extends ApiTestCase
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

        return json_decode($result['content'][0]['text'], true, 16, JSON_THROW_ON_ERROR);
    }

    /**
     * Content-addressed vectors, so distance in these tests is a fact about
     * the fixture rather than a number that happened to work. Anything whose
     * embedded text contains `far` sits 0.30 away — comfortably past either
     * model's cutoff — and everything else sits on top of everything else.
     */
    private function spreadVectors(): void
    {
        $this->ml->vectorFor = static fn (string $content): array => str_contains($content, 'far')
            ? MockMlResponder::spread(0.30)
            : MockMlResponder::spread(0.0);
    }

    // ---- duplicates -------------------------------------------------------

    public function testWritingANoteTheVaultAlreadyHasNamesTheNoteItAlreadyHas(): void
    {
        $existing = $this->kb->a->note('Disaster recovery runbook', 'How to restore the box.');

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'title' => 'Disaster recovery — runbook',
            'body_md' => 'How to restore the box, written again by somebody who did not look.',
        ]);

        self::assertArrayHasKey('hints', $result, 'A second copy of an existing note was written with no warning');
        self::assertSame(
            [(int) $existing->getId()],
            array_column($result['hints']['duplicates'], 'note_id')
        );
        self::assertSame(
            'likely the same note — read both before saving a second one',
            $result['hints']['duplicates'][0]['reading'],
            'A distance of 0 has to be reported as a probable twin, not as a neighbour'
        );
    }

    /**
     * The cutoff does something. Without a real spread of vectors every pair
     * in the suite sits at distance 0, and any threshold at all would pass —
     * which is exactly the vacuous test this project has been bitten by twice.
     */
    public function testANoteAboutSomethingElseIsNotReportedAsADuplicate(): void
    {
        $this->spreadVectors();
        $this->kb->a->note('A note about something far away', 'far');

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'title' => 'A note about what is here',
            'body_md' => 'Nothing to do with the other one.',
        ]);

        self::assertArrayNotHasKey(
            'duplicates',
            $result['hints'] ?? [],
            'A neighbour past the distance cutoff was offered as a possible duplicate'
        );
    }

    /** The band is reported in words, and the two bands are different words. */
    public function testANearbyButDistinctNoteIsOfferedAsALinkRatherThanAMerge(): void
    {
        // The marker is in the OLD note only — the embedded text is title plus
        // body, so a word the new note also uses would put both vectors at the
        // same angle and the pair back at distance 0.
        $model = Embeddings::model(self::getContainer());
        $between = ($model->duplicateDistance() + $model->hintDistance()) / 2;
        $this->ml->vectorFor = static fn (string $content): array => str_contains($content, 'zzmarker')
            ? MockMlResponder::spread($between)
            : MockMlResponder::spread(0.0);
        $this->kb->a->note('An adjacent subject', 'zzmarker');

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'title' => 'The new note',
            'body_md' => 'Related without being the same thing.',
        ]);

        self::assertSame(
            'related but probably distinct — a link is usually right, a merge usually is not',
            $result['hints']['duplicates'][0]['reading']
        );
    }

    /**
     * Every note here embeds to the same vector, so if B's write were compared
     * against A's vault, A's note would be the top match for anything B wrote
     * — and B would be shown its title and its summary.
     */
    public function testTheDuplicateHintNeverReachesAnotherTeamsNotes(): void
    {
        $this->kb->a->note('Alpha private note', 'A body only team A has.', [], 'A summary only team A has.');
        $this->kb->b->note('Bravo own note', 'A body team B has.');

        $result = $this->callTool('propose', $this->kb->b->agentBearer, [
            'title' => 'Bravo second note',
            'body_md' => 'Another body team B has.',
        ]);

        self::assertSame(['Bravo own note'], array_column($result['hints']['duplicates'], 'title'));
        self::assertStringNotContainsString('Alpha', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** Five shown out of six says so; five out of five does not need to. */
    public function testATruncatedNeighbourListSaysHowManyThereWere(): void
    {
        foreach (range(1, 6) as $i) {
            $this->kb->a->note("Twin $i", 'The same body six times.');
        }

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'title' => 'Twin seven',
            'body_md' => 'The same body a seventh time.',
        ]);

        self::assertCount(5, $result['hints']['duplicates']);
        self::assertSame(6, $result['hints']['duplicates_total']);
    }

    // ---- links ------------------------------------------------------------

    public function testANoteThatNamesAnotherNoteIsToldSo(): void
    {
        $target = $this->kb->a->note('Hermes runtime profile', 'The profile.');

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'title' => 'Deployment notes',
            'body_md' => 'The box follows the Hermes runtime profile when it boots.',
        ]);

        self::assertSame(
            [(int) $target->getId()],
            array_column($result['hints']['links'], 'note_id')
        );
    }

    /**
     * The strongest of these tests, and the one that separates a hint from a
     * decoration: the patch that comes back is sent straight to `propose` and
     * has to be an edit the server accepts and the approval really applies.
     *
     * `WikiLinkSuggest` builds a `find` that must occur EXACTLY ONCE or the
     * patch is refused, so this also pins the anchoring rule end to end.
     */
    public function testTheLinkHintCarriesAPatchProposeCanApply(): void
    {
        $this->kb->a->note('Hermes runtime profile', 'The profile.');
        $subject = $this->kb->a->note(
            'Deployment notes',
            'The box follows the Hermes runtime profile when it boots.'
        );

        $written = $this->callTool('propose', $this->kb->a->agentBearer, [
            'title' => 'Deployment notes second copy',
            'body_md' => 'The box follows the Hermes runtime profile when it boots.',
        ]);
        $patch = array_map(
            static fn (array $l): array => ['find' => $l['find'], 'replace' => $l['replace']],
            $written['hints']['links']
        );
        self::assertNotSame([], $patch);

        // Sent back verbatim, against the note it describes, by a curator
        // token so it applies rather than waiting in the inbox.
        $this->callTool('propose', $this->kb->a->curatorBearer, [
            'note_id' => $subject->getId(),
            'patch' => $patch,
        ]);

        $this->in($this->kb->a);
        self::assertStringContainsString(
            '[[Hermes runtime profile]]',
            (string) $this->em->getConnection()->fetchOne(
                'SELECT body_md FROM notes WHERE id = ?',
                [$subject->getId()]
            ),
            'The patch the hint offered was not one the server would accept'
        );
    }

    public function testANoteThatNamesNothingGetsNoLinkHint(): void
    {
        $this->kb->a->note('Hermes runtime profile', 'The profile.');

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'title' => 'Unrelated',
            'body_md' => 'This body mentions no other note at all.',
        ]);

        self::assertArrayNotHasKey('links', $result['hints'] ?? []);
    }

    // ---- tags -------------------------------------------------------------

    public function testAWordAlreadyInTheVocabularyIsOfferedAsAFiling(): void
    {
        $this->kb->a->note('An existing note', 'Body.', ['infrastructure']);

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'title' => 'A new note',
            'body_md' => 'This note is about infrastructure and nothing else.',
        ]);

        self::assertSame(['infrastructure'], $result['hints']['tags']);
    }

    /**
     * Never a name memex made up. The rule the operator set for on-save
     * enrichment on 2026-08-24 — vocabulary is applied, invented names are
     * only offered — reaches the free path as: invented names do not exist
     * here at all, because nothing here can invent one.
     */
    public function testOnlyNamesTheTeamAlreadyUsesCanBeOffered(): void
    {
        $this->kb->a->note('An existing note', 'Body.', ['infrastructure']);

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'title' => 'A new note',
            'body_md' => 'This note is about infrastructure, kubernetes and observability.',
        ]);

        self::assertSame(['infrastructure'], $result['hints']['tags']);
    }

    /**
     * Retiring a tag deletes its `tags` row, so the vocabulary this reads is
     * already the filter — there is no separate guard, deliberately (see
     * `WriteHints::tagCandidates()`). This test pins the user-visible property
     * rather than the mechanism, so it keeps meaning something if the query
     * ever starts reading from somewhere else.
     */
    public function testARetiredNameIsNeverSuggestedBack(): void
    {
        $note = $this->kb->a->note('An existing note', 'Body.', ['infrastructure']);
        $tag = $note->getTags()->first();
        static::getContainer()->get(TagAdmin::class)->remove($tag, $this->kb->a->account()->getName());

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'title' => 'A new note',
            'body_md' => 'This note is about infrastructure and nothing else.',
        ]);

        self::assertArrayNotHasKey(
            'tags',
            $result['hints'] ?? [],
            'A word the team deliberately retired was offered back to them'
        );
    }

    public function testATagTheNoteAlreadyCarriesIsNotOfferedAgain(): void
    {
        $this->kb->a->note('An existing note', 'Body.', ['infrastructure']);

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'title' => 'A new note',
            'body_md' => 'This note is about infrastructure and nothing else.',
            'tags' => ['infrastructure'],
        ]);

        self::assertArrayNotHasKey('tags', $result['hints'] ?? []);
    }

    /** A tag is a word in the text, not a substring of one. */
    public function testAVocabularyWordInsideALongerWordDoesNotCount(): void
    {
        $this->kb->a->note('An existing note', 'Body.', ['note']);

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'title' => 'Denoted',
            'body_md' => 'Everything here is denoted and noteworthy.',
        ]);

        self::assertArrayNotHasKey('tags', $result['hints'] ?? []);
    }

    // ---- the cost ---------------------------------------------------------

    /**
     * The invariant the whole design rests on: hints add nothing to the bill.
     *
     * A save embeds the note once, and that call is the save's, not the
     * hint's. If the duplicate check ever starts buying its own embedding —
     * by calling `embedQuery` instead of reading `note_embeddings` — this
     * count goes to two and the feature still works perfectly, which is why
     * it has to be asserted rather than reasoned about.
     */
    public function testHintsAddNoProviderCallsToAWrite(): void
    {
        $this->kb->a->note('Hermes runtime profile', 'The profile.', ['infrastructure']);
        $before = $this->ml->callCount();

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'title' => 'Deployment notes',
            'body_md' => 'The box follows the Hermes runtime profile. This is about infrastructure.',
        ]);

        // All three hints fired, so this is not a count of nothing happening.
        self::assertArrayHasKey('duplicates', $result['hints']);
        self::assertArrayHasKey('links', $result['hints']);
        self::assertArrayHasKey('tags', $result['hints']);

        self::assertSame(
            1,
            $this->ml->callCount() - $before,
            'A write bought more than the one embedding it always buys'
        );
        self::assertSame(1, $this->ml->callCount('/create-embeddings') - $before);
    }

    /**
     * Nothing to say means no key at all, not three empty arrays. Every MCP
     * write on the box carries this shape, so an always-present empty
     * structure is a permanent tax for the common case.
     */
    public function testAWriteWithNothingToReportCarriesNoHintsAtAll(): void
    {
        $this->spreadVectors();

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'title' => 'The first note in an empty knowledge base',
            'body_md' => 'far — nothing exists to duplicate, name or file it under.',
        ]);

        self::assertArrayNotHasKey('hints', $result);
        self::assertArrayNotHasKey('hints_note', $result);
    }

    /**
     * A note with no vector — an import created it with `enrich: null`, or the
     * embedding failed — still gets the two free hints. The duplicate section
     * is the only one that depends on the vector, and its absence must not take
     * the others with it.
     */
    public function testTheFreeHintsSurviveAnEmbeddingOutage(): void
    {
        $this->kb->a->note('Hermes runtime profile', 'The profile.', ['infrastructure']);
        $this->ml->failWith['/create-embeddings'] = 'throw';

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'title' => 'Deployment notes',
            'body_md' => 'The box follows the Hermes runtime profile. This is about infrastructure.',
        ]);

        self::assertArrayNotHasKey('duplicates', $result['hints']);
        self::assertArrayHasKey('links', $result['hints']);
        self::assertArrayHasKey('tags', $result['hints']);
    }

    /** The gate is untouched: a hint is a reading, and readings do not write. */
    public function testHintsChangeNothingAboutTheNote(): void
    {
        $this->kb->a->note('Hermes runtime profile', 'The profile.', ['infrastructure']);

        $result = $this->callTool('propose', $this->kb->a->agentBearer, [
            'title' => 'Deployment notes',
            'body_md' => 'The box follows the Hermes runtime profile. This is about infrastructure.',
        ]);

        $id = $result['note']['id'];
        self::assertSame(Note::STATUS_PENDING, $result['note']['status']);
        $this->in($this->kb->a);
        self::assertSame(
            'The box follows the Hermes runtime profile. This is about infrastructure.',
            (string) $this->em->getConnection()->fetchOne(
                'SELECT body_md FROM notes WHERE id = ?',
                [$id]
            ),
            'A link hint wrote itself into the body'
        );
        self::assertSame(
            0,
            (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM note_tag WHERE note_id = ?', [$id]),
            'A tag hint filed the note by itself'
        );
    }
}
