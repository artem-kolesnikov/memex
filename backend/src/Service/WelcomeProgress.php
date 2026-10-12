<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * What a new account has actually achieved, read live: whether an assistant
 * has connected, which the sidebar's *Connect an assistant* button reads, and
 * the owner's profile notes, which the welcome screens and Settings ›
 * Personalization read.
 *
 * Booleans and ids only. The words belong to the SPA: the server owns what is
 * true, the client owns what is said about it.
 */
class WelcomeProgress
{
    public function __construct(
        private readonly Connection $db,
        private readonly UserProfiles $profiles,
    ) {
    }

    /**
     * @return array{connected: bool, profiles: list<array{id: int, title: string, status: string}>}
     */
    public function for(): array
    {
        return [
            'connected' => $this->hasConnected(),
            // Read rather than remembered: a profile may have been deleted,
            // retagged, or written by an assistant since.
            'profiles' => $this->profiles->all(),
        ];
    }

    /**
     * Whether a live connection has SPOKEN. `last_used_at` rather than the
     * row's existence: a minted token pasted nowhere is the commonest way for
     * connecting to be left half done.
     */
    private function hasConnected(): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT 1 FROM api_tokens WHERE revoked_at IS NULL AND last_used_at IS NOT NULL LIMIT 1'
        );
    }
}
