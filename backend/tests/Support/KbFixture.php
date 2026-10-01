<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Directory\Account;
use App\Entity\ApiToken;
use App\Entity\Note;
use App\Service\NoteWriter;
use Psr\Container\ContainerInterface;

/**
 * A minimal vault to act on: one account, one agent-role connection, a second
 * agent, and one curator-role connection. The vault is entered on creation
 * and stays the one the test works in.
 *
 * Deliberately NOT a copy of the operator's vault. Every note a test needs it
 * writes itself, in the test, so the assertion and the setup are readable
 * together and no test depends on content it did not create.
 */
final class KbFixture
{
    public Tenant $tenant;
    public Account $account;
    public ApiToken $agentToken;
    public ApiToken $curatorToken;
    /** A SECOND connection, for what only happens when two authors work one note. */
    public ApiToken $otherAgentToken;

    private readonly int $otherAgentTokenId;

    public function __construct(private readonly ContainerInterface $container)
    {
        $this->tenant = new Tenant($container, 'Kb');
        $this->otherAgentTokenId = $this->tenant->connection('test-agent-2', 'mxt_agent_kb_2');
        $this->refresh();
    }

    /**
     * Re-attach after something cleared the identity map. NoteLimbo::restore()
     * calls em->clear(), which detaches everything the fixture is holding — so
     * a test that writes another note after a restore needs this first.
     */
    public function refresh(): void
    {
        $this->tenant->enter();
        $this->account = $this->tenant->account();
        $this->agentToken = $this->tenant->agentToken();
        $this->curatorToken = $this->tenant->curatorToken();
        $this->otherAgentToken = $this->container->get('doctrine.orm.vault_entity_manager')->find(ApiToken::class, $this->otherAgentTokenId);
    }

    /**
     * A verified note, written the way the owner's own notes are: straight
     * through NoteWriter.
     *
     * @param string[] $tags
     */
    public function note(NoteWriter $writer, string $title, string $body = 'Body text.', array $tags = [], ?string $summary = null): Note
    {
        $this->tenant->enter();

        return $writer->create(
            null,
            $title,
            $body,
            Note::SOURCE_MANUAL,
            null,
            $tags,
            enrich: \App\Service\EmbeddingSpend::Metered,
            summary: $summary,
            applyTags: false,
        )['note'];
    }
}
