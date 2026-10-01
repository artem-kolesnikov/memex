<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\ProviderSpend;
use App\Entity\AiCredential;
use App\Service\SpendLedger;
use App\Tests\Support\KbFixture;

/**
 * The ledger: what a provider was actually paid for.
 *
 * The properties worth asserting here are all ones the product got WRONG in a
 * cheaper form before this table existed, which is why each has a test rather
 * than a comment.
 *
 * `note_embeddings.token_est` is the cautionary tale behind most of them: it is
 * `ceil(characters / 4)` of the input text, it has no output counterpart, it is
 * never null, and it was rendered on the Admin pane as the embedding bill for
 * weeks. Every failure mode below is a way of producing another one of those.
 */
final class SpendLedgerTest extends DatabaseTestCase
{
    private KbFixture $kb;
    private SpendLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kb = new KbFixture(self::getContainer());
        $this->ledger = self::getContainer()->get(SpendLedger::class);
    }

    /** @return array<string, mixed>[] */
    private function rows(): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT * FROM provider_spend ORDER BY id'
        );
    }

    public function testATextCallOnTheTeamsOwnKeyIsRecordedAgainstTheTeam(): void
    {
        $this->ledger->recordModelUse(
            ProviderSpend::SURFACE_TEXT,
            'summarize',
            ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5-20251001', 'input_tokens' => 812, 'output_tokens' => 64, 'key' => 'caller'],
        );

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('team', $rows[0]['payer']);
        self::assertSame('anthropic', $rows[0]['provider']);
        self::assertSame('claude-haiku-4-5-20251001', $rows[0]['model']);
        self::assertSame(812, $rows[0]['input_tokens']);
        self::assertSame(64, $rows[0]['output_tokens']);
        self::assertSame('tokens', $rows[0]['unit']);
        // Not a token surface, so not a number. See the null-versus-zero test.
    }

    /**
     * The reason `payer` is observed rather than inferred (operator, 2026-08-27).
     *
     * ml-processor's OpenAI header falls back to the BOX key when the caller
     * sent none. For embeddings that is the documented arrangement; for text it
     * would be a defect, and the kind nobody finds — the settings screen would
     * still say the account is on its own key, and the bill would arrive here.
     * Reading `AiCredentials::isOwnAccount()` instead would record what the
     * backend intended and miss it entirely.
     */
    public function testTheKeyTheServiceActuallyUsedIsWhatGetsRecorded(): void
    {
        // The credential id is NON-NULL and the report says `box`, and that
        // pairing is the entire test (corrected 2026-08-27 after Codex found
        // the first version could not fail). With a null credential id, the
        // real rule and the rejected alternative — infer the payer from
        // whether a credential was named — both answer `operator`, so the test
        // passed against the very logic its docblock argues against. Only this
        // combination separates them: an own key was configured, and the
        // provider was reached with the box's.
        // A REAL credential, so the mutant's row inserts and the assertion
        // fails on the payer itself rather than on a foreign key.
        $credential = new AiCredential('openai', 'OpenAI', 'not-a-real-key', '...test');
        $this->em->persist($credential);
        $this->em->flush();

        $this->ledger->recordModelUse(
            ProviderSpend::SURFACE_TEXT,
            'summarize',
            ['provider' => 'openai', 'model' => 'gpt-4o-mini', 'input_tokens' => 10, 'output_tokens' => 2, 'key' => 'box'],
            credentialId: (int) $credential->getId(),
        );

        $rows = $this->rows();
        self::assertSame('operator', $rows[0]['payer'], 'The service reported the box key, so the box paid');
        self::assertNull($rows[0]['credential_id'], 'and an operator-paid row never names an own key');
    }

    /** An unknown answer is attributed to the operator, never silently to the account. */
    public function testAnUnrecognisedKeyReportIsTreatedAsTheOperatorPaying(): void
    {
        $this->ledger->recordModelUse(
            ProviderSpend::SURFACE_TEXT,
            'summarize',
            ['provider' => 'openai', 'model' => 'gpt-4o-mini', 'input_tokens' => 1, 'output_tokens' => 1],
        );

        self::assertSame('operator', $this->rows()[0]['payer']);
    }

    /**
     * The whole reason the table has a `unit` column.
     *
     * A provider that reported nothing is not a call that cost nothing, and an
     * embedding has no completion. Writing 0 in either place makes both
     * indistinguishable from a real zero and makes every SUM quietly wrong,
     * which is exactly what `token_est` does.
     */
    public function testAMissingCountIsNullAndNeverZero(): void
    {
        $this->ledger->recordModelUse(
            ProviderSpend::SURFACE_EMBEDDING,
            'embed_content',
            ['provider' => 'openai', 'model' => 'text-embedding-3-large', 'input_tokens' => 4096, 'output_tokens' => null, 'key' => 'box'],
        );

        $row = $this->rows()[0];
        self::assertSame(4096, $row['input_tokens']);
        self::assertNull($row['output_tokens'], 'An embedding has no completion — null, not 0');
    }

    public function testAnAbsentUsageBlockWritesNothingAtAll(): void
    {
        // "We do not know what this cost" is honestly recorded as no row.
        // A row of nulls would claim a call happened at a known time with
        // unknown cost, which is a different and unearned claim.
        $this->ledger->recordModelUse(ProviderSpend::SURFACE_TEXT, 'summarize', null);

        self::assertSame([], $this->rows());
    }

    /**
     * Bookkeeping must never destroy the thing it is bookkeeping for.
     *
     * A summary that was written, paid for and returned must not be lost
     * because a ledger row would not insert. Losing a row understates a total;
     * throwing loses the user's work as well, and the money is gone either way.
     */
    public function testAMalformedUsageBlockDoesNotThrow(): void
    {
        $this->ledger->recordModelUse(
            ProviderSpend::SURFACE_TEXT,
            'summarize',
            ['provider' => ['not', 'a', 'string'], 'input_tokens' => 'lots', 'key' => 'caller'],
        );

        $row = $this->rows()[0];
        self::assertSame('unknown', $row['provider']);
        self::assertNull($row['input_tokens'], 'A count that is not an integer is not a count');
    }

    /**
     * A call the provider BILLED that returned nothing usable is still spend.
     *
     * The defect Codex found in this feature's first version, 2026-08-27.
     * ml-processor attached usage only on the success path, so an answer that
     * failed validation — Anthropic replying with no text part, an OpenAI
     * completion stopped at its token cap, tag suggestions that were not valid
     * JSON — threw before its cost was read, and the ledger recorded that the
     * call had never happened.
     *
     * Wrong twice over: the money is spent either way, and a call that failed
     * validation is precisely the one somebody would come to this screen
     * looking for.
     */
    public function testABilledCallThatReturnedNothingUsableIsStillRecorded(): void
    {
        $this->ledger->recordModelUse(
            ProviderSpend::SURFACE_TEXT,
            'suggest_tags',
            ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5-20251001', 'input_tokens' => 2400, 'output_tokens' => 0, 'key' => 'caller'],
            succeeded: false,
        );

        $row = $this->rows()[0];
        self::assertSame(0, $row['succeeded']);
        self::assertSame(2400, $row['input_tokens'], 'The input was read and charged for');
    }

    /** A row that says the operator paid must not also name the account's key. */
    public function testAnOperatorPaidRowNeverNamesATeamCredential(): void
    {
        $this->ledger->recordModelUse(
            ProviderSpend::SURFACE_EMBEDDING,
            'embed_content',
            ['provider' => 'openai', 'model' => 'text-embedding-3-large', 'input_tokens' => 9, 'key' => 'box'],
            credentialId: 12345,
        );

        self::assertNull($this->rows()[0]['credential_id'], 'Two answers to one question');
    }
}
