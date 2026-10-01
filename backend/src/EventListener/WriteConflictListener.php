<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\ClientMessage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * A write that lost a race is a 409, not a 500.
 *
 * `notes.version` makes Doctrine put `WHERE version = ?` on every UPDATE it
 * issues, so the writer who loses a race gets an OptimisticLockException
 * instead of silently winning. Without this listener
 * that surfaces as an Internal Server Error — technically a refusal, but one
 * that tells the caller nothing and reads like a bug in memex rather than a
 * fact about their note.
 *
 * 409 with the note's id, so a client can reload and show what actually
 * happened. The API contract this completes is the one `expected_version` on
 * PUT /api/notes/{id} starts: that check catches the long window, where an
 * editor sat open while a curator token rewrote the note, and this catches the
 * narrow one, where two writes collide inside the same instant on a path that
 * never sent a precondition at all — including MCP, and including paths not
 * written yet.
 *
 * Deliberately global rather than a try/catch per controller. A write path
 * that forgets to catch it is exactly the path where a 500 would be most
 * confusing, and there is no useful per-endpoint variation in the answer.
 */
#[AsEventListener(event: ExceptionEvent::class)]
final class WriteConflictListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $message = ClientMessage::conflict($event->getThrowable());
        if ($message === null) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if (!str_starts_with($path, '/api') && !str_starts_with($path, '/mcp')) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'error' => $message,
            'conflict' => true,
        ], Response::HTTP_CONFLICT));
    }
}
