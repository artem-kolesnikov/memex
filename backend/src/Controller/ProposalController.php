<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\ReleasesSession;
use App\Entity\EditProposal;
use App\Service\ReviewVerdicts;
use App\Service\SystemTags;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Review-inbox surface for held agent edits (PRODUCT.md §Review gate).
 * Approve applies the proposal through NoteWriter (links re-sync, enrichment
 * re-runs) and discards it; reject just discards it. Both operator-only —
 * an agent approving its own edit would void the gate.
 */
class ProposalController extends ApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReviewVerdicts $verdicts,
    ) {
    }

    #[Route('/api/proposals', methods: ['GET'])]
    public function list(): JsonResponse
    {
        // HELD rows only. Applied rows are audit records (AuditController).
        $proposals = $this->em->getRepository(EditProposal::class)->findBy(
            ['status' => EditProposal::STATUS_HELD],
            ['createdAt' => 'ASC'],
        );

        return $this->json([
            'proposals' => array_map($this->proposalToArray(...), $proposals),
        ]);
    }

    #[Route('/api/proposals/{id}', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        $proposal = $this->findProposal($id);
        $data = $this->proposalToArray($proposal);
        // The current body rides along so the UI can show what would change.
        $data['note']['body_md'] = $proposal->getNote()->getBodyMd();
        // And the current summary, so an enrichment proposal reads as filling a
        // blank rather than as text arriving from nowhere.
        $data['note']['summary'] = $proposal->getNote()->getSummary();
        $data['note']['tags'] = array_map(
            static fn ($tag) => $tag->getName(),
            SystemTags::sortBy($proposal->getNote()->getTags()->toArray(), static fn ($tag) => $tag->getName())
        );
        // For merges the meaningful diff is against the KEEPER's body; for
        // deletes the inbox shows what would dangle.
        if ($proposal->getMergeIntoNote() !== null) {
            $data['merge_into'] += $this->keeperToArray($proposal->getMergeIntoNote());
        }
        if ($proposal->getType() !== EditProposal::TYPE_EDIT) {
            $data['note']['backlinks'] = $this->em->getConnection()->executeQuery(
                'SELECT n.id AS id, n.title FROM note_links nl JOIN notes n ON n.id = nl.from_note_id
                 WHERE nl.to_note_id = :id ORDER BY lower(n.title)',
                ['id' => $proposal->getNote()->getId()]
            )->fetchAllAssociative();
        }

        return $this->json($data);
    }

    #[ReleasesSession]
    #[Route('/api/proposals/{id}/approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function approve(int $id, Request $request): JsonResponse
    {
        if ($this->requestToken($request) !== null) {
            return $this->json($this->json400('Only the operator can approve edit proposals'), Response::HTTP_FORBIDDEN);
        }
        $proposal = $this->findProposal($id);
        try {
            $amended = $this->amendment($request);
            $snapshot = \App\Service\ReviewSnapshot::proposal($request->toArray(), $proposal->getMergeIntoNote() !== null);
        } catch (BadRequestHttpException $e) {
            return $this->json($this->json400($e->getMessage()), Response::HTTP_BAD_REQUEST);
        }
        if ($amended !== null) {
            try {
                $proposal->amend($amended['title'], $amended['body_md'], $amended['tags'], $amended['summary']);
            } catch (\InvalidArgumentException $e) {
                return $this->json($this->json400($e->getMessage()), Response::HTTP_BAD_REQUEST);
            }
        }
        try {
            $result = $this->verdicts->approveProposal($proposal, $this->operatorVerdict($request), $snapshot);
        } catch (\App\Service\NotePatchException $e) {
            // An anchored edit whose text has moved since it was filed. 409,
            // and the proposal STAYS HELD: the alternative is applying a change
            // to text nobody reviewed, or discarding somebody's work because a
            // sentence was reworded. Rejecting it is the operator's call, not
            // ours, and it is one button away.
            return $this->json(
                $this->json400('This edit no longer fits the note. '.$e->getMessage()),
                Response::HTTP_CONFLICT
            );
        }

        return $this->json([
            // null after an applied delete — the note is gone
            'note' => $result['note'] === null ? null : $this->noteToArray($result['note'], true),
            'suggested_tags' => $result['suggestions'],
        ]);
    }

    #[ReleasesSession]
    #[Route('/api/proposals/{id}/reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reject(int $id, Request $request): JsonResponse
    {
        if ($this->requestToken($request) !== null) {
            return $this->json($this->json400('Only the operator can reject edit proposals'), Response::HTTP_FORBIDDEN);
        }
        $this->verdicts->rejectProposal($this->findProposal($id), $this->operatorVerdict($request));

        return $this->json(['rejected' => true]);
    }

    private function findProposal(int $id): EditProposal
    {
        $proposal = $this->em->find(EditProposal::class, $id);
        if ($proposal === null) {
            throw $this->createNotFoundException('Proposal not found');
        }
        // Applied rows are immutable audit records, not reviewable.
        if ($proposal->isApplied()) {
            throw $this->createNotFoundException('Proposal not found');
        }

        return $proposal;
    }

    /** `body_md` on approve: the operator's own version of the edit, applied instead of the proposed one. */
    /**
     * The operator's own version of what this proposal does, or null when they
     * sent none — `comment` and `is_precedent` are the verdict's, so a verdict
     * carrying only reasoning must not be read as an amendment.
     *
     * @return array{title: ?string, body_md: ?string, tags: ?array<int, string>, summary: ?string}|null
     */
    private function amendment(Request $request): ?array
    {
        if (trim($request->getContent()) === '') {
            return null;
        }
        $data = $request->toArray();
        $fields = ['title', 'body_md', 'tags', 'summary'];
        if (array_filter($fields, static fn (string $f) => array_key_exists($f, $data)) === []) {
            return null;
        }

        $title = null;
        if (array_key_exists('title', $data)) {
            $title = is_string($data['title']) ? trim($data['title']) : '';
            if ($title === '' || mb_strlen($title) > 500) {
                throw new BadRequestHttpException('title must be 1-500 chars');
            }
        }
        $body = null;
        if (array_key_exists('body_md', $data)) {
            if (!is_string($data['body_md']) || trim($data['body_md']) === '') {
                throw new BadRequestHttpException('body_md must be a non-empty string');
            }
            $body = $data['body_md'];
        }
        $tags = null;
        if (array_key_exists('tags', $data)) {
            if (!is_array($data['tags']) || !array_is_list($data['tags'])) {
                throw new BadRequestHttpException('tags must be a list');
            }
            $tags = array_map('strval', $data['tags']);
        }
        $summary = null;
        if (array_key_exists('summary', $data)) {
            if (!is_string($data['summary'])) {
                throw new BadRequestHttpException('summary must be a string');
            }
            $summary = $data['summary'];
        }

        return ['title' => $title, 'body_md' => $body, 'tags' => $tags, 'summary' => $summary];
    }
}
