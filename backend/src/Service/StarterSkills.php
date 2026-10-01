<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Note;

/**
 * Taking a catalogue skill into a knowledge base, whether the owner presses
 * Add on the Skills page or the account is being opened.
 *
 * {@see self::SEEDED} names the entries that arrive with every new account,
 * switched on, as notes the owner holds and may edit, switch off or delete
 * like any other — the same note Add would have made, so the byline is the
 * owner's. They are examples as much as tools: the welcome notes
 * ({@see WelcomeNotes}) point at them to show what a skill does.
 */
class StarterSkills
{
    /** The catalogue slugs every new knowledge base opens with, written in this order, so the list shows the last first. */
    public const SEEDED = ['handoff', 'plain-writing'];

    public function __construct(
        private readonly SkillLibrary $skills,
        private readonly NoteWriter $writer,
    ) {
    }

    /**
     * Write one catalogue entry into the knowledge base as the owner's note.
     *
     * The catalogue description becomes the note's summary, so no summarize
     * call is bought for text memex already has, and tag suggestions are
     * skipped for the same reason: a shipped skill arrives fully specified.
     *
     * @param array{slug: string, title: string, description: string, short: string, body: string} $entry
     */
    public function take(array $entry, ?EmbeddingSpend $enrich): Note
    {
        return $this->writer->create(
            null,
            $entry['title'],
            $entry['body'],
            Note::SOURCE_MANUAL,
            null,
            ['skill'],
            enrich: $enrich,
            summary: $entry['description'],
            applyTags: false,
        )['note'];
    }

    /**
     * The starter set for a knowledge base that is being opened. Nothing is
     * bought: the embed sweep indexes the notes like any import. Entries the
     * knowledge base already holds are left alone.
     *
     * @return list<Note>
     */
    public function seed(): array
    {
        $notes = [];
        foreach (self::SEEDED as $slug) {
            $entry = $this->skills->catalogueEntry($slug);
            if ($entry === null || $this->skills->find($slug) !== null) {
                continue;
            }
            $notes[] = $this->take($entry, null);
        }

        return $notes;
    }
}
