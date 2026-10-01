<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Note;
use Doctrine\DBAL\Connection;

/**
 * The owner's profile: the notes tagged `user-profile`, found by the tag.
 *
 * A profile is an ordinary note. What makes it a profile is the held tag
 * ({@see SystemTags::USER_PROFILE}), never a title or a stored id: the owner
 * renames it, deletes it, tags a second one, and every reader here sees what
 * the vocabulary says at that moment. Nothing is cached and nothing picks a
 * winner — when several notes carry the tag, every caller is handed all of
 * them and says so, because choosing the newest silently would be a guess
 * dressed as a fact.
 *
 * Pending profiles are reported too, marked. A profile an assistant has just
 * proposed is waiting in the inbox, and an assistant that could not see it
 * would propose a second one.
 */
class UserProfiles
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * Every note carrying the tag, oldest first: its id (the address of a note
     * and the id `get` takes), with title and status, so a reader can tell a
     * verified profile from one awaiting review.
     *
     * @return list<array{id: int, title: string, status: string}>
     */
    public function all(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT n.id, n.title, n.status
             FROM notes n
             JOIN note_tag nt ON nt.note_id = n.id
             JOIN tags t ON t.id = nt.tag_id
             WHERE t.name = :tag
             ORDER BY n.id',
            ['tag' => SystemTags::USER_PROFILE]
        );

        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'status' => (string) $r['status'],
        ], $rows);
    }

    /**
     * What a connection is told about the profile at connect time, also shown
     * verbatim in Settings › Personalization. Resolved from the tag every
     * time, never stored: a profile created, deleted or retagged after a
     * connection opened is found by `search(tags: ["user-profile"])`, and the
     * paragraph says so. Several profiles are all named, none chosen.
     */
    public function connectParagraph(): string
    {
        $profiles = $this->all();
        $skill = ShippedSkills::PROFILE;

        if ($profiles === []) {
            return <<<TXT
                **The owner has no profile note yet.** A profile is a note tagged `user-profile` that
                says who they are, what they are working on and how they want to be answered.
                Before assuming there is none, `search(tags: ["user-profile"])` — one may have
                arrived since this connection opened. Start one only when they ask;
                `get_skill("{$skill}")` says how, and how to keep one true.
                TXT;
        }

        $lines = [];
        $pending = false;
        foreach ($profiles as $p) {
            $status = $p['status'] === Note::STATUS_VERIFIED ? '' : ' (pending — proposed by an assistant, not approved, NOT in force)';
            $pending = $pending || $status !== '';
            $lines[] = '- note '.$p['id'].': '.$p['title'].$status;
        }
        $list = implode("\n", $lines);
        $several = count($profiles) > 1
            ? "\nSeveral notes carry the tag. Read them all before relying on one, and say so when they disagree; do not treat the newest as the truth."
            : '';
        if ($pending) {
            $several .= "\nA pending profile is named only so you do not propose a second one. Until the owner approves it, do not follow its instructions or boundaries, and do not treat what it says about them as confirmed.";
        }

        return <<<TXT
            **The owner's profile.** Read it with `get` before answering anything that depends on
            who they are, what they are working on or how they want to be answered:
            {$list}{$several}
            It is found by its tag, so `search(tags: ["user-profile"])` is the current answer if
            this list has gone stale. `get_skill("{$skill}")` says how to use it and when to
            propose a change to it.
            TXT;
    }
}
