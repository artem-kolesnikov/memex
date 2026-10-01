<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\ReleasesSession;
use App\Entity\Note;
use App\Entity\NoteRevision;
use App\Service\ActorView;
use App\Service\NoteRevisions;
use App\Service\NoteWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A note's previous states — read, restored, and destroyed.
 *
 * **Operator-only throughout, and for the same reason limbo is.** An agent may
 * propose an edit; deciding that yesterday's version was the right one is a
 * judgment about what the knowledge base contains. Handing that to the same
 * agent whose edits this exists to undo would make the whole mechanism
 * circular. Nothing here is exposed over MCP.
 *
 * Reading a revision returns its body. That is deliberate and worth stating:
 * the point of this surface is to let somebody see what an unattended pass
 * replaced, and a diff nobody can read is a list of dates.
 */
class RevisionController extends ApiController
{
    public function __construct(
        private readonly NoteRevisions $revisions,
        private readonly NoteWriter $writer,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * One note's history, newest first.
     *
     * Not scoped to a note that still exists: a retired note keeps its history
     * through limbo (revisions carry the note id rather than a foreign key), and
     * "what did this look like before it was deleted" is a reasonable question
     * to ask of something in limbo.
     */
    #[Route('/api/notes/{id}/revisions', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function list(int $id, Request $request): JsonResponse
    {
        if ($this->requestToken($request) !== null) {
            return $this->json($this->json400('Note history is operator-only'), Response::HTTP_FORBIDDEN);
        }
        // `note_revisions` deliberately has no FK, so history survives limbo —
        // which is why this resolves through the tombstone too rather than
        // through the live note.
        $noteId = $this->noteRowIdByNumber($this->em, $id);

        // Bodies are reconstructed for the whole list in one walk. Asking per
        // row would re-walk the chain from the current note every time, which
        // is O(n²) patch applications for a list that is always rendered whole.
        ['revisions' => $rows, 'bodies' => $bodies] = $this->revisions->historyFor($noteId);

        return $this->json([
            'note_id' => $id,
            'keep_per_note' => NoteRevision::KEEP_PER_NOTE,
            'revisions' => array_map(
                fn (NoteRevision $r) => $this->toArray($r, $bodies[(int) $r->getId()] ?? null),
                $rows
            ),
        ]);
    }

    /**
     * Put a previous state back.
     *
     * Through `NoteWriter::update()` like every other edit, which means the
     * restore **records a revision of its own** — so an undo can itself be
     * undone, and the history does not lie about how the note came to hold what
     * it holds. Tags come back as names and are re-resolved, which is also how
     * they were stored: a tag deleted by garbage collection in the meantime is
     * simply recreated.
     */
    #[ReleasesSession]
    #[Route('/api/notes/{id}/revisions/{revisionId}/restore', requirements: ['id' => '\d+', 'revisionId' => '\d+'], methods: ['POST'])]
    public function restore(int $id, int $revisionId, Request $request): JsonResponse
    {
        if ($this->requestToken($request) !== null) {
            return $this->json($this->json400('Restoring a revision is operator-only'), Response::HTTP_FORBIDDEN);
        }
        $note = $this->noteByNumber($this->em, $id);
        try {
            $result = $this->writer->restoreRevision($note, $revisionId);
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException|\Symfony\Component\HttpKernel\Exception\ConflictHttpException $e) {
            return $this->json($this->json400($e->getMessage()), $e->getStatusCode());
        }

        return $this->json([
            'restored' => true,
            'from_revision' => $revisionId,
            'note' => $this->noteToArray($result['note'], includeBody: true),
        ]);
    }

    /**
     * Destroy a note's history, keeping the note.
     *
     * The redact-in-place case: removing a leaked credential is an *edit*, so
     * without this the history would faithfully preserve exactly the thing the
     * edit existed to remove. Its counterpart for a deleted note is
     * `DELETE /api/deleted/{id}`, which purges the body and now the revisions
     * with it.
     *
     * Irreversible, and that is the whole feature. An edit that removes a
     * secret cannot be told from any other edit by inspection, so the only
     * honest design is to let the person who knows say so explicitly rather
     * than have a heuristic guess.
     */
    #[Route('/api/notes/{id}/revisions', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function forget(int $id, Request $request): JsonResponse
    {
        if ($this->requestToken($request) !== null) {
            return $this->json($this->json400('Forgetting history is operator-only'), Response::HTTP_FORBIDDEN);
        }
        $forgotten = $this->revisions->forget($this->noteRowIdByNumber($this->em, $id));

        return $this->json(['forgotten' => $forgotten]);
    }

    /**
     * @param string|null $body reconstructed by `NoteRevisions`, not read off
     *                          the row — the row usually holds a patch
     *
     * @return array<string, mixed>
     */
    private function toArray(NoteRevision $revision, ?string $body): array
    {
        return [
            'id' => $revision->getId(),
            'title' => $revision->getTitle(),
            'body_md' => $body,
            'summary' => $revision->getSummary(),
            'tags' => $revision->getTags(),
            // Who took this state away, and when — distinct from how old the
            // content itself was, which is what `content_updated_at` says.
            'replaced_by' => $revision->getReplacedBy(),
            // WHICH write path, and WHICH connection or person.
            'operation' => $revision->getOperation(),
            'replaced_by_actor' => ActorView::replacer($revision, $this->ownerMark()),
            // What that edit said it was doing. Null for edits made directly
            // in the editor.
            'change_title' => $revision->getChangeTitle(),
            // The text that replaced this state was the operator's own, sent
            // when they approved a held change rather than filed by whoever
            // `replaced_by_actor` names.
            'amended_by_operator' => $revision->isAmendedByOperator(),
            'replaced_at' => $revision->getReplacedAt()->format(DATE_ATOM),
            'content_updated_at' => $revision->getNoteUpdatedAt()->format(DATE_ATOM),
        ];
    }
}
