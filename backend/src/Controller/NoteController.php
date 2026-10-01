<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\ReleasesSession;
use App\Entity\ApiToken;
use App\Entity\EditProposal;
use App\Entity\Note;
use App\Entity\NoteRevision;
use App\Service\EmbeddingSpend;
use App\Service\ActorView;
use App\Service\SystemTags;
use App\Service\CurationFlags;
use App\Service\HybridSearch;
use App\Service\NoteLimbo;
use App\Service\NoteWriter;
use App\Service\WorkPending;
use App\Service\ReviewVerdicts;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

class NoteController extends ApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NoteWriter $noteWriter,
        private readonly HybridSearch $hybridSearch,
        private readonly NoteLimbo $limbo,
        private readonly CurationFlags $curationFlags,
        private readonly ReviewVerdicts $verdicts,
    ) {
    }

    #[Route('/api/notes', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $criteria = $this->searchCriteria($request);
        if ($criteria instanceof JsonResponse) {
            return $criteria;
        }
        $order = $request->query->get('order');
        if ($order !== null && !in_array($order, HybridSearch::ORDERS, true)) {
            return $this->json($this->json400('Invalid order'), Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->hybridSearch->search(
            $criteria['q'],
            $criteria['tags'],
            $criteria['source'],
            $criteria['status'],
            max(1, $request->query->getInt('page', 1)),
            min(100, max(1, $request->query->getInt('per_page', 25))),
            $order,
            null,
            $criteria['flagged'],
            $criteria['undescribed'],
            $criteria['added_by'],
        ));
    }

    /**
     * Every note the list's search matches, as numbers: what the notes page's
     * map lights. The same parameters as the list, read by the same code, so
     * the two views of one search cannot disagree about what it found.
     */
    #[Route('/api/notes/ids', methods: ['GET'])]
    public function ids(Request $request): JsonResponse
    {
        $criteria = $this->searchCriteria($request);
        if ($criteria instanceof JsonResponse) {
            return $criteria;
        }

        return $this->json($this->hybridSearch->matchingNumbers(
            $criteria['q'],
            $criteria['tags'],
            $criteria['source'],
            $criteria['status'],
            $criteria['flagged'],
            $criteria['undescribed'],
            $criteria['added_by'],
        ));
    }

    /**
     * The search a notes request asks for, or the 400 that refuses it.
     *
     * @return JsonResponse|array{q: ?string, tags: int[], source: ?string, status: ?string, flagged: bool, undescribed: bool, added_by: ?int}
     */
    private function searchCriteria(Request $request): JsonResponse|array
    {
        $status = $request->query->get('status');
        if ($status !== null && !in_array($status, [Note::STATUS_VERIFIED, Note::STATUS_PENDING], true)) {
            return $this->json($this->json400('Invalid status'), Response::HTTP_BAD_REQUEST);
        }
        $source = $request->query->get('source');
        if ($source !== null && !in_array($source, Note::SOURCES, true)) {
            return $this->json($this->json400('Invalid source'), Response::HTTP_BAD_REQUEST);
        }
        $tagIds = array_values(array_filter(array_map('intval', explode(',', $request->query->get('tags', ''))), static fn (int $id) => $id > 0));
        // Which connection added these notes. Refused rather than ignored when
        // the id names no connection: an empty list is the one response an
        // operator chasing a leaked token must not be given by mistake.
        //
        // Parsed STRICTLY, and the first version was not. `getInt()` returns 0
        // for anything `FILTER_VALIDATE_INT` rejects — `abc`, `1e5`, `01`, a
        // number past PHP_INT_MAX — and `?: null` then turned every one of
        // those into "no filter at all": HTTP 200 with the WHOLE note list and
        // no error anywhere. That is worse than the empty list the comment
        // above warns about, because the operator gets a full list back and it
        // reads as "this connection wrote all of this". A digits-only pattern
        // instead, so a value that is not an id is a 400 rather than a
        // silently dropped filter. A digit string too large for an int survives
        // the pattern, saturates on cast, matches no token, and is refused
        // below.
        //
        // `null` alone, NOT `null` or empty. The first strict version also
        // exempted the empty string, which put `?added_by=` — a hand-edited or
        // half-pasted URL — straight back into the failure this whole parse
        // exists to close: 200, the whole list, no error. `status` two blocks up
        // has always had it right, testing only for null and letting the
        // validity check refuse everything else.
        $rawAddedBy = $request->query->get('added_by');
        $addedBy = null;
        if ($rawAddedBy !== null) {
            // `\z`, not `$`: PCRE's `$` matches before a trailing newline, so
            // `added_by=1%0A` satisfied a pattern meant to read "digits only".
            // Harmless in the event — it casts to a real id and is looked up —
            // but a pattern that does not mean what it says is one someone
            // will reuse.
            if (!is_string($rawAddedBy) || 1 !== preg_match('/^[1-9][0-9]*\z/', $rawAddedBy)) {
                return $this->json($this->json400('Invalid added_by'), Response::HTTP_BAD_REQUEST);
            }
            $addedBy = (int) $rawAddedBy;
            if ($this->em->getRepository(ApiToken::class)->find($addedBy) === null) {
                return $this->json($this->json400('Invalid added_by'), Response::HTTP_BAD_REQUEST);
            }
        }

        return [
            'q' => $request->query->get('q'),
            'tags' => $tagIds,
            'source' => $source,
            'status' => $status,
            'flagged' => $request->query->getBoolean('flagged'),
            'undescribed' => $request->query->getBoolean('undescribed'),
            'added_by' => $addedBy,
        ];
    }

    /** Lightweight id+title list for the editor's [[link]] autocomplete. */
    #[Route('/api/notes/titles', methods: ['GET'])]
    public function titles(): JsonResponse
    {
        $titles = $this->em->getConnection()->executeQuery(
            'SELECT id, title FROM notes ORDER BY LOWER(title) ASC'
        )->fetchAllAssociative();

        return $this->json(['titles' => array_map(
            static fn (array $r) => ['id' => (int) $r['id'], 'title' => $r['title']],
            $titles
        )]);
    }

    /**
     * Enough about a handful of notes to decide whether a mention is worth
     * linking, without opening each one in a tab: what the note is (summary,
     * tags), whether it is settled (status), and how connected it already is
     * (backlinks). Deliberately NOT folded into /api/notes/titles — that list
     * is every note in the vault and feeds autocomplete on every keystroke,
     * whereas this is bounded by the link-suggestion cap.
     */
    #[Route('/api/notes/link-targets', methods: ['GET'])]
    public function linkTargets(Request $request): JsonResponse
    {
        $ids = array_slice(array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) $request->query->get('ids', ''))),
            static fn (int $id): bool => $id > 0
        ))), 0, 25);
        if ($ids === []) {
            return $this->json(['targets' => []]);
        }

        $rows = $this->em->getConnection()->executeQuery(
            'SELECT n.id, n.title, n.summary, n.status, n.updated_at,
                    (SELECT count(*) FROM note_links bl WHERE bl.to_note_id = n.id) AS backlinks,
                    COALESCE(
                        (SELECT group_concat(t.name, \',\' ORDER BY '.SystemTags::sqlRank('t.name').', t.name)
                         FROM note_tag nt JOIN tags t ON t.id = nt.tag_id
                         WHERE nt.note_id = n.id),
                        \'\'
                    ) AS tags
             FROM notes n
             WHERE n.id IN (:ids)',
            ['ids' => $ids],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
        )->fetchAllAssociative();

        return $this->json(['targets' => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'summary' => $r['summary'] !== null && $r['summary'] !== '' ? (string) $r['summary'] : null,
            'status' => (string) $r['status'],
            'tags' => $r['tags'] !== '' ? explode(',', (string) $r['tags']) : [],
            'backlinks' => (int) $r['backlinks'],
            'updated_at' => (string) $r['updated_at'],
        ], $rows)]);
    }

    /** Badge counts for the header inbox icon. */
    #[Route('/api/inbox/count', methods: ['GET'])]
    public function inboxCount(WorkPending $workPending): JsonResponse
    {
        return $this->json($workPending->inbox());
    }

    #[ReleasesSession]
    #[Route('/api/notes', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $token = $this->requestToken($request);
        $data = $request->toArray();

        $title = trim((string) ($data['title'] ?? ''));
        $bodyMd = (string) ($data['body_md'] ?? '');
        if ($title === '' || mb_strlen($title) > 500) {
            return $this->json($this->json400('title is required (max 500 chars)'), Response::HTTP_BAD_REQUEST);
        }
        if (trim($bodyMd) === '') {
            return $this->json($this->json400('body_md is required'), Response::HTTP_BAD_REQUEST);
        }
        $sourceUrl = $data['source_url'] ?? null;
        if ($sourceUrl !== null && Note::normaliseSourceUrl($sourceUrl) === null) {
            return $this->json($this->json400('source_url must be an http(s) URL'), Response::HTTP_BAD_REQUEST);
        }

        $result = $this->noteWriter->create(
            $token,
            $title,
            $bodyMd,
            $token !== null ? Note::SOURCE_AGENT : Note::SOURCE_MANUAL,
            is_string($sourceUrl) ? $sourceUrl : null,
            is_array($data['tags'] ?? null) ? $data['tags'] : [],
            // A request caused this, so the embedding it buys on the
            // operator's key is counted. Reachable by a bearer token, which is
            // exactly the caller this bound exists for.
            enrich: EmbeddingSpend::Metered,
            summary: isset($data['summary']) && is_string($data['summary']) ? $data['summary'] : null,
        );

        return $this->json([
            'note' => $this->noteToArray($result['note'], true),
            'suggested_tags' => $result['suggestions'],
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/notes/{id}', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        $note = $this->findNote($id);

        $conn = $this->em->getConnection();
        $links = $conn->executeQuery(
            'SELECT nl.raw_target, n.id AS to_id, n.title AS to_title
             FROM note_links nl LEFT JOIN notes n ON n.id = nl.to_note_id
             WHERE nl.from_note_id = :id ORDER BY nl.id',
            ['id' => $note->getId()]
        )->fetchAllAssociative();
        $backlinks = $conn->executeQuery(
            'SELECT n.id, n.title FROM note_links nl JOIN notes n ON n.id = nl.from_note_id
             WHERE nl.to_note_id = :id ORDER BY lower(n.title)',
            ['id' => $note->getId()]
        )->fetchAllAssociative();

        $data = $this->noteToArray($note, true);
        // Who ADDED it, in the same shape as `edited_by`. This was a bare
        // string built from `getCreatedByToken()?->getName()` — the token's
        // REGISTERED name, so a connection the operator had renamed still
        // showed as `dev-curator` here while the notes list showed the name he
        // gave it, with no mark either way. One producer now, so the two
        // cannot disagree again (App\Service\ActorView).
        $data['added_by'] = ActorView::added($note, $this->ownerMark());
        // The id behind that mark, so the page can offer "everything this
        // connection added" as a link. Beside `added_by` rather than inside it:
        // that shape is shared with `edited_by` and with every search row, and
        // widening it there would put a token id in a dozen payloads to serve
        // one link on one screen. Null when a person added the note, which is
        // what makes the link conditional rather than always drawn.
        $data['added_by_token_id'] = $note->getCreatedByToken()?->getId();
        $data['described_at'] = $note->getEnrichedAt()?->format(DATE_ATOM);
        // The one thing memex does for a note on its own, so the page can say
        // so plainly instead of leaving the user to infer it. Cheap here and
        // deliberately not in the list endpoint, where it would be a join for
        // a fact nobody reads per row.
        $data['embedded'] = (bool) $conn->fetchOne(
            'SELECT 1 FROM note_embeddings WHERE note_id = :id',
            ['id' => $note->getId()]
        );
        $data['pending_proposals'] = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM edit_proposals
             WHERE note_id = :id AND status = 'held'",
            ['id' => $note->getId()]
        );
        $data['links'] = array_map(static fn (array $l) => [
            'target' => $l['raw_target'],
            'note_id' => $l['to_id'] !== null ? (int) $l['to_id'] : null,
            'title' => $l['to_title'],
        ], $links);
        $data['backlinks'] = array_map(static fn (array $b) => [
            'note_id' => (int) $b['id'],
            'title' => $b['title'],
        ], $backlinks);
        // Null when nobody has flagged it — the same shape MCP's `get` returns,
        // so the page and the agent are reading one field.
        $data['curation_flag'] = $this->curationFlags->open($note)?->toArray();

        return $this->json($data);
    }

    /**
     * Flag this note for priority curation, in the operator's own words.
     *
     * The comment is the feature; the flag is only what makes it standing. It
     * goes into `curation_flags` (where the queue sorts it ahead of everything
     * structural and the cooldown cannot hide it) and into the Curator log at
     * the same moment, so a run that never reaches the queue verbs still meets
     * it at bootstrap.
     *
     * Operator-only, session auth: an agent that could flag notes could rank
     * its own work ahead of what the human asked for, which is the one thing
     * this channel exists to prevent. Re-flagging rewords the open flag.
     */
    #[Route('/api/notes/{id}/flag', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function flag(int $id, Request $request): JsonResponse
    {
        $note = $this->findNote($id);
        if ($this->requestToken($request) !== null) {
            return $this->json($this->json400('Only the operator can flag notes for curation'), Response::HTTP_FORBIDDEN);
        }

        try {
            $comment = CurationFlags::normalizeComment($request->toArray()['comment'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return $this->json($this->json400($e->getMessage()), Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'curation_flag' => $this->curationFlags->raise($note, $this->currentAccount()->getName(), $comment)->toArray(),
        ]);
    }

    /** Withdraw the flag — "never mind", as against the curator's "done". */
    #[Route('/api/notes/{id}/flag', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function unflag(int $id, Request $request): JsonResponse
    {
        $note = $this->findNote($id);
        if ($this->requestToken($request) !== null) {
            return $this->json($this->json400('Only the operator can withdraw a curation flag'), Response::HTTP_FORBIDDEN);
        }

        return $this->json(['withdrawn' => $this->curationFlags->withdraw($note, $this->currentAccount()->getName()) !== null]);
    }

    #[ReleasesSession]
    #[Route('/api/notes/{id}', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $note = $this->findNote($id);
        $data = $request->toArray();

        $title = isset($data['title']) ? trim((string) $data['title']) : null;
        if ($title !== null && ($title === '' || mb_strlen($title) > 500)) {
            return $this->json($this->json400('title must be 1-500 chars'), Response::HTTP_BAD_REQUEST);
        }
        $bodyMd = isset($data['body_md']) ? (string) $data['body_md'] : null;
        if ($bodyMd !== null && trim($bodyMd) === '') {
            return $this->json($this->json400('body_md must not be empty'), Response::HTTP_BAD_REQUEST);
        }
        $tags = is_array($data['tags'] ?? null) ? array_map('strval', $data['tags']) : null;

        // The staleness check, for the window a version column inside one
        // request cannot see: an editor open for minutes while a curator token
        // rewrites the same note. The client sends back the `version` it was
        // given, and a mismatch is a 409 rather than a silent overwrite of work
        // it never saw. Optional, so existing clients keep working — the ORM's
        // own version check still catches the narrow same-instant collision on
        // every write path regardless.
        if (isset($data['expected_version'])) {
            if (!is_int($data['expected_version'])) {
                return $this->json($this->json400('expected_version must be an integer'), Response::HTTP_BAD_REQUEST);
            }
            if ($data['expected_version'] !== $note->getVersion()) {
                return $this->json([
                    // Two different 409s reach this editor now, and they ask
                    // for opposite things — one says somebody already saved,
                    // the other that somebody is waiting to.
                    'conflict' => 'version',
                    'error' => 'This note changed while you were editing it.',
                    'expected_version' => $data['expected_version'],
                    'current_version' => $note->getVersion(),
                    'last_actor' => $note->getLastActor(),
                ], Response::HTTP_CONFLICT);
            }
        }

        // Anchored edits: change part of a note without resending all of it.
        // See App\Service\NotePatch for why this exists and what it refuses.
        $patch = null;
        if (array_key_exists('patch', $data)) {
            try {
                $patch = \App\Service\NotePatch::parse($data['patch']);
            } catch (\App\Service\NotePatchException $e) {
                return $this->json($this->json400($e->getMessage()), Response::HTTP_BAD_REQUEST);
            }
        }

        $token = $this->requestToken($request);
        if ($token !== null) {
            // PRODUCT.md §Review gate: agent edits are held, not applied.
            // A summary alone is a complete edit — it is what enriching an
            // undescribed note looks like — so it counts here too.
            $proposedSummary = NoteWriter::normaliseSummary($data['summary'] ?? null);
            try {
                $proposal = $this->noteWriter->propose(
                    $note,
                    $token,
                    $title,
                    $bodyMd,
                    $tags,
                    isset($data['comment']) && is_string($data['comment']) ? $data['comment'] : null,
                    summary: $proposedSummary,
                    patch: $patch,
                );
            } catch (\App\Service\NotePatchException $e) {
                // The anchor did not fit the note as it stands. The caller's
                // own text, quoted back, with what to do about it.
                return $this->json($this->json400($e->getMessage()), Response::HTTP_CONFLICT);
            } catch (\InvalidArgumentException $e) {
                return $this->json($this->json400($e->getMessage()), Response::HTTP_BAD_REQUEST);
            }

            // **Whether it APPLIED is asked of the proposal**, not guessed from
            // the request. A curator token's safe edit applies on the spot, and
            // this route told it "held for review — the note is unchanged"
            // regardless: a caller that believed the answer and re-sent the
            // edit then got an anchor conflict caused by its own first call
            // (codex, 2026-08-26). MCP has always asked `isApplied()`; this is
            // the same question, on the route that never asked it.
            if ($proposal->isApplied()) {
                return $this->json([
                    'proposal' => $this->proposalToArray($proposal),
                    'applied' => true,
                    'review' => 'Applied immediately (curator token) — the change is live and recorded in the activity log.',
                ]);
            }

            return $this->json([
                'proposal' => $this->proposalToArray($proposal),
                'applied' => false,
                // Two ways to be held, and only one of them has a fix the
                // caller can act on (C-9, NoteWriter::wantsAnchoring).
                'review' => match (true) {
                    $proposal->getRevisedAt() !== null => 'Edit folded into the draft you already have in review — the operator sees ONE item per note per connection, so this revised that one rather than adding a second. Everything you sent replaced the matching field; anchors were appended to the ones already held; fields you left out stand as filed. Read `proposal` for the whole document as it now reads.',
                    $proposal->getType() === EditProposal::TYPE_REPORT => 'Report filed — the note is unchanged, and nothing here proposes changing it. The operator sees what you said in the review inbox and decides what to do about it.',
                    NoteWriter::holdsInstructions($token, $note, $tags) => NoteWriter::INSTRUCTIONS_HELD,
                    NoteWriter::wantsAnchoring($token, $bodyMd) => 'Edit held for review — it replaces the whole body, and your edits apply without review, so a full body could silently overwrite whatever another connection changed while you were reading. Send the same change as `patch` and it applies immediately.',
                    default => 'Edit held for review — the note is unchanged until the operator approves the proposal in the review inbox.',
                },
            ], Response::HTTP_ACCEPTED);
        }

        // Summary contract (operator form): field present + non-empty = kept
        // verbatim; present + empty = cleared and regenerated; absent = the
        // AI refresh behaves as before.
        if ($patch !== null) {
            try {
                $bodyMd = \App\Service\NotePatch::apply($note->getBodyMd(), $patch);
            } catch (\App\Service\NotePatchException $e) {
                return $this->json($this->json400($e->getMessage()), Response::HTTP_CONFLICT);
            }
        }

        $result = $this->limbo->whileHoldingNotes([$note], function () use ($note, $title, $bodyMd, $tags, $data, $request): array|JsonResponse {
            $this->verdicts->assertNoteVersion($note, $data['expected_version'] ?? $note->getVersion());
            $held = $this->em->getRepository(EditProposal::class)->findBy(
                ['note' => $note, 'status' => EditProposal::STATUS_HELD],
                ['id' => 'ASC'],
            );
            if ($held !== [] && ($data['discard_proposals'] ?? null) !== true) {
                return $this->json([
                    'conflict' => 'pending_proposals',
                    'error' => 'This note has work waiting in the review inbox.',
                    'pending_proposals' => array_map($this->proposalToArray(...), $held),
                ], Response::HTTP_CONFLICT);
            }
            foreach ($held as $proposal) {
                $this->verdicts->rejectSuperseded($proposal);
            }
            return $this->noteWriter->update(
                $note,
                $title,
                $bodyMd,
                $tags,
                // The editor's own save, and a bearer PUT. Both are requests, so
                // the embedding the edit buys on the operator's key is counted.
                null,
                summaryProvided: array_key_exists('summary', $data) && ($data['summary'] === null || is_string($data['summary'])),
                summary: isset($data['summary']) && is_string($data['summary']) ? $data['summary'] : null,
                // This route is the editor: a token reaching it is still an actor,
                // but an operator saving the form is the common case.
                actor: Note::actorFor(null, $this->requestToken($request)),
                actorToken: $this->requestToken($request),
                // Absent means "unchanged", not "off": an edit that says nothing
                // about the request must not withdraw one.
                operation: NoteRevision::OP_EDIT,
            );
        });
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $result['suggestions'] = $this->noteWriter->finishUpdateEnrichment($note, $bodyMd !== null, true, EmbeddingSpend::Metered);

        return $this->json([
            'note' => $this->noteToArray($result['note'], true),
            'suggested_tags' => $result['suggestions'],
        ]);
    }

    #[Route('/api/notes/{id}', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $note = $this->findNote($id);
        $token = $this->requestToken($request);
        if ($token !== null) {
            // Review gate: agents propose deletion, the operator approves it.
            $body = json_decode($request->getContent() ?: '{}', true);
            $proposal = $this->noteWriter->proposeDelete(
                $note,
                $token,
                is_array($body) && is_string($body['comment'] ?? null) ? $body['comment'] : null,
            );

            return $this->json([
                'proposal' => $this->proposalToArray($proposal),
                'review' => 'Deletion held for review — the note stays until the operator approves it in the review inbox.',
            ], Response::HTTP_ACCEPTED);
        }
        // Operator delete: retired to limbo, restorable for NoteLimbo::LIMBO_DAYS.
        $body = json_decode($request->getContent() ?: '{}', true);

        return $this->limbo->whileHoldingNotes([$note], function () use ($note, $body): JsonResponse {
            // The note comes back for 30 days; the work held against it does not.
            // So the same step the owner's SAVE has (operator, 2026-09-08): refused
            // once, naming what is waiting, and `discard_proposals` is the answer
            // coming back.
            $held = $this->limbo->heldDraftsForRetirement($note);
            if ($held !== [] && (!is_array($body) || ($body['discard_proposals'] ?? null) !== true)) {
                return $this->json([
                    'conflict' => 'pending_proposals',
                    'error' => 'This note has work waiting in the review inbox. Deleting it discards that work permanently — a restore brings the note back, not the drafts.',
                    'pending_proposals' => array_map($this->proposalToArray(...), $held),
                ], Response::HTTP_CONFLICT);
            }

            $this->limbo->retire(
                $note,
                'operator',
                is_array($body) && is_string($body['reason'] ?? null) ? $body['reason'] : null,
            );
            $this->noteWriter->gcTags();

            return $this->json(['deleted' => true, 'restorable_for_days' => NoteLimbo::LIMBO_DAYS]);
        });
    }

    /**
     * Agent merge proposal: fold this note into `into_note_id` on approval.
     * Token-only — the operator merges directly by editing and deleting.
     */
    #[Route('/api/notes/{id}/propose-merge', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function proposeMerge(int $id, Request $request): JsonResponse
    {
        $token = $this->requestToken($request);
        if ($token === null) {
            return $this->json($this->json400('Merge proposals are an agent verb; operators edit and delete directly'), Response::HTTP_BAD_REQUEST);
        }
        $absorb = $this->findNote($id);
        $data = $request->toArray();
        $keeper = $this->findNote((int) ($data['into_note_id'] ?? 0));

        try {
            $proposal = $this->noteWriter->proposeMerge(
                $absorb,
                $keeper,
                $token,
                isset($data['merged_body_md']) && is_string($data['merged_body_md']) ? $data['merged_body_md'] : null,
                isset($data['comment']) && is_string($data['comment']) ? $data['comment'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json($this->json400($e->getMessage()), Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'proposal' => $this->proposalToArray($proposal),
            'review' => 'Merge held for review — both notes stay until the operator approves it in the review inbox.',
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Approve a pending note — as filed, or with the operator's own edits.
     *
     * The edits are a real save, not a shortcut past one: they run through
     * `NoteWriter::update()` like any other operator edit, so the note gets a
     * revision authored by the person approving it, before the row that says
     * it was approved. Reading the history afterwards, an amended approval is
     * two rows — what the agent wrote, then what the operator made of it —
     * rather than one row silently crediting the agent with text they never
     * sent.
     */
    #[ReleasesSession]
    #[Route('/api/notes/{id}/approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function approve(int $id, Request $request): JsonResponse
    {
        $note = $this->findNote($id);
        if ($this->requestToken($request) !== null) {
            // The gate exists to keep agents out of the verified state.
            return $this->json($this->json400('Only the operator can approve notes'), Response::HTTP_FORBIDDEN);
        }
        try {
            $edits = $this->approvalEdits($request);
            $expected = \App\Service\ReviewSnapshot::version($request->toArray());
        } catch (BadRequestHttpException $e) {
            return $this->json($this->json400($e->getMessage()), Response::HTTP_BAD_REQUEST);
        }
        if ($edits !== null && $note->getStatus() !== Note::STATUS_PENDING) {
            return $this->json($this->json400('Only a pending note can be approved with edits'), Response::HTTP_BAD_REQUEST);
        }
        $amended = false;
        $bodyChanged = false;
        $this->verdicts->approveNote($note, $this->operatorVerdict($request), expectedVersion: $expected, amend: function () use ($note, $edits, &$amended, &$bodyChanged): bool {
            if ($edits !== null) {
                // Whether this payload CHANGES anything, asked before it is
                // applied. The editor sends the whole note rather than the moved
                // fields, so "edits arrived" is not "the operator rewrote it" — and
                // reading it that way put their name on text they only read, in the
                // revision and in the journal both (Codex, 2026-09-07).
                $bodyChanged = $edits['body_md'] !== null && $edits['body_md'] !== $note->getBodyMd();
                $amended = ($edits['title'] !== null && $edits['title'] !== $note->getTitle())
                    || $bodyChanged
                    || ($edits['summary_provided'] && $edits['summary'] !== $note->getSummary())
                    || ($edits['tags'] !== null && self::tagSet($edits['tags']) !== self::tagSet(
                        array_map(static fn ($tag) => $tag->getName(), $note->getTags()->toArray())
                    ));
                if ($amended) {
                    $this->noteWriter->update(
                        $note,
                        $edits['title'],
                        $edits['body_md'],
                        $edits['tags'],
                        // The operator's own save, exactly as the editor's is: the
                        // embedding this buys is counted against the same budget.
                        null,
                        summaryProvided: $edits['summary_provided'],
                        summary: $edits['summary'],
                        actor: Note::actorFor(null, null),
                        operation: NoteRevision::OP_EDIT,
                    );
                }
            }
            return $amended;
        });
        if ($amended) {
            $this->noteWriter->finishUpdateEnrichment($note, $bodyChanged, true, EmbeddingSpend::Metered);
        }

        return $this->json(['note' => $this->noteToArray($note)]);
    }

    /**
     * @param string[] $tags
     * @return string[]
     */
    private static function tagSet(array $tags): array
    {
        $set = array_values(array_unique($tags));
        sort($set);

        return $set;
    }

    #[ReleasesSession]
    #[Route('/api/notes/{id}/reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reject(int $id, Request $request): JsonResponse
    {
        $note = $this->findNote($id);
        if ($this->requestToken($request) !== null) {
            return $this->json($this->json400('Only the operator can reject notes'), Response::HTTP_FORBIDDEN);
        }
        if ($note->getStatus() !== Note::STATUS_PENDING) {
            return $this->json($this->json400('Only pending notes can be rejected'), Response::HTTP_BAD_REQUEST);
        }
        $this->verdicts->rejectNote($note, $this->operatorVerdict($request));

        return $this->json(['rejected' => true, 'restorable_for_days' => NoteLimbo::LIMBO_DAYS]);
    }

    /**
     * The operator's own edits, sent alongside an approval verdict.
     *
     * Null when the request carries none — `comment` and `is_precedent` are
     * the verdict's, not the note's, so a plain approval with reasoning must
     * not be read as an amendment and must not write a revision.
     *
     * @return array{title: ?string, body_md: ?string, tags: ?array<int, string>, summary: ?string, summary_provided: bool, expected_version: ?int}|null
     */
    private function approvalEdits(Request $request): ?array
    {
        if (trim($request->getContent()) === '') {
            return null;
        }
        $data = $request->toArray();
        $touched = array_filter(
            ['title', 'body_md', 'tags', 'summary'],
            static fn (string $field) => array_key_exists($field, $data)
        );
        if ($touched === []) {
            return null;
        }

        $title = null;
        if (array_key_exists('title', $data)) {
            $title = is_string($data['title']) ? trim($data['title']) : '';
            if ($title === '' || mb_strlen($title) > 500) {
                throw new BadRequestHttpException('title must be 1-500 chars');
            }
        }
        $bodyMd = null;
        if (array_key_exists('body_md', $data)) {
            if (!is_string($data['body_md']) || trim($data['body_md']) === '') {
                throw new BadRequestHttpException('body_md must not be empty');
            }
            $bodyMd = $data['body_md'];
        }
        $tags = null;
        if (array_key_exists('tags', $data)) {
            if (!is_array($data['tags']) || !array_is_list($data['tags'])) {
                throw new BadRequestHttpException('tags must be a list');
            }
            $tags = array_map('strval', $data['tags']);
        }
        $expected = null;
        if (array_key_exists('expected_version', $data)) {
            if (!is_int($data['expected_version'])) {
                throw new BadRequestHttpException('expected_version must be an integer');
            }
            $expected = $data['expected_version'];
        }

        return [
            'title' => $title,
            'body_md' => $bodyMd,
            'tags' => $tags,
            // The same summary contract the editor's PUT follows: present and
            // non-empty is kept verbatim, present and empty clears it for
            // regeneration, absent leaves it alone.
            'summary' => isset($data['summary']) && is_string($data['summary']) ? $data['summary'] : null,
            'summary_provided' => array_key_exists('summary', $data)
                && ($data['summary'] === null || is_string($data['summary'])),
            'expected_version' => $expected,
        ];
    }

    private function findNote(int $id): Note
    {
        return $this->noteByNumber($this->em, $id);
    }
}
