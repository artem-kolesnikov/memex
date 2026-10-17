<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\ReleasesSession;
use App\Entity\EditProposal;
use App\Entity\Note;
use App\Service\ClientMessage;
use App\Service\ReviewVerdicts;
use App\Service\StaleProposalException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Deciding a SELECTION of held items in one action.
 *
 * Why this exists (operator, 2026-08-19): enrichment goes through the review
 * gate like every other agent write, so an assistant that works a backlog of
 * sixty undescribed notes files sixty held items. The answer is not to let
 * enrichment skip review — only a curator-role token writes unreviewed, and
 * granting that role is the user's decision about which model they trust — but
 * to let the OWNER decide many items at once, knowingly forgoing the
 * item-by-item read and the per-item comment. The operator owns the knowledge
 * base; choosing not to read each line is theirs to choose.
 *
 * Two properties keep that from becoming a way to lose things:
 *
 * - **Every item is decided by the same code as a single click.** The verdict
 *   logic lives in `ReviewVerdicts`, and this route calls it once per item.
 *   Nothing here reaches around `NoteWriter` or `NoteLimbo`, so a batch-
 *   approved delete still retires a note into limbo for its 30 days.
 * - **Failures are per-item, not per-batch.** Approving fifty enrichments must
 *   not be undone because the fifty-first targets a note something else has
 *   removed. Each item stands or falls alone and the response names the ones
 *   that fell.
 */
class InboxController extends ApiController
{
    /** Bounded so one request cannot become an unbounded write transaction. */
    private const MAX_ITEMS = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReviewVerdicts $verdicts,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[ReleasesSession]
    #[Route('/api/inbox/batch', methods: ['POST'])]
    public function batch(Request $request): JsonResponse
    {
        if ($this->requestToken($request) !== null) {
            // The same rule as every single-item verdict: agents file, the
            // operator decides. A bulk route would be the obvious place to
            // forget it.
            return $this->json($this->json400('Only the operator can decide held items'), Response::HTTP_FORBIDDEN);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json($this->json400('Request body is not valid JSON'), Response::HTTP_BAD_REQUEST);
        }

        $action = (string) ($data['action'] ?? '');
        if (!in_array($action, ['approve', 'reject'], true)) {
            return $this->json($this->json400('action must be "approve" or "reject"'), Response::HTTP_BAD_REQUEST);
        }
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if ($items === [] || !array_is_list($items)) {
            return $this->json($this->json400('items must be a non-empty list'), Response::HTTP_BAD_REQUEST);
        }
        if (count($items) > self::MAX_ITEMS) {
            return $this->json($this->json400('At most '.self::MAX_ITEMS.' items per batch'), Response::HTTP_BAD_REQUEST);
        }

        // One comment for the whole selection, or none. Deliberately no
        // `precedent` flag: marking one piece of reasoning as standing policy
        // across fifty items the operator did not read individually is exactly
        // the over-reach the charter forbids the curator, and it would be worse
        // coming from the operator's own side.
        $comment = isset($data['comment']) && is_string($data['comment']) ? trim($data['comment']) : '';
        if (mb_strlen($comment) > self::OPERATOR_COMMENT_MAX) {
            return $this->json($this->json400('comment must be at most '.self::OPERATOR_COMMENT_MAX.' characters'), Response::HTTP_BAD_REQUEST);
        }
        $verdict = ['comment' => $comment === '' ? null : $comment, 'precedent' => false];

        // One moment for the whole selection: what the owner approved together
        // is recorded together, and History credits every assistant in it.
        [$done, $failed] = $this->verdicts->together(fn (): array => $this->decideAll($items, $action, $verdict));

        return $this->json([
            'action' => $action,
            'done' => $done,
            'failed' => $failed,
        ]);
    }

