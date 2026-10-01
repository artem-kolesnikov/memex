<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One purchase from an outside provider: what was bought, for whom, and whose
 * account paid.
 *
 * Until 2026-08-27 nothing in memex recorded this. The three numbers that
 * looked as though they might were none of them accounting:
 * `team_ai_credentials.last_used_at` is a date with no count,
 * `note_embeddings.token_est` is `ceil(characters / 4)` of the INPUT text with
 * no output counterpart and no relation to what was billed, and
 * {@see \App\Service\SpendLimiter}'s counters live in a cache pool that a
 * deploy resets — deliberately, because they bound behaviour rather than
 * account for it.
 *
 * ## Why every surface is in one table
 *
 * Operator's decision, 2026-08-27, and it is the shape of the whole feature.
 * The question this answers is **what memex costs HIM**, and one of his two
 * cost surfaces is the one with no team decision behind it: embeddings run
 * on his OpenAI key for every team because a per-team embedding model would
 * mean incomparable vectors. Only TEXT is the team's own key. A table holding
 * text alone would answer a question nobody asked.
 *
 * ## Why some columns are null
 *
 * An embedding has no completion, and a provider does not always report what
 * it counted. The columns that do not apply are **null rather than zero**. A
 * zero here would be indistinguishable from a call that genuinely cost
 * nothing, and it would make every SUM quietly wrong — the same failure as
 * `token_est`, which looks like a token count and is a character count.
 *
 * ## Payer is OBSERVED, not intended
 *
 * `payer` is what the SERVICE reported, never what the backend meant. The
 * backend knows which key it intended to use; only ml-processor knows which
 * one it used, because its OpenAI header falls back to the box key when the
 * caller sent none. For embeddings that fallback is the documented
 * arrangement. For text it would be a defect, and an invisible one — so it is
 * recorded as a fact and can be queried, rather than inferred from intent and
 * never noticed. Decided with the operator on 2026-08-27, over the cheaper
 * option of reading `AiCredentials::isOwnAccount()`.
 *
 * ## What this is NOT
 *
 * Money. No row here carries a price or a computed cost. Token counts are what
 * memex observed; a price is a claim about the world that rots between
 * deploys, so it lives in {@see \App\Service\ProviderPrices} with the date it
 * was checked, and only for the operator's own account.
 *
 * Nor is it content. Like {@see \App\Service\AdminMetrics}, which is the only
 * other cross-team reader, this holds counts and dates and nothing a note
 * said.
 */
#[ORM\Entity]
#[ORM\Table(name: 'provider_spend')]
// The reporting query is "this window, grouped": every read is bounded by time
// first, and by team when the operator drills into one.
#[ORM\Index(name: 'idx_provider_spend_occurred', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_provider_spend_occurred', columns: ['occurred_at'])]
class ProviderSpend
{
    /** Which of the two cost surfaces this row belongs to. */
    public const SURFACE_TEXT = 'text';
    public const SURFACE_EMBEDDING = 'embedding';

    /** What is counted. Every provider this box buys from bills tokens. */
    public const UNIT_TOKENS = 'tokens';

    /**
     * Whose account the provider billed, as the service reported it.
     *
     * `operator` covers both the arrangement CLAUDE.md §Spend describes —
     * embeddings for every team — and the one state that would be a defect: a
     * team's TEXT call that reached the box key. Distinguishing those is the
     * surface's job, not this column's.
     */
    public const PAYER_TEAM = 'team';
    public const PAYER_OPERATOR = 'operator';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'occurred_at')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'surface', length: 16)]
    private string $surface;

    /**
     * The specific thing that was bought — `summarize`, `suggest_tags`,
     * `suggest_title`, `embed_content`, `embed_query`. Free text
     * rather than an enum on purpose: a new operation must be able to start
     * recording spend without a migration, and an unrecognised value in a
     * report is a visible question rather than a lost row.
     */
    #[ORM\Column(name: 'operation', length: 32)]
    private string $operation;

    /** `openai`, `anthropic`, `google`. */
    #[ORM\Column(name: 'provider', length: 32)]
    private string $provider;

    /**
     * The model that actually ran, not the one that was asked for — the box
     * resolves a per-task default when a caller names none.
     */
    #[ORM\Column(name: 'model', length: 64, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(name: 'payer', length: 16)]
    private string $payer;

    /**
     * WHICH of the team's stored keys paid, when one did. A team may hold
     * several keys for one provider — two accounts, two bills — so the
     * provider does not identify it, the same reason
     * `team_settings.ai_credential_id` names a key rather than a provider.
     *
     * `ON DELETE SET NULL`: deleting a key must not delete the record of what
     * it spent, and the row's `provider` and `payer` survive to say what
     * happened without it.
     */
    #[ORM\ManyToOne(targetEntity: AiCredential::class)]
    #[ORM\JoinColumn(name: 'credential_id', nullable: true, onDelete: 'SET NULL')]
    private ?AiCredential $credential = null;

    #[ORM\Column(name: 'unit', length: 16)]
    private string $unit = self::UNIT_TOKENS;

    /** Null when the provider reported no count. */
    #[ORM\Column(name: 'input_tokens', nullable: true)]
    private ?int $inputTokens = null;

    /** Null for an embedding, which has no completion. */
    #[ORM\Column(name: 'output_tokens', nullable: true)]
    private ?int $outputTokens = null;

    /**
     * Whether the thing bought was actually delivered.
     *
     * **A failed call is still billed**, and this is the column that stops that
     * fact from being invisible. A completion cut off at its token cap returns
     * nothing usable and costs as much as one that worked — so if only
     * successful calls were recorded, the rows would be quietest exactly where
     * the money went.
     */
    #[ORM\Column(name: 'succeeded', options: ['default' => true])]
    private bool $succeeded = true;

    public function __construct(
        string $surface,
        string $operation,
        string $provider,
        string $payer,
    ) {
        $this->surface = $surface;
        $this->operation = $operation;
        $this->provider = $provider;
        $this->payer = $payer;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getSurface(): string
    {
        return $this->surface;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getPayer(): string
    {
        return $this->payer;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function withModel(?string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function getCredential(): ?AiCredential
    {
        return $this->credential;
    }

    public function withCredential(?AiCredential $credential): self
    {
        $this->credential = $credential;

        return $this;
    }

    public function getInputTokens(): ?int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): ?int
    {
        return $this->outputTokens;
    }

    public function withTokens(?int $input, ?int $output): self
    {
        $this->inputTokens = $input;
        $this->outputTokens = $output;

        return $this;
    }

    public function didSucceed(): bool
    {
        return $this->succeeded;
    }

    public function withSucceeded(bool $succeeded): self
    {
        $this->succeeded = $succeeded;

        return $this;
    }
}
