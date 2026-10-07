<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SearchPreset;
use App\Entity\Tag;
use App\Service\PresetIcons;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Saved notes-list filters. Session-only: presets are how the owner arranges
 * their own sidebar, and nothing an assistant needs.
 */
class SearchPresetController extends ApiController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/api/presets', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Workspaces');

        $presets = $this->em->getRepository(SearchPreset::class)->findBy([], ['name' => 'ASC']);

        return $this->json(['presets' => $this->toArrays($presets), 'icons' => PresetIcons::catalogue()]);
    }

    #[Route('/api/presets', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Workspaces');

        $count = $this->em->getRepository(SearchPreset::class)->count([]);
        if ($count >= SearchPreset::MAX_PER_TEAM) {
            return $this->json($this->json400('At most '.SearchPreset::MAX_PER_TEAM.' workspaces'), Response::HTTP_BAD_REQUEST);
        }

        $preset = new SearchPreset('');
        $problem = $this->fill($preset, $request);
        if ($problem !== null) {
            return $problem;
        }

        $this->em->persist($preset);

        return $this->flushOr409($preset, Response::HTTP_CREATED);
    }

    #[Route('/api/presets/{id}', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Workspaces');

        $preset = $this->find($id);
        $problem = $this->fill($preset, $request);
        if ($problem !== null) {
            return $problem;
        }

        return $this->flushOr409($preset, Response::HTTP_OK);
    }

    #[Route('/api/presets/{id}', methods: ['DELETE'])]
    public function remove(int $id, Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Workspaces');

        $this->em->remove($this->find($id));
        $this->em->flush();

        return $this->json(['removed' => $id]);
    }

    private function find(int $id): SearchPreset
    {
        $preset = $this->em->getRepository(SearchPreset::class)->find($id);
        if ($preset === null) {
            throw $this->createNotFoundException('No such workspace');
        }

        return $preset;
    }

    /** Read the body onto the preset, or answer why it cannot be. */
    private function fill(SearchPreset $preset, Request $request): ?JsonResponse
    {
        try {
            $data = $request->toArray();
        } catch (JsonException $e) {
            return $this->json($this->json400('Request body is not valid JSON'), Response::HTTP_BAD_REQUEST);
        }

        $name = trim(preg_replace('/\s+/u', ' ', (string) ($data['name'] ?? '')) ?? '');
        if ($name === '') {
            return $this->json($this->json400('A workspace needs a name'), Response::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($name) > SearchPreset::MAX_NAME) {
            return $this->json($this->json400('The name must be at most '.SearchPreset::MAX_NAME.' characters'), Response::HTTP_BAD_REQUEST);
        }

        $icon = (string) ($data['icon'] ?? PresetIcons::DEFAULT);
        if (!PresetIcons::isKnown($icon)) {
            return $this->json($this->json400('Unknown icon'), Response::HTTP_BAD_REQUEST);
        }

        $query = trim((string) ($data['q'] ?? ''));
        if (mb_strlen($query) > SearchPreset::MAX_QUERY) {
            return $this->json($this->json400('Search terms must be at most '.SearchPreset::MAX_QUERY.' characters'), Response::HTTP_BAD_REQUEST);
        }

        $status = (string) ($data['status'] ?? '');
        if ($status !== '' && !in_array($status, SearchPreset::STATUSES, true)) {
            return $this->json($this->json400('Unknown status'), Response::HTTP_BAD_REQUEST);
        }

        $rawTags = $data['tags'] ?? [];
        if (!is_array($rawTags) || !array_is_list($rawTags)) {
            return $this->json($this->json400('tags must be a list of tag ids'), Response::HTTP_BAD_REQUEST);
        }
        $tagIds = [];
        foreach ($rawTags as $raw) {
            if (!is_int($raw) || $raw <= 0) {
                return $this->json($this->json400('tags must be a list of tag ids'), Response::HTTP_BAD_REQUEST);
            }
            $tagIds[$raw] = $raw;
        }
        $tagIds = array_values($tagIds);
        if ($tagIds !== []) {
            $known = $this->em->getRepository(Tag::class)->findBy(['id' => $tagIds]);
            if (count($known) !== count($tagIds)) {
                return $this->json($this->json400('No such tag'), Response::HTTP_BAD_REQUEST);
            }
        }

        $addedBy = $data['added_by'] ?? null;
        if ($addedBy !== null && (!is_int($addedBy) || $addedBy <= 0)) {
            return $this->json($this->json400('added_by must be a connection id'), Response::HTTP_BAD_REQUEST);
        }

        $preset->setName($name);
        $preset->setIcon($icon);
        $preset->setCriteria($query, $tagIds, $status, $addedBy);
        if ($preset->isEmpty()) {
            return $this->json($this->json400('A workspace needs at least one filter'), Response::HTTP_BAD_REQUEST);
        }

        return null;
    }

    private function flushOr409(SearchPreset $preset, int $status): JsonResponse
    {
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->json($this->json400('A workspace with that name already exists'), Response::HTTP_CONFLICT);
        }

        return $this->json(['preset' => $this->toArrays([$preset])[0]], $status);
    }

    /**
     * @param list<SearchPreset> $presets
     * @return list<array<string, mixed>>
     */
    private function toArrays(array $presets): array
    {
        $wanted = [];
        foreach ($presets as $preset) {
            foreach ($preset->getTagIds() as $id) {
                $wanted[$id] = $id;
            }
        }
        $names = [];
        if ($wanted !== []) {
            foreach ($this->em->getRepository(Tag::class)->findBy(['id' => array_values($wanted)]) as $tag) {
                $names[$tag->getId()] = $tag->getName();
            }
        }

        $out = [];
        foreach ($presets as $preset) {
            $tags = [];
            foreach ($preset->getTagIds() as $id) {
                if (isset($names[$id])) {
                    $tags[] = ['id' => $id, 'name' => $names[$id]];
                }
            }
            $out[] = [
                'id' => $preset->getId(),
                'name' => $preset->getName(),
                'icon' => $preset->getIcon(),
                'icon_class' => PresetIcons::classesFor($preset->getIcon()),
                'q' => $preset->getQuery(),
                'tags' => $tags,
                'status' => $preset->getStatus(),
                'added_by' => $preset->getAddedBy(),
                'updated_at' => $preset->getUpdatedAt()->format(DATE_ATOM),
            ];
        }

        return $out;
    }
}
