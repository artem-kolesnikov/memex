<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\CurationQueue;
use App\Service\NoteGraph;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class GraphController extends ApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NoteGraph $graph,
        private readonly CurationQueue $queue,
    ) {
    }

    #[Route('/api/notes/{id}/graph', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function neighbourhood(int $id, Request $request): JsonResponse
    {
        $note = $this->noteByNumber($this->em, $id);

        return $this->json($this->graph->neighbourhood(
            (int) $note->getId(),
            (int) $request->query->get('depth', '1'),
        ));
    }

    #[Route('/api/graph', methods: ['GET'])]
    public function map(Request $request): JsonResponse
    {
        // The suggestion layer is a k-NN join over the whole collection, so it
        // is asked for rather than always paid: the map opens without it.
        return $this->json($this->graph->map($request->query->getBoolean('semantic')));
    }

    /**
     * How many of the changes behind each affected note travel with it.
     *
     * A bound on the RESPONSE, not on the finding: `changed_neighbours` stays
     * exact, so nothing the reader is told is short. The sources are there to
     * draw the link a change travelled along, and a note with two hundred
     * changed neighbours is a dot with two hundred hot lines out of it —
     * unreadable past a handful, and megabytes of JSON to say so.
     *
     * The ROW count is the map's own cap and nothing tighter. A smaller one
     * looked like the same kind of bound and was not: the map picks its six
     * hundred by flags and defects while this ranks by changed neighbours, so
     * a tighter slice here can drop the one affected note that is drawn and
     * leave the rail saying nothing is stale (Codex, 2026-09-11).
     */
    private const BLAST_SOURCES = 8;

    /**
     * The map's blast-radius mode: notes a neighbour's change may have left
     * stale. Already answered for agents by the `blast_radius` MCP tool, which
     * every role may call — this is the same question asked from the owner's
     * own browser, so it grants nothing the connection did not have.
     */
    #[Route('/api/graph/blast', methods: ['GET'])]
    public function blast(Request $request): JsonResponse
    {
        $days = (int) $request->query->get('since_days', (string) CurationQueue::DEFAULT_BLAST_RADIUS_DAYS);

        $answer = $this->queue->blastRadius(
            min(365, max(1, $days)),
            NoteGraph::MAP_NODE_CAP,
        );
        foreach ($answer['notes'] as $i => $note) {
            $answer['notes'][$i]['changed'] = array_slice($note['changed'], 0, self::BLAST_SOURCES);
        }

        return $this->json($answer);
    }
}
