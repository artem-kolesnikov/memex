<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Note;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What is waiting for somebody, as counts: the review inbox for the owner,
 * the enrichment backlog for an agent. Cheap by construction, so a
 * connection can ask before deciding whether a run is worth starting.
 */
class WorkPending
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return array{pending_notes: int, edit_proposals: int, total: int} */
    public function inbox(): array
    {
        $conn = $this->em->getConnection();
        $counts = [
            'pending_notes' => (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM notes WHERE status = :s',
                ['s' => Note::STATUS_PENDING]
            ),
            'edit_proposals' => (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM edit_proposals WHERE status = 'held'"
            ),
        ];
        $counts['total'] = $counts['pending_notes'] + $counts['edit_proposals'];

        return $counts;
    }

    /** @return array{total: int, awaiting_review: int} */
    public function enrichment(): array
    {
        $conn = $this->em->getConnection();
        $resting = $conn->fetchAssociative(EnrichmentBacklog::restingSql()) ?: [];

        return [
            'total' => (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM notes n WHERE '.EnrichmentBacklog::sql()
            ),
            'awaiting_review' => (int) ($resting['awaiting_review'] ?? 0),
        ];
    }
}
