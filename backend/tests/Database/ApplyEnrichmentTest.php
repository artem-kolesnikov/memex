<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\EditProposal;
use App\Service\AiProviders;
use App\Service\EnrichmentSettings;
use App\Service\NoteWriter;
use App\Service\ReviewVerdicts;
use App\Tests\Support\KbFixture;

/**
 * Where the provider calls happen relative to the transaction (audit A-3b),
 * and how many of them are bought (M-9, M-11).
 *
 * The defect was not visible in any result: every apply path enriched inline,
 * so a hung ml-processor left the session idle-in-transaction holding row locks
 * on the operator's notes for up to a minute per operation, while the notes
 * themselves came out perfectly correct. Every
 * transaction is BEGIN IMMEDIATE now, so the lock held would be the whole
 * vault's.
 *
 * **What makes it testable is `MockMlResponder::$calls[]['tx']`**, the
 * transaction nesting level at the moment of each outbound call. Nothing wraps
 * a test in a transaction, so **level 0 means no transaction of the app's own
 * is open** and anything higher means the vault was locked. Without
 * that recording there is nothing to assert: the observable output is identical
 * either way, which is exactly why this survived a green suite.
 */
final class ApplyEnrichmentTest extends DatabaseTestCase
{
    private const VERDICT = ['comment' => null, 'precedent' => false];

    private const NO_APP_TRANSACTION = 0;

    private NoteWriter $writer;
    private ReviewVerdicts $verdicts;
    private KbFixture $kb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = self::getContainer()->get(NoteWriter::class);
        $this->verdicts = self::getContainer()->get(ReviewVerdicts::class);
        $this->kb = new KbFixture(self::getContainer());

