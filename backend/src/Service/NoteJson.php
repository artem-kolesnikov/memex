<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EditProposal;
use App\Entity\Note;

/** A note and a held proposal as the API and MCP send them. */
final class NoteJson
{
    /** @return array<string, mixed> */
    public static function note(Note $note, OwnerMark $owner, bool $includeBody = false): array
    {
        $data = [
            // The team's own number, never the global row id: see Note::$number.
            'id' => $note->getId(),
            'title' => $note->getTitle(),
            'source' => $note->getSource(),
            'source_url' => $note->getSourceUrl(),
            'status' => $note->getStatus(),
            'last_actor' => $note->getLastActor(),
            // WHO wrote last — the assistant if one did, otherwise the person —
            // where `last_actor` above says only what KIND of thing it was.
            // Null on notes last written before 2026-08-23 in a team of more
            // than one, where the answer was never recorded and cannot be
            // derived; see Version20260823000034.
            'edited_by' => ActorView::lastWriter($note, $owner),
            'summary' => $note->getSummary(),
            // "Somebody should look at this one." Set from the note form, read
            // by whatever runs an enrichment pass — see Version20260823000035
            // for why the form requests instead of calling.
            // Who described it: a token name, `operator`, or `memex`. Null on
            // notes described before the column existed.
            'summary_by' => $note->getSummaryBy(),
            'tags' => array_map(
                static fn ($tag) => ['id' => $tag->getId(), 'name' => $tag->getName()],
                SystemTags::sortBy($note->getTags()->toArray(), static fn ($tag) => $tag->getName())
            ),
            'created_at' => $note->getCreatedAt()->format(DATE_ATOM),
            'updated_at' => $note->getUpdatedAt()->format(DATE_ATOM),
            // Send this back on an edit as `expected_version` and a write that
            // would overwrite somebody else's becomes a 409 instead.
            'version' => $note->getVersion(),
        ];
        if ($includeBody) {
            $data['body_md'] = $note->getBodyMd();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function proposal(EditProposal $proposal): array
    {
        $keeper = $proposal->getMergeIntoNote();
        $note = $proposal->getNote();

        return [
            'id' => $proposal->getId(),
            'type' => $proposal->getType(),
            'revision' => $proposal->getRevision(),
            'note' => [
                'id' => $note->getId(),
                'title' => $note->getTitle(),
                'status' => $note->getStatus(),
                'version' => $note->getVersion(),
            ],
            'merge_into' => $keeper === null ? null : [
                'id' => $keeper->getId(),
                'title' => $keeper->getTitle(),
                'version' => $keeper->getVersion(),
            ],
            // Six or seven words on what the edit does. The inbox lists rows
            // by it, so it travels with every proposal shape rather than only
            // with edits.
            'change_title' => $proposal->getChangeTitle(),
            'proposed_title' => $proposal->getProposedTitle(),
            'proposed_body_md' => $proposal->getProposedBodyMd(),
            // An anchored edit, unresolved: the operations as filed, so the
            // inbox can show what changes rather than a body that is mostly
            // the same text it already has. It is applied to the note as it
            // stands when this is approved, not now.
            'proposed_patch' => $proposal->getProposedPatch(),
            'proposed_tags' => $proposal->getProposedTags(),
            'proposed_summary' => $proposal->getProposedSummary(),
            // What kind of work this is, so the inbox can be filtered to one
            // kind before anything is decided in bulk (App\Service\ReviewKinds).
            'kinds' => ReviewKinds::ofProposal($proposal),
            'comment' => $proposal->getComment(),
            // The connection's CHOSEN name, or `memex` when memex filed it
            // itself — see EditProposal::authorName(). Read through the
            // proposal rather than off its token, because since 2026-08-23
            // there may not be one.
            'proposed_by' => $proposal->authorName(),
            'created_at' => $proposal->getCreatedAt()->format(DATE_ATOM),
            // When the AUTHOR last changed this draft. A second edit from the
            // same connection revises the item already in review rather than
            // filing another, so `created_at` alone would date text written
            // hours later.
            'revised_at' => $proposal->getRevisedAt()?->format(DATE_ATOM),
        ];
    }
}
