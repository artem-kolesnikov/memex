<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\ReleasesSession;
use App\Entity\ApiToken;
use App\Entity\Note;
use App\Service\AgentIcons;
use App\Service\EmbeddingSpend;
use App\Service\ShippedSkills;
use App\Service\SkillLibrary;
use App\Service\SkillLint;
use App\Service\SkillNotUnlockable;
use App\Service\SkillPackage;
use App\Service\SkillServes;
use App\Service\SkillServing;
use App\Service\SkillSlugTaken;
use App\Service\SkillUnlockConflict;
use App\Service\SkillUnlocking;
use App\Service\StarterSkills;
use App\Service\UserGuide;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The skills page — one list of every skill this knowledge base can serve,
 * shipped or the user's own, and the one route that edits a note's serving
 * record — plus the catalogue verb that takes a shipped entry into a note.
 *
 * This exists for what a note list cannot answer: what memex offers that this
 * KB has not taken, and what state each skill is served in. Onboarding is one
 * caller; it presents the catalogue, the user picks, and each pick is written
 * into the KB as a note that then belongs to them. The same endpoints are how
 * a user takes a skill back after deleting it, and how skills added to memex
 * later reach an account that already exists.
 */
class SkillController extends ApiController
{
    public function __construct(
        private readonly SkillLibrary $skills,
        private readonly StarterSkills $starters,
        private readonly EntityManagerInterface $em,
        private readonly SkillServing $serving,
        private readonly SkillServes $serves,
        private readonly SkillLint $lint,
        private readonly SkillPackage $package,
        private readonly UserGuide $guide,
        private readonly SkillUnlocking $unlocking,
    ) {
    }

    #[Route('/api/skills', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'The skills page');

