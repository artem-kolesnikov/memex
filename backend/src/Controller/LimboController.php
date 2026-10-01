<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\NoteLimbo;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Limbo — retired notes, and the tombstones they leave behind.
 *
 * Operator-only throughout, deliberately: agents may PROPOSE deletion, but
 * un-deleting is a decision about what the knowledge base contains, and
 * handing an agent the ability to reverse the operator's retirements would
 * make the review gate a suggestion. Nothing here is exposed over MCP.
 */
class LimboController extends ApiController
{
    public function __construct(private readonly NoteLimbo $limbo)
    {
    }

    #[Route('/api/deleted', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        if ($this->requestToken($request) !== null) {
            return $this->json($this->json400('Limbo is operator-only'), Response::HTTP_FORBIDDEN);
        }

        $includePurged = $request->query->getBoolean('include_purged');
        $perPage = max(1, min(NoteLimbo::PER_PAGE_MAX, $request->query->getInt('per_page', 20)));
        $query = mb_substr(trim((string) $request->query->get('q', '')), 0, 200);
        $total = $this->limbo->count($includePurged, $query);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $request->query->getInt('page', 1)));

        return $this->json([
            'limbo_days' => NoteLimbo::LIMBO_DAYS,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'notes' => $this->limbo->list($includePurged, $perPage, ($page - 1) * $perPage, $query),
        ]);
    }

    /** Reincarnate: the note comes back at its original id. */
    #[Route('/api/deleted/{id}/restore', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function restore(int $id, Request $request): JsonResponse
    {
        if ($this->requestToken($request) !== null) {
            return $this->json($this->json400('Restoring is operator-only'), Response::HTTP_FORBIDDEN);
        }

        $note = $this->limbo->restore($id);
        if ($note === null) {
            return $this->json(
                $this->json400('Not in limbo, or already purged — a tombstone has no content to restore'),
                Response::HTTP_NOT_FOUND
            );
        }

        return $this->json(['restored' => true, 'note' => $this->noteToArray($note)]);
    }

    /**
     * Purge now, without waiting out the 30 days. This exists for one reason:
     * a note retired BECAUSE it should not exist — a leaked credential, or
     * personal data — where "deleted but retained for a month" is precisely
     * the wrong outcome. The tombstone (title, reason, dates) survives; the
     * content does not, and it does not come back.
     */
    #[Route('/api/deleted/{id}', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function purge(int $id, Request $request): JsonResponse
    {
        if ($this->requestToken($request) !== null) {
            return $this->json($this->json400('Purging is operator-only'), Response::HTTP_FORBIDDEN);
        }
        if (!$this->limbo->purge($id)) {
            return $this->json($this->json400('Not in limbo, or already purged'), Response::HTTP_NOT_FOUND);
        }

        return $this->json(['purged' => true]);
    }
}
