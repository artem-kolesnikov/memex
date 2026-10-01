<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApiToken;
use App\Entity\CurationPreset;
use App\Service\AgentIcons;
use App\Service\CurationBriefFields;
use App\Service\CurationCharter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The instructions half of curation: the shipped canon, this vault's
 * briefs, and what memex has observed about the connections that curate.
 *
 * Session-only, all of it. A brief is the operator telling their agents how to
 * work, and a token that could rewrite its own instructions is the same hole
 * that flag-raising is session-only to avoid.
 */
class CurationDeskController extends ApiController
{
    public function __construct(
        private readonly CurationCharter $charter,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/curation/instructions', methods: ['GET'])]
    public function instructions(Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'The curation instructions');
        $canon = $this->charter->canon();

        return $this->json([
            'canon' => [
                'title' => $canon['title'],
                'description' => $canon['description'],
                'body' => $canon['body'],
            ],
            'presets' => array_map(
                fn (CurationPreset $p) => $this->presetPayload($p),
                $this->charter->presets(),
            ),
            'field_options' => [
                'work_first' => \App\Service\CurationQueue::reasons(),
                'boldness' => CurationBriefFields::BOLDNESS,
                'report_back' => CurationBriefFields::REPORT_BACK,
                'notes_per_run' => ['min' => CurationBriefFields::NOTES_PER_RUN_MIN, 'max' => CurationBriefFields::NOTES_PER_RUN_MAX],
                'cooldown_days' => ['min' => CurationBriefFields::COOLDOWN_MIN, 'max' => CurationBriefFields::COOLDOWN_MAX],
            ],
        ]);
    }

    #[Route('/api/curation/presets/{id}', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function updatePreset(int $id, Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'A curation profile');
        $preset = $this->ownPreset($id);
        if ($preset === null) {
            return $this->json($this->json400('No such profile'), Response::HTTP_NOT_FOUND);
        }
        // Standard is locked, and the lock is here rather than on the screen:
        // it is what every connection falls back to and what an unedited
        // account is served, so a request that reaches this route by any other
        // path must not be able to move it.
        if ($preset->isStandard()) {
            return $this->json(
                $this->json400('The default profile is what memex ships and every assistant falls back to. Add a profile to set your own.'),
                Response::HTTP_BAD_REQUEST,
            );
        }
        $body = $this->jsonBody($request);

        if (is_string($body['name'] ?? null)) {
            $name = trim($body['name']);
            if ($name === '' || mb_strlen($name) > CurationPreset::MAX_NAME) {
                return $this->json($this->json400('A profile needs a name of 1 to '.CurationPreset::MAX_NAME.' characters'), Response::HTTP_BAD_REQUEST);
            }
            if ($refusal = $this->reservedName($name)) {
                return $this->json($this->json400($refusal), Response::HTTP_BAD_REQUEST);
            }
            $preset->rename($name);
        }
        if (is_array($body['fields'] ?? null)) {
            $preset->setFields(CurationBriefFields::normalize($body['fields']));
        }