        // **Server-side text is switched ON for this fixture, and that is
        // load-bearing rather than incidental.** `MlClient`'s text methods
        // refuse outright when a vault has configured no provider, so on a
        // default fixture no summarize or suggest-tags request is ever made —
        // and "assert no tag suggestions were bought" would then pass against
        // code that buys them enthusiastically. It did, when this file was
        // first written: the M-9 mutation survived, because the number being
        // asserted was already zero for an unrelated reason. Embeddings are
        // unaffected either way; they are the operator's, for every team.
        $settings = self::getContainer()->get(EnrichmentSettings::class);
        $key = $settings->saveKey(AiProviders::OPENAI, 'OpenAI', 'sk-valid-key')['credential'];
        $settings->save(enabled: true, credential: $key);
    }

    /** Approving one held edit: the simplest path, and it holds a lock too. */
    public function testApprovingAnEditLeavesTheTransactionBeforeSpending(): void
    {
        $note = $this->kb->note($this->writer, 'A note', 'The original body.', []);
        $proposal = $this->writer->propose(
            $note,
            $this->kb->agentToken,
            'A better title',
            'A rewritten body, long enough to be worth describing.',
            null,
        );

        $this->forgetCalls();
        $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));

        self::assertNotSame([], $this->mlCalls(), 'Nothing was enriched at all — the test proves nothing');
        $this->assertNothingSpentInsideATransaction();
    }

    public function testApprovingAMergeEnrichesTheKeeperAfterCommit(): void
    {
        $source = $this->kb->note($this->writer, 'Source', 'Source body.');
        $keeper = $this->kb->note($this->writer, 'Keeper', 'Keeper body.');
        $proposal = $this->writer->proposeMerge(
            $source, $keeper, $this->kb->agentToken,
            'The combined replacement body.', 'Combine these.',
        );
        $this->forgetCalls();

        $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));

        self::assertSame('The combined replacement body.', $keeper->getBodyMd());
        self::assertCount(1, $this->callsTo('create-embeddings'));
        $this->assertNothingSpentInsideATransaction();
    }

    /**
     * The curator's auto-apply path has no verdict transaction above it. Same
     * property, different owner — and the half a fix aimed only at the review
     * inbox would have missed.
     */
    public function testACuratorsOwnEditLeavesTheTransactionBeforeSpendingToo(): void
    {
        $note = $this->kb->note($this->writer, 'A curated note', 'Body.', []);

        $this->forgetCalls();
        // Anchored, because since C-9 a curator's WHOLE-body edit is held for
        // review rather than applied — and what this test is about is the
        // applying path.
        $this->writer->propose(
            $note,
            $this->kb->curatorToken,
            null,
            null,
            null,
            patch: [['find' => 'Body.', 'replace' => 'A rewritten body.']],
        );

        self::assertNotSame([], $this->mlCalls(), 'Nothing was enriched at all — the test proves nothing');
        $this->assertNothingSpentInsideATransaction();
    }

    /**
     * Deferred is not skipped, and this is the assertion the three above lean
     * on: without it they would all pass on a system that enriches nothing,
     * which is the shape a careless "fix" would have.
     *
     * The embedding is what an approval buys. The description stays the
     * assistant's to write, even with setUp()'s provider switched on.
     */
    public function testADeferredNoteIsStillEnrichedWithItsNewText(): void
    {
        $note = $this->kb->note($this->writer, 'A note', 'The original body.', []);
        // Undescribed before the approval, or the summary assertion below is
        // satisfied by the description this note was born with and proves
        // nothing about the drain.
        $note->setSummary(null, null);
        $this->em->flush();

        $proposal = $this->writer->propose(
            $note,
            $this->kb->agentToken,
            null,
            'A rewritten body that nothing has ever embedded.',
            null,
        );

        $this->forgetCalls();
        $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));

        $embedded = array_filter(
            $this->callsTo('create-embeddings'),
            static fn (array $call) => str_contains(
                json_encode($call['body'], JSON_THROW_ON_ERROR),
                'A rewritten body that nothing has ever embedded'
            )
        );
        self::assertNotSame([], $embedded, 'The approved text was never embedded — the deferral dropped the work');

        $this->em->refresh($note);
        self::assertNull($note->getSummary(), 'memex described an assistant\'s approved edit');
        self::assertSame([], $this->callsTo('summarize'));
    }

    /**
     * M-9 as it now stands. The original finding was that an approval bought
     * tag suggestions on every edit and threw them away, on the reasoning that
     * nothing on an apply path has anywhere to show them.
     *
     * Since 2026-08-23 tags are APPLIED rather than shown, so that reasoning
     * has gone and the rule it produced has not: tags are read out of the TEXT,
     * and this proposal is a retitle. Approving an edit that leaves the body
     * alone has nothing new to ask a model about, and an approval is common
     * enough that asking anyway would buy the same answer for as long as the
     * note existed. What changed is WHY the call is not made, which is worth
     * more than the assertion.
     */
    public function testApprovingAnEditBuysNoTagSuggestions(): void
    {
        $note = $this->kb->note($this->writer, 'A note', 'Body.', ['existing-tag']);
        $proposal = $this->writer->propose($note, $this->kb->agentToken, 'Retitled', null, null);

        $this->forgetCalls();
        $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));

        self::assertSame(
            [],
            $this->callsTo('suggest-tags'),
            'An approval bought tag suggestions that nothing shows to anybody'
        );
    }

    /**
     * M-11: `storeEmbedding()` compares a sha256 to decide whether the text
     * changed, and the comparison could never be true. `embedded_text_hash` is
     * `bytea`, PDO_PGSQL returns a bytea column as a STREAM RESOURCE, and the
     * guard asked `is_string()` — so every save, approval and sweep pass bought
     * an embedding for text that had not changed. On the operator's key, for
     * every team, invisibly, because a redundant embedding gives a correct
     * answer.
     */
    public function testTextThatDidNotChangeIsNotEmbeddedTwice(): void
    {
        $note = $this->kb->note($this->writer, 'A note', 'A body worth embedding.', []);

        $this->forgetCalls();
        // An edit that changes neither the title nor the body: a tag-only
        // change, which is the commonest thing a curator does.
        $this->writer->update($note, null, null, ['a-new-tag'], \App\Service\EmbeddingSpend::Metered);

        self::assertSame([], $this->callsTo('create-embeddings'), 'Unchanged text was embedded again');
    }

    /** And the other half: text that DID change must still be re-embedded. */
    public function testChangedTextIsEmbeddedAgain(): void
    {
        $note = $this->kb->note($this->writer, 'A note', 'A body worth embedding.', []);

        $this->forgetCalls();
        $this->writer->update($note, null, 'A completely different body.', null, \App\Service\EmbeddingSpend::Metered);

        self::assertCount(1, $this->callsTo('create-embeddings'));
    }

    /**
     * The other half of M-11, and the one a careless fix CREATES.
     *
     * `app:embed` selects notes where `embedded_at < updated_at`, and many
     * edits bump `updated_at` without changing a word of the embeddable text —
     * a tag change, an approval that rewrote only the summary. Make the hash
     * comparison work and stop there, and every one of those sits at the head
     * of the 500-note sweep forever, reporting SUCCESS while the backfill it
     * exists for never runs. Before the comparison was fixed they left the
     * queue by being re-embedded, which is why nobody saw this: the operator
     * was paying for the drain.
     */
    public function testTheSweepQueueDrainsWithoutBuyingAnything(): void
    {
        $note = $this->kb->note($this->writer, 'A note', 'A body worth embedding.', []);
        // A row whose embedding predates the note's last change while the text
        // itself is identical. Written directly, because the whole point is
        // that this state arrives through paths that do NOT embed — an import
        // backfill, a migration, a summary rewritten in place — and every row
        // written before today's fix is in it.
        $this->em->getConnection()->executeStatement(
            'UPDATE note_embeddings SET embedded_at = :before WHERE note_id = :id',
            ['before' => (new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), 'id' => $note->getId()]
        );
        self::assertSame([$note->getId()], $this->sweepQueue(), 'Fixture is wrong: the note should be due a look');

        $this->forgetCalls();
        self::assertTrue(self::getContainer()->get(\App\Service\NoteEnricher::class)->storeEmbedding($note, \App\Service\EmbeddingSpend::Metered));

        self::assertSame([], $this->callsTo('create-embeddings'), 'Unchanged text was embedded again');
        self::assertSame([], $this->sweepQueue(), 'The note is still queued — the sweep will offer it forever');
    }

    /** Exactly what `app:embed` asks for: notes with no embedding or a stale one. */
    private function sweepQueue(): array
    {
        return array_map('intval', $this->em->getConnection()->fetchFirstColumn(
            'SELECT n.id FROM notes n
             LEFT JOIN note_embeddings ne ON ne.note_id = n.id
             WHERE '.\App\Command\EmbedCommand::STALE_SQL.'
             ORDER BY n.id ASC'
        ));
    }

    /** Every outbound provider call must have happened with no lock in hand. */
    private function assertNothingSpentInsideATransaction(): void
    {
        $inside = array_values(array_filter(
            $this->mlCalls(),
            static fn (array $call) => $call['tx'] > self::NO_APP_TRANSACTION
        ));

        self::assertSame([], array_map(
            static fn (array $call) => $call['url'].' (depth '.$call['tx'].')',
            $inside
        ), 'These provider calls were made with a transaction open, holding the vault\'s write lock');
    }

    /** @return array<int, array{method: string, url: string, body: array<string, mixed>, tx: int}> */
    private function mlCalls(): array
    {
        return array_values(array_filter(
            $this->ml->calls,
            static fn (array $call) => str_contains($call['url'], '/api/v1/')
        ));
    }

    /** @return array<int, array<string, mixed>> */
    private function callsTo(string $endpoint): array
    {
        return array_values(array_filter(
            $this->ml->calls,
            static fn (array $call) => str_contains($call['url'], $endpoint)
        ));
    }

    /** Only what happens from here matters — fixture writes enrich too. */
    private function forgetCalls(): void
    {
        $this->ml->calls = [];
    }
}
