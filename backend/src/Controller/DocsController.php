<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ShippedText;
use App\Service\WelcomeNotes;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Docs page: short how-tos for people, from `config/docs/memex-docs.md`.
 * Assistants read the full guide, the `memex-guide` skill, instead; the two
 * cover the same ground and change in the same PR. Rendered for this server
 * like the welcome notes, so links open the reader's own pages.
 */
class DocsController extends ApiController
{
    public function __construct(
        private readonly ShippedText $text,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/config/docs/memex-docs.md')]
        private readonly string $docsFile,
    ) {
    }

    #[Route('/api/docs', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        $account = $this->currentAccount();
        $this->assertSessionAuth($request, 'The docs');

        $raw = is_readable($this->docsFile) ? file_get_contents($this->docsFile) : false;
        if ($raw === false) {
            $this->logger->error('The docs could not be read', ['file' => $this->docsFile]);
            throw new ServiceUnavailableHttpException(null, 'The docs could not be read');
        }

        return $this->json(['body' => $this->text->render(str_replace(WelcomeNotes::BASE, '/'.$account->getHandle(), $raw))]);
    }
}