        return $this->flushOrConflict($preset);
    }

    #[Route('/api/curation/presets', methods: ['POST'])]
    public function createPreset(Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'A curation profile');
        $body = $this->jsonBody($request);
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > CurationPreset::MAX_NAME) {
            return $this->json($this->json400('A profile needs a name of 1 to '.CurationPreset::MAX_NAME.' characters'), Response::HTTP_BAD_REQUEST);
        }
        if ($refusal = $this->reservedName($name)) {
            return $this->json($this->json400($refusal), Response::HTTP_BAD_REQUEST);
        }
        $fields = is_array($body['fields'] ?? null) ? $body['fields'] : [];
        $preset = new CurationPreset($name, CurationBriefFields::normalize($fields));
        $this->em->persist($preset);

        return $this->flushOrConflict($preset, Response::HTTP_CREATED);
    }

    #[Route('/api/curation/presets/{id}', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function deletePreset(int $id, Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'A curation profile');
        $preset = $this->ownPreset($id);
        if ($preset === null) {
            return $this->json($this->json400('No such profile'), Response::HTTP_NOT_FOUND);
        }
        if ($preset->isStandard()) {
            return $this->json($this->json400('The default profile cannot be deleted — every assistant falls back to it'), Response::HTTP_BAD_REQUEST);
        }
        // Connections pointing at it fall back to Standard: ON DELETE SET NULL,
        // and a null preset is Standard everywhere it is read.
        $this->em->remove($preset);
        $this->em->flush();

        return $this->json(['deleted' => true]);
    }

    /**
     * The connections that curate, and what memex has SEEN of them.
     *
     * **Curator-role only** (operator, 2026-08-27): the Curator checkbox in
     * Settings → Connections is the flag, and an account with a dozen assistants
     * turned a setup panel into a directory. The others are counted, not
     * listed — an agent-role connection may still curate with everything held
     * for review, so saying nothing about them would be false.
     *
     * Nothing here is a health check. memex cannot probe an agent; it reports
     * the last time each connection asked for the charter and under which
     * profile.
     */
    #[Route('/api/curation/wiring', methods: ['GET'])]
    public function wiring(Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'The curation wiring');

        $serves = $this->em->getConnection()->fetchAllAssociative(
            "SELECT token_id, MAX(served_at) AS last_at, COUNT(*) AS serves
             FROM skill_serves WHERE slug = 'memex-curation' AND token_id IS NOT NULL
             GROUP BY token_id",
        );
        $byToken = [];
        foreach ($serves as $row) {
            $byToken[(int) $row['token_id']] = $row;
        }

        // The last PASS, which is the run-summary row — not the last time the
        // token spoke, which is any read at all and would call an assistant
        // that never curated "last run today".
        $runs = $this->em->getConnection()->fetchAllKeyValue(
            "SELECT token_id, MAX(created_at) FROM curator_log
             WHERE action = 'run-summary' AND token_id IS NOT NULL
             GROUP BY token_id",
        );

        $connections = [];
        $others = 0;
        foreach ($this->em->getRepository(ApiToken::class)->findBy([], ['id' => 'ASC']) as $token) {
            if ($token->isRevoked()) {
                continue;
            }
            if (!$token->isCurator()) {
                ++$others;
                continue;
            }
            $seen = $byToken[$token->getId()] ?? null;
            $lastRun = $runs[$token->getId()] ?? null;
            $preset = $this->charter->presetFor($token);
            $connections[] = [
                'id' => $token->getId(),
                'name' => $token->getDisplayName() ?? $token->getName(),
                // What the client registered as, kept beside a chosen name so
                // "which of these two Claudes is it" stays answerable.
                'label' => $token->getName(),
                ...AgentIcons::markFor(
                    $token->getIconKey(),
                    $token->hasUploadedIcon() ? '/api/tokens/'.$token->getId().'/icon' : null,
                ),
                'preset_id' => $preset->getId(),
                'preset_name' => $preset->getName(),
                'last_run_at' => $lastRun === null ? null : (new \DateTimeImmutable((string) $lastRun))->format(\DATE_ATOM),
                'charter_last_loaded_at' => $seen === null ? null : (new \DateTimeImmutable((string) $seen['last_at']))->format(\DATE_ATOM),
                'charter_loads' => (int) ($seen['serves'] ?? 0),
            ];
        }

        return $this->json(['connections' => $connections, 'other_count' => $others]);
    }

    /**
     * The prompt, both lengths, for the settings currently on screen —
     * including ones not saved yet, which is the point: flipping a control
     * shows what an agent would actually be told.
     */
    #[Route('/api/curation/preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'The curation prompt');
        $body = $this->jsonBody($request);

        return $this->json($this->charter->prompts(
            mb_substr(trim((string) ($body['name'] ?? CurationPreset::DEFAULT_NAME)), 0, CurationPreset::MAX_NAME) ?: CurationPreset::DEFAULT_NAME,
            is_array($body['fields'] ?? null) ? $body['fields'] : [],
        ));
    }

    /** Point a connection at a profile. Session-only, like every role decision. */
    #[Route('/api/curation/wiring/{id}', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function setConnectionPreset(int $id, Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'The curation wiring');
        $token = $this->em->getRepository(ApiToken::class)->find($id);
        if ($token === null) {
            return $this->json($this->json400('No such connection'), Response::HTTP_NOT_FOUND);
        }
        $rawId = $this->jsonBody($request)['preset_id'] ?? null;
        $preset = is_numeric($rawId) ? $this->ownPreset((int) $rawId) : null;
        if ($rawId !== null && $preset === null) {
            return $this->json($this->json400('No such profile'), Response::HTTP_NOT_FOUND);
        }
        $token->setCurationPreset($preset);
        $this->em->flush();

        return $this->json(['preset_id' => $preset?->getId()]);
    }

    /**
     * The shipped profile's name is not available to a user's own profile.
     *
     * Not tidiness: the seed in {@see CurationCharter::standard()} inserts by
     * NAME, so a vault holding an ordinary profile called `Default` before the
     * shipped one was seeded made that seed collide on the unique index — and
     * the re-query for `standard = true` then found nothing, leaving every MCP
     * path that lists or loads a skill throwing, permanently, for that vault.
     * `prompts/list` and `resources/list` sit outside `callTool`'s catch, so it
     * was a 500 rather than a tool error. Found by Codex on review.
     */
    private function reservedName(string $name): ?string
    {
        return mb_strtolower($name) === mb_strtolower(CurationPreset::DEFAULT_NAME)
            ? '“'.CurationPreset::DEFAULT_NAME.'” is the profile memex ships. Give yours another name.'
            : null;
    }

    private function ownPreset(int $id): ?CurationPreset
    {
        return $this->em->getRepository(CurationPreset::class)->find($id);
    }

    private function flushOrConflict(CurationPreset $preset, int $status = Response::HTTP_OK): JsonResponse
    {
        try {
            $this->em->flush();
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            return $this->json($this->json400('A profile with that name already exists'), Response::HTTP_CONFLICT);
        }

        return $this->json($this->presetPayload($preset), $status);
    }

    /** @return array<string, mixed> */
    private function presetPayload(CurationPreset $preset): array
    {
        return [
            'id' => $preset->getId(),
            'name' => $preset->getName(),
            'is_standard' => $preset->isStandard(),
            'is_shipped' => $preset->isShipped(),
            'version' => $preset->getVersion(),
            'updated_at' => $preset->getUpdatedAt()->format(\DATE_ATOM),
            'fields' => CurationBriefFields::normalize($preset->getFields()),
            'brief_text' => CurationCharter::briefText($preset),
        ];
    }

    /** @return array<string, mixed> */
    private function jsonBody(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);

        return is_array($body) ? $body : [];
    }
}
