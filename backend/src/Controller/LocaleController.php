<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Locales;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Interface languages: read by anyone, installed from control.
 *
 * Public because the sign-in page is part of the SPA and wants to speak the
 * language a returning person chose last time — before there is a session to
 * ask. A translation file holds nothing but the words on the screen, so there
 * is nothing to protect.
 */
class LocaleController extends ApiController
{
    public function __construct(private readonly Locales $locales)
    {
    }

    #[Route('/api/locales', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(['locales' => $this->locales->installed()]);
    }

    #[Route('/api/locales/{code}', methods: ['GET'], requirements: ['code' => '[a-z]{2,3}(-[a-z]{2,4})?'])]
    public function messages(string $code, Request $request): Response
    {
        $json = $this->locales->read($code);
        if ($json === null) {
            return $this->json(['error' => 'That language is not installed'], Response::HTTP_NOT_FOUND);
        }

        $response = new Response($json, Response::HTTP_OK, ['Content-Type' => 'application/json']);
        $response->setEtag(md5($json));
        $response->headers->set('Cache-Control', 'no-cache');
        $response->isNotModified($request);

        return $response;
    }
}
