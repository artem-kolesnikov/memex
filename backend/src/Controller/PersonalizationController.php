<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\VaultSettings;
use App\Service\MemexWriting;
use App\Service\Personalization;
use App\Service\ShippedSkills;
use App\Service\SkillServes;
use App\Service\UserProfiles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Settings › Personalization: the presets for memex-writing. Session-only,
 * reading as well as writing: they decide what every connection is handed,
 * and a connection must never be able to adjust its own instructions.
 */
class PersonalizationController extends ApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SkillServes $serves,
        private readonly UserProfiles $profiles,
    ) {
    }

    #[Route('/api/personalization', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Personalization');

        return $this->json($this->view($this->settings()));
    }

    /**
     * Partial, and refused when the tab sending it was looking at an older
     * version, so two tabs cannot each write their own choice over the other's.
     */
    #[Route('/api/personalization', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'Personalization');
        $settings = $this->settings();
        $patch = $request->toArray();
        $expected = $patch['expected_revision'] ?? null;
        unset($patch['expected_revision']);

        $refusal = $this->em->wrapInTransaction(function () use ($settings, $patch, $expected): ?JsonResponse {
            $this->em->refresh($settings);
            $stored = $settings->getPersonalization();

            if ($expected !== null && $expected !== Personalization::revision($stored)) {
                return $this->json([
                    'error' => 'These settings changed in another tab. The current ones are shown; make your change again.',
                    'conflict' => 'revision',
                    'current' => $this->view($settings),
                ], Response::HTTP_CONFLICT);
            }

            [$next, $reason] = Personalization::merge($stored, $patch);
            if ($reason !== null) {
                return $this->json($this->json400($reason), Response::HTTP_BAD_REQUEST);
            }

            $settings->setPersonalization($next);
            $this->em->flush();

            return null;
        });

        return $refusal ?? $this->json($this->view($settings));
    }

    private function settings(): VaultSettings
    {
        return $this->em->getRepository(VaultSettings::class)->current();
    }

    /** @return array<string, mixed> */
    private function view(VaultSettings $vault): array
    {
        $settings = Personalization::read($vault->getPersonalization());
        $text = MemexWriting::text(['writing' => true] + $settings);

        return [
            'revision' => Personalization::revision($vault->getPersonalization()),
            'settings' => $settings,
            'defaults' => Personalization::defaults(),
            'options' => MemexWriting::options($settings),
            'skill' => [
                'slug' => ShippedSkills::WRITING,
                'text' => $text,
                'loaded_by' => $settings['writing'] === true ? $this->serves->loadedBy(ShippedSkills::WRITING, (string) $text) : [],
            ],
            'profile_paragraph' => $this->profiles->connectParagraph(),
        ];
    }
}
