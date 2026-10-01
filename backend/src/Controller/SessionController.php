<?php

declare(strict_types=1);

namespace App\Controller;

use App\EventListener\SessionListener;
use App\Service\SessionRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Where you are signed in, and how to stop being signed in there.
 *
 * memex had no answer to either question: a session was a file on the box that
 * nobody could enumerate, so somebody who signed in on a machine they do not
 * own had no way to undo it and no way to find out. That is the gap this
 * closes, and it is deliberately about BROWSERS only — connected assistants
 * hold bearer tokens, they are listed and switched off on the Connections
 * screen, and a control called "sign out everywhere" that silently
 * disconnected every assistant would be a worse version of one that already
 * exists.
 *
 * Session-only, like every other account-shaped route here: a connected
 * assistant authenticates as its owner, and "may read and file notes" must not
 * quietly include "may sign its owner out of everything".
 */
class SessionController extends ApiController
{
    public function __construct(private readonly SessionRegistry $registry)
    {
    }

    /** Every browser signed in to this account, most recently used first. */
    #[Route('/api/me/sessions', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $account = $this->currentAccount();
        $this->assertSessionAuth($request, 'Your sessions');

        return $this->json([
            'sessions' => $this->registry->listFor((int) $account->getId(), $this->currentKey($request)),
        ]);
    }

    /**
     * End one of them. It stops working on that browser's next request.
     *
     * The session making the request is refused, not because ending it would
     * break anything, but because it already has a control that says what it
     * does: signing out. A row in a list of other machines that quietly logs
     * you out of this one is a surprise, and this screen exists to remove
     * surprises.
     */
    #[Route('/api/me/sessions/{id}', requirements: ['id' => '[0-9a-f]{16}'], methods: ['DELETE'])]
    public function end(string $id, Request $request): JsonResponse
    {
        $account = $this->currentAccount();
        $this->assertSessionAuth($request, 'Your sessions');

        $current = $this->currentKey($request);
        $sessions = $this->registry->listFor((int) $account->getId(), $current);
        foreach ($sessions as $session) {
            if ($session['id'] === $id && $session['current'] === true) {
                return $this->json(
                    $this->json400('This is the browser you are using. Sign out to end it.'),
                    Response::HTTP_CONFLICT
                );
            }
        }

        if (!$this->registry->end($id, (int) $account->getId())) {
            return $this->json($this->json400('That session has already ended'), Response::HTTP_NOT_FOUND);
        }

        return $this->json(['ended' => 1]);
    }

    /**
     * Sign out of every browser except this one — the control somebody reaches
     * for after leaving a machine behind, when they cannot say which row it is.
     *
     * It spares the caller on purpose. Ending everything including yourself
     * would drop you at the sign-in screen with no way to tell whether it
     * worked, and signing out afterwards is one more click for anybody who
     * wants it.
     */
    #[Route('/api/me/sessions/end-others', methods: ['POST'])]
    public function endOthers(Request $request): JsonResponse
    {
        $account = $this->currentAccount();
        $this->assertSessionAuth($request, 'Your sessions');

        $current = $this->currentKey($request);
        if ($current === null) {
            // Nothing authenticates a browser here except the key, so without
            // one we cannot tell which session to spare.
            return $this->json($this->json400('Sign in again to use this'), Response::HTTP_CONFLICT);
        }

        return $this->json(['ended' => $this->registry->endOthers((int) $account->getId(), $current)]);
    }

    /** This browser's registry key, which is what marks its own row. */
    private function currentKey(Request $request): ?string
    {
        if (!$request->hasSession()) {
            return null;
        }
        $key = $request->getSession()->get(SessionListener::KEY);

        return is_string($key) ? $key : null;
    }
}