    /**
     * @param list<mixed>                              $items
     * @param array{comment: ?string, precedent: bool} $verdict
     *
     * @return array{0: int, 1: list<array<string, mixed>>} how many were decided, and each that failed
     */
    private function decideAll(array $items, string $action, array $verdict): array
    {
        $done = 0;
        $failed = [];
        // Versions this batch itself moved, by note id: the first it found and
        // the last it left. Approving an assistant's held edit to its own
        // pending note raises that note's version, and the note's approval in
        // the same batch then failed against the version the operator was
        // shown (2026-10-13). They selected both; the batch's own write is not
        // a change made behind their back.
        $moved = [];
        foreach ($items as $i => $item) {
            $kind = is_array($item) && is_string($item['kind'] ?? null) ? $item['kind'] : '';
            $id = is_array($item) && is_numeric($item['id'] ?? null) ? (int) $item['id'] : 0;
            try {
                $notes = $this->notesOf($kind, $id);
                $before = array_map(static fn (Note $note): int => $note->getVersion(), $notes);
                $seen = is_array($item) ? $item : [];
                $this->decide($kind, $id, $action, $verdict, $this->carriesForward($kind, $id) ? self::seenThroughBatch($seen, $notes, $moved) : $seen);
                foreach ($notes as $key => $note) {
                    $noteId = (int) $note->getId();
                    if ($noteId !== 0 && $this->em->contains($note) && $note->getVersion() !== $before[$key]) {
                        $moved[$noteId] = ['from' => $moved[$noteId]['from'] ?? $before[$key], 'to' => $note->getVersion()];
                    }
                }
                ++$done;
            } catch (\Throwable $e) {
                $message = ClientMessage::of($e);
                if ($message === null) {
                    $this->logger->error('A held item could not be decided', ['kind' => $kind, 'id' => $id, 'exception' => $e]);
                }
                $failed[] = ['kind' => $kind, 'id' => $id, 'error' => $message ?? 'memex hit an internal error deciding this item.']
                    + ($e instanceof StaleProposalException ? ['reason' => 'stale'] : []);
                // The unit of work is poisoned once a write throws mid-flush;
                // without this the NEXT item fails for a reason that has
                // nothing to do with it, and the report blames the wrong rows.
                if (!$this->em->isOpen()) {
                    $failed[] = ['kind' => '', 'id' => 0, 'error' => 'Stopped after item '.($i + 1).': the database session could not continue'];
                    break;
                }
                // isOpen() is not the whole story. A write can throw with the
                // manager still open and its unit of work still holding the
                // entity that could not be flushed — every later item then
                // fails on someone else's row. Dropping the identity map is
                // what actually isolates one item's failure from the rest;
                // decide() re-fetches by id, so nothing here needs it kept.
                $this->em->clear();
            }
        }

        return [$done, $failed];
    }

    /** @param array{comment: ?string, precedent: bool} $verdict */
    private function decide(string $kind, int $id, string $action, array $verdict, array $item): void
    {
        switch ($kind) {
            case 'proposal':
                $proposal = $this->em->find(EditProposal::class, $id);
                if ($proposal === null || $proposal->isApplied()) {
                    throw new \InvalidArgumentException('Proposal '.$id.' is not a held item');
                }
                if ($action === 'approve' && $proposal->isStale()) {
                    throw new StaleProposalException('Written before the note changed. Open it to see what changed since, then decide.');
                }
                $action === 'approve'
                    ? $this->verdicts->approveProposal($proposal, $verdict, \App\Service\ReviewSnapshot::proposal($item, $proposal->getMergeIntoNote() !== null))
                    : $this->verdicts->rejectProposal($proposal, $verdict);

                return;

            case 'note':
                $note = $this->em->find(Note::class, $id);
                if ($note === null || $note->getStatus() !== Note::STATUS_PENDING) {
                    throw new \InvalidArgumentException('Note '.$id.' is not pending review');
                }
                $action === 'approve'
                    ? $this->verdicts->approveNote($note, $verdict, expectedVersion: \App\Service\ReviewSnapshot::version($item))
                    : $this->verdicts->rejectNote($note, $verdict);

                return;

            default:
                throw new \InvalidArgumentException('Unknown item kind "'.$kind.'" (proposal|note)');
        }
    }

    /**
     * The notes an item's snapshot names, in the order of its version keys:
     * `expected_version`, then a merge's `expected_merge_version`.
     *
     * @return Note[]
     */
    private function notesOf(string $kind, int $id): array
    {
        if ($kind === 'note') {
            return array_filter([$this->em->find(Note::class, $id)]);
        }
        if ($kind !== 'proposal') {
            return [];
        }
        $proposal = $this->em->find(EditProposal::class, $id);

        return $proposal === null ? [] : array_values(array_filter([$proposal->getNote(), $proposal->getMergeIntoNote()]));
    }

    /**
     * Whether a version this batch moved may be read as the one the operator
     * saw. A pending note, yes: what moved it is its own held edit, approved
     * in the same selection. A draft that recorded what it was written
     * against, yes: if the batch changed what it changes it is stale, and
     * skipped. A draft filed before drafts recorded that, no: nothing could
     * tell a second rewrite from the one it would silently undo.
     */
    private function carriesForward(string $kind, int $id): bool
    {
        if ($kind !== 'proposal') {
            return true;
        }

        return $this->em->find(EditProposal::class, $id)?->getBase() !== null;
    }

    /**
     * The item as the operator saw it, with any version this batch moved read
     * as the version it moved it to.
     *
     * Only a version exactly where the batch found it is carried forward: one
     * the operator saw before something ELSE changed the note still fails.
     *
     * @param Note[] $notes
     * @param array<int, array{from: int, to: int}> $moved
     * @return array<string, mixed>
     */
    private static function seenThroughBatch(array $item, array $notes, array $moved): array
    {
        foreach (['expected_version', 'expected_merge_version'] as $key => $field) {
            $noteId = isset($notes[$key]) ? (int) $notes[$key]->getId() : 0;
            if (isset($moved[$noteId]) && ($item[$field] ?? null) === $moved[$noteId]['from']) {
                $item[$field] = $moved[$noteId]['to'];
            }
        }

        return $item;
    }
}
