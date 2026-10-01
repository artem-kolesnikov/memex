<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\CurationDigest;
use App\Service\CurationFlags;
use App\Service\CurationQueue;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What needs attention, for the owner's own eyes.
 *
 * The gap this closes: **the owner could not see from a browser what their own
 * curator sees.** Every view into the queue is curator-token work —
 * `curation_candidates`, `last_curated`, `duplicate_candidates` and
 * `blast_radius` all call `McpServer::requireCurator()` — so the person
 * who owns the knowledge base, raises the flags and approves the verdicts had
 * no way to ask what was waiting. Curation was a thing that happened to them.
 *
 * Three questions, one call, because the pane asks all three at once and a
 * screen that fires three requests to fill one card is three chances to show a
 * half-answer:
 *
 *  - **the census** — the same numbers the assistant is told at bootstrap;
 *  - **the top of the queue** — the same rows under the same RANKING, from one
 *    call rather than a second query beside the census (see
 *    {@see CurationQueue::preflight()}, which takes the limit);
 *  - **the open flags** — the operator's own standing requests.
 *
 * **What that shared call does and does not guarantee**, stated because the
 * first version of this docblock overclaimed it and Codex said so. It
 * guarantees one ranking and one set of predicates: the pane cannot drift from
 * the queue by growing its own idea of what needs attention. It does NOT mean
 * the pane and an assistant always hold identical lists — `curation_candidates`
 * takes `include_pending`, `reason`, `cooldown_days`, `stale_days` and
 * `offset`, and an agent that narrows its run sees a narrower list by asking
 * for one. Nor is the answer a snapshot: `candidates()` runs its CTE more than
 * once and `openList()` is a further statement, so a proposal committed
 * mid-request can make one response mildly disagree with itself (it predates
 * this route and MCP has it too).
 *
 * **Session or curator token**, the pairing `assertSessionOrCurator()` exists
 * for: this is the screen the data was built for, and a curator token already
 * reads all of it over MCP, so refusing one here would be a rule that changes
 * with the doorway. An agent token is refused, as it is on every other
 * curation surface.
 *
 * Nothing here spends, and nothing here writes a row of its own — bearer auth
 * still stamps `api_tokens.last_used_at` as it does on every route, which is
 * the honest version of the sentence this comment used to make (Codex,
 * 2026-08-29). Flagging stays where it was — on the note itself,
 * session-only, because an agent that could rank its own work ahead of the
 * human's is the one thing that channel exists to prevent.
 */
class CurationController extends ApiController
{
    /**
     * How much of the queue the pane shows.
     *
     * Ten, and it is a display decision rather than a queue one: a curator
     * works 15-25 notes a run, and this is a settings pane telling somebody
     * what is waiting, not a work surface. `queue_total` carries the real
     * size beside it, so a short list can never be read as an empty queue.
     */
    private const QUEUE_PREVIEW = 10;

    /**
     * Open flags shown. Higher than the queue preview on purpose — these are
     * the operator's own words, every one of them still unanswered, and a
     * truncated list of your own requests is worse than a long one.
     *
     * It is still a bound, so `open_flags_total` travels with it. Codex found
     * the first version shipping 25 rows out of 26 with nothing on the screen
     * saying so, one paragraph under a comment arguing that truncating
     * somebody's own requests is unacceptable. A cap that cannot be seen is
     * the part that was wrong, not the cap.
     */
    private const FLAGS_SHOWN = 25;

    public function __construct(
        private readonly CurationQueue $queue,
        private readonly CurationFlags $flags,
        private readonly CurationDigest $digest,
    ) {
    }

    /**
     * What curation has DONE, run by run — the Activity Journal's digest.
     *
     * Session or curator token, the same pairing as the queue above and for
     * the same reason with one addition: this is the AUDIT, aggregated, and
     * codex H-5 settled that an agent-role token does not read the audit — *an
     * audit an agent can read is an audit an agent can learn to write around*.
     * The counts here are exactly what {@see CuratorLogController} refuses
     * that role, so serving them from a second door would be the drift H-5 was
     * about.
     */
    #[Route('/api/curation/digest', methods: ['GET'])]
    public function digest(Request $request): JsonResponse
    {
        $this->assertSessionOrCurator($request, 'The curation digest');

        return $this->json($this->digest->recent(
            (int) $request->query->get('limit', CurationDigest::DEFAULT_RUNS),
        ));
    }

    #[Route('/api/curation/attention', methods: ['GET'])]
    public function attention(Request $request): JsonResponse
    {
        $this->assertSessionOrCurator($request, 'The curation queue');

        return $this->json($this->queue->preflight(self::QUEUE_PREVIEW) + [
            'open_flags' => $this->flags->openList(self::FLAGS_SHOWN),
            // Both lists are capped, and both caps say so. `queue_total` comes
            // from the preflight; this is its counterpart for the flags.
            'open_flags_total' => $this->flags->openCount(),
            'queue_shown' => self::QUEUE_PREVIEW,
        ]);
    }
}
