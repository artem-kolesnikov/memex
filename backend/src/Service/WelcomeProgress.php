<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;
use App\Entity\Note;
use Doctrine\DBAL\Connection;

/**
 * What a new account has actually achieved, read live.
 *
 * The first-run wizard stores no step of its own — it derives the step it
 * opens on from these facts. The reason is the shape of the flow rather than
 * tidiness: connecting an assistant sends somebody into ANOTHER APPLICATION
 * and they come back minutes later, in a different tab, possibly after a
 * reload, possibly on a different machine. A stored cursor would be wrong at
 * exactly that moment; a derived one is right by construction, and a person
 * who connected an assistant by some other route never sees the step telling
 * them to.
 *
 * Booleans, counts and timestamps only. The words belong to the SPA: the
 * server owns what is true, the client owns what is said about it.
 */
class WelcomeProgress
{
    public function __construct(
        private readonly Connection $db,
        private readonly UserProfiles $profiles,
    ) {
    }

    /**
     * @return array{connected: bool, connection: ?string, last_seen: ?string, guide_read: bool, curator: bool, skills: int, waiting: int, kept: bool, profiles: list<array{id: int, title: string, status: string}>}
     */
    public function for(): array
    {
        $connection = $this->firstLiveConnection();

        return [
            'connected' => $connection !== null,
            'connection' => $connection['name'] ?? null,
            'last_seen' => $connection['last_used_at'] ?? null,
            'guide_read' => $this->hasReadGuide(),
            'curator' => $this->hasCurator(),
            'skills' => $this->skillCount(),
            'waiting' => $this->waitingCount(),
            'kept' => $this->hasKeptAgentNote(),
            // The profile slides and Settings › Personalization read these
            // rather than remembering that a profile was made: it may have been
            // deleted, retagged, or written by an assistant since.
            'profiles' => $this->profiles->all(),
        ];
    }

    /**
     * Whether any live connection holds the curator role. The wizard's copy
     * says an assistant's writes wait for review; for a curator that is only
     * true of deletes and merges, and the copy has to say so.
     */
    private function hasCurator(): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT 1 FROM api_tokens WHERE revoked_at IS NULL AND role = :role LIMIT 1',
            ['role' => ApiToken::ROLE_CURATOR]
        );
    }

    /**
     * The assistant that has actually SPOKEN, or null.
     *
     * `last_used_at` rather than the row's existence: a minted token pasted
     * nowhere is the commonest way for connecting to be left half done, and
     * this is the step whose whole job is to catch that. The most recent
     * caller rather than the first, so the last step can say "Claude talked
     * to memex two minutes ago" about the assistant the person is sitting in
     * front of. The name comes back with it because four connections called
     * Claude is the case `display_name` exists for.
     *
     * @return array{name: string, last_used_at: string}|null
     */
    private function firstLiveConnection(): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT COALESCE(display_name, name) AS name, last_used_at FROM api_tokens
             WHERE revoked_at IS NULL AND last_used_at IS NOT NULL
             ORDER BY last_used_at DESC
             LIMIT 1'
        );
        if ($row === false) {
            return null;
        }

        return [
            'name' => (string) $row['name'],
            'last_used_at' => (new \DateTimeImmutable((string) $row['last_used_at']))->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Whether any live connection has opened the memex docs, the
     * `memex-docs` skill, by `get_skill`. Stamped in
     * McpServer, because nothing else records what a connection READ,
     * and it is the one read the wizard's last step asks a person to cause.
     */
    private function hasReadGuide(): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT 1 FROM api_tokens
             WHERE revoked_at IS NULL AND guide_read_at IS NOT NULL
             LIMIT 1'
        );
    }

    /** Proposals sitting in the review inbox. */
    private function waitingCount(): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM notes WHERE status = :pending',
            ['pending' => Note::STATUS_PENDING]
        );
    }

    /**
     * Whether an assistant has written something that survived review: a
     * verified note with a token behind its creation or its last edit.
     */
    private function hasKeptAgentNote(): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT 1 FROM notes
             WHERE status = :verified
               AND (created_by_token_id IS NOT NULL OR last_actor_token_id IS NOT NULL)
             LIMIT 1',
            ['verified' => Note::STATUS_VERIFIED]
        );
    }

    /**
     * How many skills this knowledge base has taken: notes carrying the held
     * `skill` tag, whatever they came from. A skill written by hand counts as
     * much as one taken from the catalogue.
     */
    private function skillCount(): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM notes n
             JOIN note_tag nt ON nt.note_id = n.id
             JOIN tags t ON t.id = nt.tag_id
             WHERE n.status = :verified AND t.name = :tag',
            ['verified' => Note::STATUS_VERIFIED, 'tag' => SystemTags::SKILL]
        );
    }
}
