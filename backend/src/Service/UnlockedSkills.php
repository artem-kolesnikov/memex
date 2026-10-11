<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Note;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The system skills this knowledge base's owner has unlocked: a note of theirs
 * that memex serves under the system skill's alias in place of its own text.
 *
 * {@see ShippedSkills} says why memex's skills are served from code: a copy in
 * a note never receives memex's corrections. Unlocking is the owner choosing
 * that trade for one skill, so what the old charter notes died of is closed
 * here instead. The alias is the record's, never derived from a title; the
 * copy is served only while it is a verified note tagged `skill`, and memex's
 * own text again the moment it is not; an assistant's edit to it is held for
 * every role, as for any skill. The hash of memex's text at unlock or restore
 * says whether memex has changed it since.
 *
 * A record is the note's and outlives its deletion, with no foreign key to
 * cascade it away: a copy restored from Deleted notes comes back at its own
 * number and is the copy again, never a second skill under another alias.
 * Restore locks the skill again and keeps the copy, so unlocking it later
 * reopens the same note and its history goes on.
 */
class UnlockedSkills
{
    /** The system skills that can be unlocked; memex-writing follows its presets and memex-docs the docs site. */
    public const UNLOCKABLE = [SkillLibrary::CURATION_CHARTER, ShippedSkills::RECALL, ShippedSkills::PROFILE, ShippedSkills::SKILLS];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public static function unlockable(string $slug): bool
    {
        return in_array($slug, self::UNLOCKABLE, true);
    }

    /** @param array{title: string, description: string, body: string} $text */
    public static function hash(array $text): string
    {
        return hash('sha256', $text['title']."\n".$text['description']."\n".$text['body']);
    }

    /**
     * The copies served now, by alias.
     *
     * @return array<string, array{note_id: int, title: string, description: string, body: string, updated_at: string, shipped_hash: string}>
     */
    public function served(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT u.slug, u.note_id, u.shipped_hash, n.title, n.summary, n.body_md, n.updated_at
             FROM unlocked_skills u
             JOIN notes n ON n.id = u.note_id
             WHERE u.locked_at IS NULL AND n.status = :verified
               AND EXISTS (SELECT 1 FROM note_tag nt JOIN tags t ON t.id = nt.tag_id WHERE nt.note_id = n.id AND t.name = :tag)
             ORDER BY u.unlocked_at, u.note_id",
            ['verified' => Note::STATUS_VERIFIED, 'tag' => SystemTags::SKILL],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['slug']] = [
                'note_id' => (int) $row['note_id'],
                'title' => (string) $row['title'],
                'description' => (string) ($row['summary'] ?? ''),
                'body' => (string) $row['body_md'],
                'updated_at' => (string) $row['updated_at'],
                'shipped_hash' => (string) $row['shipped_hash'],
            ];
        }

        return $out;
    }

    /**
     * A new copy of the alias. An earlier one that stopped being a skill is a
     * plain note now and is let go; one in Deleted notes keeps its record, so
     * restoring it is refused while this one is served rather than served as
     * a second skill.
     */
    public function record(string $slug, int $noteId, string $shippedHash): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'DELETE FROM unlocked_skills WHERE slug = :s AND note_id NOT IN (SELECT id FROM deleted_notes WHERE purged_at IS NULL)',
            ['s' => $slug],
        );
        $conn->executeStatement(
            'INSERT INTO unlocked_skills (note_id, slug, shipped_hash, unlocked_at) VALUES (:n, :s, :h, :now)',
            ['s' => $slug, 'n' => $noteId, 'h' => $shippedHash, 'now' => gmdate('Y-m-d H:i:s')],
        );
    }

    /** The copy a restore locked, still a skill, for unlocking to reopen. */
    public function lockedCopy(string $slug): ?int
    {
        $id = $this->em->getConnection()->fetchOne(
            "SELECT u.note_id FROM unlocked_skills u JOIN notes n ON n.id = u.note_id
             WHERE u.slug = :s AND u.locked_at IS NOT NULL AND n.status = :verified
               AND EXISTS (SELECT 1 FROM note_tag nt JOIN tags t ON t.id = nt.tag_id WHERE nt.note_id = n.id AND t.name = :tag)
             ORDER BY u.locked_at DESC, u.note_id DESC LIMIT 1",
            ['s' => $slug, 'verified' => Note::STATUS_VERIFIED, 'tag' => SystemTags::SKILL],
        );

        return $id === false ? null : (int) $id;
    }

    public function lock(int $noteId): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE unlocked_skills SET locked_at = :now WHERE note_id = :n',
            ['n' => $noteId, 'now' => gmdate('Y-m-d H:i:s')],
        );
    }

    public function reopen(int $noteId, string $shippedHash): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE unlocked_skills SET locked_at = NULL, shipped_hash = :h, unlocked_at = :now WHERE note_id = :n',
            ['n' => $noteId, 'h' => $shippedHash, 'now' => gmdate('Y-m-d H:i:s')],
        );
    }

    /** The alias a note coming back from Deleted notes would clash on: its own, now served by a later copy. */
    public function clashOnReturn(int $noteId): ?string
    {
        $slug = $this->em->getConnection()->fetchOne('SELECT slug FROM unlocked_skills WHERE note_id = :n', ['n' => $noteId]);
        if ($slug === false) {
            return null;
        }
        $served = $this->served()[(string) $slug] ?? null;

        return $served !== null && $served['note_id'] !== $noteId ? (string) $slug : null;
    }
}
