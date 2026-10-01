<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * A spend limit, as an answer the SPA can read (C-1, 2026-08-22).
 *
 * `SpendLimiter` writes a sentence for a person — what happened and when it
 * will work again — and without this listener that sentence never arrives:
 * Symfony renders an uncaught HttpException as an HTML error page, and
 * `client.ts` falls back to "HTTP 429" when the body will not parse. The user
 * would be told a number instead of a reason, for a refusal that is not their
 * mistake.
 *
 * Same shape and same prefix check as {@see WriteConflictListener} — the two
 * are the only places where a failed request has something worth saying.
 *
 * `Retry-After` is carried over from the exception rather than recomputed: it
 * is the machine-readable half of the same answer, and a scripted client
 * should be able to obey it without reading English.
 */
#[AsEventListener(event: ExceptionEvent::class)]
final class SpendLimitListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof TooManyRequestsHttpException) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if (!str_starts_with($path, '/api') && !str_starts_with($path, '/mcp')) {
            return;
        }

        $event->setResponse(new JsonResponse(
            [
                'error' => $exception->getMessage(),
                // So a client can tell "you have spent enough for now" from
                // every other 429 it might meet in front of this box.
                'limited' => true,
            ],
            Response::HTTP_TOO_MANY_REQUESTS,
            $exception->getHeaders(),
        ));
    }
}
