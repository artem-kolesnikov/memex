<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What is actually in this knowledge base, counted rather than estimated.
 *
 * Two groups, and only two, because the operator asked for what a person would
 * act on rather than for trivia (2026-08-20):
 *
 *  - **records and their state** — how much is here, how much is waiting for a
 *    review decision, how much nobody has described, how much is in limbo and
 *    still restorable;
 *  - **indexing** — how many notes have an embedding. This is the number that
 *    explains why search feels incomplete for a few minutes after an import,
 *    which is otherwise indistinguishable from search being broken.
 */
class StatsController extends ApiController
{
    public function __construct(private readonly Connection $db)
    {
    }

    #[Route('/api/stats', methods: ['GET'])]
    public function stats(Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Stats');

        $notes = $this->db->fetchAssociative(
            'SELECT
                COUNT(*)                                              AS total,
                COUNT(*) FILTER (WHERE status = :verified)            AS verified,
                COUNT(*) FILTER (WHERE status = :pending)             AS pending,
                COUNT(*) FILTER (WHERE summary IS NULL OR summary = \'\') AS undescribed
             FROM notes',
            ['verified' => 'verified', 'pending' => 'pending']
        ) ?: [];

        // Restorable means not yet purged: a purged row keeps its tombstone for
        // ever but has no content left to bring back, so counting it as
        // restorable would promise something limbo cannot deliver.
        $limbo = $this->db->fetchOne('SELECT COUNT(*) FROM deleted_notes WHERE purged_at IS NULL');

        $embedded = $this->db->fetchOne('SELECT COUNT(*) FROM note_embeddings');

        $total = (int) ($notes['total'] ?? 0);
        $embedded = (int) $embedded;

        return $this->json([
            'notes' => [
                'total' => $total,
                'verified' => (int) ($notes['verified'] ?? 0),
                'pending' => (int) ($notes['pending'] ?? 0),
                'undescribed' => (int) ($notes['undescribed'] ?? 0),
            ],
            'limbo' => ['restorable' => (int) $limbo],
            'indexing' => [
                'embedded' => $embedded,
                // Never negative: an embedding row can outlive its note only
                // through a bug, and reporting "-2 waiting" would send someone
                // hunting for a queue that does not exist.
                'waiting' => max(0, $total - $embedded),
            ],
        ]);
    }
}