        return $this->json(['skills' => $this->rows(), 'connections' => $this->connections()]);
    }

    #[Route('/api/skills/{id}', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Skill settings');
        $note = $this->noteByNumber($this->em, $id);
        $patch = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($patch)) {
            return $this->json($this->json400('Body must be a JSON object'), Response::HTTP_BAD_REQUEST);
        }
        try {
            $this->serving->update($note->getId(), array_intersect_key($patch, array_flip(['enabled', 'auto', 'command', 'slug', 'blocked'])));
        } catch (\InvalidArgumentException $e) {
            return $this->json($this->json400($e->getMessage()), Response::HTTP_BAD_REQUEST);
        } catch (SkillSlugTaken $e) {
            return $this->json($this->json400($e->getMessage()), Response::HTTP_CONFLICT);
        } catch (\OutOfBoundsException $e) {
            return $this->json($this->json400($e->getMessage()), Response::HTTP_NOT_FOUND);
        }
        foreach ($this->rows() as $row) {
            if ($row['note_id'] === $note->getId()) {
                return $this->json(['skill' => $row]);
            }
        }
        throw $this->createNotFoundException('That note is not a skill');
    }

    /** @return list<array> */
    private function rows(): array
    {
        $usage = $this->serves->usage();
        $rows = [];
        foreach ($this->skills->page() as $skill) {
            $use = $usage[$skill['slug']] ?? ['total_30d' => 0, 'last_at' => null, 'last_token_id' => null, 'by_token' => []];
            $rows[] = [
                'kind' => $skill['kind'],
                'status' => $skill['status'],
                'slug' => $skill['slug'],
                'title' => $skill['title'],
                'description' => $skill['description'],
                'short' => $skill['short'] ?? null,
                'body' => $skill['body'],
                'updated_at' => $skill['updated_at'],
                // A system skill the owner unlocked opens as its note.
                'note_id' => $skill['id'] ?? ($skill['unlocked']['note_id'] ?? null),
                'unlockable' => $skill['unlockable'] ?? false,
                'unlocked' => isset($skill['unlocked']),
                'outdated' => $skill['unlocked']['outdated'] ?? false,
                'enabled' => $skill['enabled'] ?? true,
                'auto' => $skill['auto'] ?? true,
                'command' => $skill['command'] ?? true,
                'blocked' => $skill['blocked'] ?? [],
                'usage' => $use,
                'lint' => $this->lint->findings($skill, $use),
                'size_tokens' => (int) ceil(strlen($skill['body']) / 4),
            ];
        }

        return $rows;
    }

    /** @return list<array{id:int, name:string, label:string, display_name:?string, role:string, icon_key:?string, icon:?string, icon_url:?string, icon_url_dark:?string}> */
    private function connections(): array
    {
        $tokens = $this->em->getRepository(ApiToken::class)->findBy(['revokedAt' => null], ['id' => 'ASC']);
        $oauthPrefix = 'oauth: ';
        $label = static fn (string $name): string => str_starts_with($name, $oauthPrefix) ? substr($name, strlen($oauthPrefix)) : $name;

        return array_map(static fn (ApiToken $t) => [
            'id' => $t->getId(),
            'name' => $t->getName(),
            'label' => $label($t->getName()),
            'display_name' => $t->getDisplayName(),
            'role' => $t->getRole(),
            'icon_key' => $t->getIconKey(),
            ...AgentIcons::markFor(
                $t->getIconKey(),
                $t->hasUploadedIcon() ? '/api/tokens/'.$t->getId().'/icon' : null
            ),
        ], $tokens);
    }

    /**
     * Every served skill, one `SKILL.md` per folder, zipped for a vault that
     * wants the lot in one download. Shipped skills are included alongside the
     * user's own notes, since both are things this KB actually serves.
     */
    #[Route('/api/skills/export', methods: ['GET'])]
    public function exportAll(Request $request): Response
    {
        $this->assertSessionAuth($request, 'The skills export');
        $skills = $this->skills->all();
        if ($skills === []) {
            return $this->json($this->json400('Nothing is served'), Response::HTTP_NOT_FOUND);
        }
        $path = @tempnam(sys_get_temp_dir(), 'memex-skills-');
        if ($path === false) {
            throw new \RuntimeException('Could not create export temporary file');
        }
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not open '.$path);
            }
            foreach ($skills as $skill) {
                $zip->addFromString($skill['slug'].'/SKILL.md', $this->package->render($skill, $this->memexVersion()));
            }
            $zip->close();

            return new Response((string) file_get_contents($path), Response::HTTP_OK, [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => 'attachment; filename="memex-skills-'.date('Ymd-His').'.zip"',
            ]);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * One served skill as a `SKILL.md`, whichever kind it is: the same
     * {@see SkillPackage} rendering the zip in {@see self::exportAll()} uses,
     * so a single download and the bulk one are never two formats.
     */
    #[Route('/api/skills/served/{slug}/export', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function exportServed(string $slug, Request $request): Response
    {
        $token = $this->requestToken($request);
        // The token, not just the knowledge base: the charter COMPOSES its brief per
        // connection (CurationCharter::presetFor), so an export that dropped it
        // would hand a token running a custom brief the Standard one and look
        // like the text that connection is actually served. A session caller
        // has no such brief, so it resolves against the owner's own page
        // instead — Download must work on a paused or pending skill, which
        // the served-only find() drops.
        $skill = $token === null ? $this->ownedSkill($slug) : $this->skills->find($slug, $token);
        if ($skill === null) {
            throw $this->createNotFoundException('No skill called '.$slug.' is served here');
        }

        return $this->markdown($this->package->render($skill, $this->memexVersion()), $slug.'.SKILL.md');
    }

    /** Any row the owner can see on the Skills page: shipped, or a note of theirs by slug, whatever its status. */
    private function ownedSkill(string $slug): ?array
    {
        foreach ($this->skills->page() as $row) {
            if ($row['slug'] === $slug && ($row['kind'] === 'shipped' || $row['kind'] === 'note')) {
                return $row;
            }
        }

        return null;
    }

    private function markdown(string $body, string $filename): Response
    {
        return new Response($body, Response::HTTP_OK, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function memexVersion(): string
    {
        return $this->guide->version() ?? 'dev';
    }

    /** One of memex's own skills into a note of the owner's, served in its place. */
    #[Route('/api/skills/system/{slug}/unlock', requirements: ['slug' => '[a-z0-9-]+'], methods: ['POST'])]
    public function unlock(string $slug, Request $request): JsonResponse
    {
        return $this->systemVerb($request, 'Unlocking a system skill', $slug, fn () => $this->unlocking->unlock($slug), Response::HTTP_CREATED);
    }

    /** memex's own text back into the owner's copy, as a new version of it. */
    #[Route('/api/skills/system/{slug}/restore', requirements: ['slug' => '[a-z0-9-]+'], methods: ['POST'])]
    public function restore(string $slug, Request $request): JsonResponse
    {
        return $this->systemVerb($request, 'Restoring a system skill', $slug, fn () => $this->unlocking->restore($slug), Response::HTTP_OK);
    }

    /** @param callable(): Note $verb */
    private function systemVerb(Request $request, string $what, string $slug, callable $verb, int $status): JsonResponse
    {
        $this->assertSessionAuth($request, $what);
        try {
            $verb();
        } catch (SkillNotUnlockable $e) {
            return $this->json($this->json400($e->getMessage()), Response::HTTP_NOT_FOUND);
        } catch (SkillUnlockConflict $e) {
            return $this->json($this->json400($e->getMessage()), Response::HTTP_CONFLICT);
        }
        foreach ($this->rows() as $row) {
            if ($row['kind'] === 'shipped' && $row['slug'] === $slug) {
                return $this->json(['skill' => $row], $status);
            }
        }
        throw $this->createNotFoundException('No skill called '.$slug.' is served here');
    }

    /** Write a catalogue entry into this knowledge base as a note the user owns. */
    #[ReleasesSession]
    #[Route('/api/skills/{slug}/add', requirements: ['slug' => '[a-z0-9-]+'], methods: ['POST'])]
    public function add(string $slug, Request $request): JsonResponse
    {
        if ($this->requestToken($request) !== null) {
            return $this->json($this->json400('Only the operator can add a skill'), Response::HTTP_FORBIDDEN);
        }
        $entry = $this->skills->catalogueEntry($slug);
        if ($entry === null) {
            return $this->json($this->json400('memex ships no skill called '.$slug), Response::HTTP_NOT_FOUND);
        }
        if ($this->skills->find($slug) !== null) {
            return $this->json($this->json400('This knowledge base already has that skill'), Response::HTTP_CONFLICT);
        }

        // The embedding is still made: a skill the user cannot find by
        // searching may as well not be there.
        $note = $this->starters->take($entry, EmbeddingSpend::Metered);

        return $this->json(['note' => $this->noteToArray($note, true)], Response::HTTP_CREATED);
    }
}
