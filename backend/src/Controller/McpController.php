<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\McpServer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** MCP over HTTP: one JSON-RPC request per POST, answered by {@see McpServer}. */
class McpController extends ApiController
{
    public function __construct(private readonly McpServer $server)
    {
    }

    #[Route('/mcp', methods: ['POST'])]
    public function handle(Request $request): JsonResponse
    {
        $token = $this->requestToken($request);
        if ($token === null) {
            return new JsonResponse(['error' => 'The MCP endpoint requires bearer-token auth'], Response::HTTP_UNAUTHORIZED);
        }

        $reply = $this->server->reply($request->getContent(), $token, $request->getSchemeAndHttpHost());

        return $reply === null ? new JsonResponse(null, Response::HTTP_ACCEPTED) : new JsonResponse($reply);
    }

    #[Route('/mcp', methods: ['GET', 'DELETE'])]
    public function streamNotSupported(): JsonResponse
    {
        return new JsonResponse(['error' => 'This server does not offer an SSE stream'], Response::HTTP_METHOD_NOT_ALLOWED);
    }
}
