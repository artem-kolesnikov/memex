<?php

declare(strict_types=1);

namespace App\Standalone\Controller;

use App\Controller\ApiController;
use App\Directory\Account;
use App\EventListener\SessionListener;
use App\Security\SignInAuthenticator;
use App\Service\SessionRegistry;
use App\Standalone\FirstRun;
use App\Standalone\OwnerException;
use App\Standalone\OwnerPassword;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The owner's way in: creating the account at first run, signing in with the
 * password, and changing it. Failures come back as codes the sign-in screen
 * words, as the provider sign-in's do.
 */
final class OwnerController extends ApiController
{
    public function __construct(
        private readonly FirstRun $firstRun,
        private readonly OwnerPassword $password,
        private readonly Security $security,
        private readonly SessionRegistry $sessions,
        private readonly RateLimiterFactory $ownerPasswordLimiter,
    ) {
    }

    /** Whether the account exists yet, which decides between first run and signing in, and whether first run takes the code. */
    #[Route('/api/auth/owner', methods: ['GET'])]
    public function state(): JsonResponse
    {
        if ($this->firstRun->owner() !== null) {
            return $this->json(['owner' => true]);
        }

        return $this->json(['owner' => false, 'code' => $this->firstRun->openUntil() === null]);
    }

    #[Route('/api/auth/owner', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $refused = $this->refuse($request, 'ip:'.$request->getClientIp());
        if ($refused !== null) {
            return $refused;
        }
        $body = $request->toArray();
        if ($this->firstRun->owner() !== null) {
            return $this->error('owner_exists', Response::HTTP_CONFLICT);
        }
        $code = trim((string) ($body['code'] ?? ''));
        if ($this->firstRun->openUntil() === null && !$this->firstRun->matches($code)) {
            return $this->error($code === '' ? 'code_needed' : 'code', Response::HTTP_FORBIDDEN);
        }

        try {
            $account = $this->firstRun->createOwner((string) ($body['email'] ?? ''), (string) ($body['password'] ?? ''));
        } catch (OwnerException $e) {
            return $this->error($e->reason, $e->reason === 'owner_exists' ? Response::HTTP_CONFLICT : Response::HTTP_BAD_REQUEST);
        }

        return $this->signIn($account, null);
    }

    #[Route('/api/auth/password', methods: ['POST'])]
    public function password(Request $request): JsonResponse
    {
        $refused = $this->refuse($request, 'ip:'.$request->getClientIp());
        if ($refused !== null) {
            return $refused;
        }
        $body = $request->toArray();
        $owner = $this->firstRun->owner();
        $email = trim((string) ($body['email'] ?? ''));
        $given = (string) ($body['password'] ?? '');
        if ($owner === null || strcasecmp($owner->getEmail(), $email) !== 0 || !$this->password->verify($owner, $given)) {
            return $this->error('credentials', Response::HTTP_UNAUTHORIZED);
        }

        return $this->signIn($owner, SignInAuthenticator::safeNext(\is_string($body['next'] ?? null) ? $body['next'] : null));
    }

    #[Route('/api/me/password', methods: ['PUT'])]
    public function change(Request $request): JsonResponse
    {
        $this->assertSessionAuth($request, 'The password');
        $account = $this->currentAccount();
        $refused = $this->refuse($request, 'account:'.$account->getId());
        if ($refused !== null) {
            return $refused;
        }
        $body = $request->toArray();
        if ($this->password->has($account) && !$this->password->verify($account, (string) ($body['current'] ?? ''))) {
            return $this->error('current', Response::HTTP_FORBIDDEN);
        }

        try {
            $this->password->set($account, (string) ($body['password'] ?? ''));
        } catch (OwnerException $e) {
            return $this->error($e->reason, Response::HTTP_BAD_REQUEST);
        }
        $key = $request->getSession()->get(SessionListener::KEY);
        $ended = \is_string($key) ? $this->sessions->endOthers((int) $account->getId(), $key) : 0;

        return $this->json(['changed' => true, 'signed_out' => $ended]);
    }

    /**
     * A JSON body only: a form on another site can post text/plain that reads
     * as JSON, and a guess counts against its address whatever it holds.
     */
    private function refuse(Request $request, string $key): ?JsonResponse
    {
        if ($request->getContentTypeFormat() !== 'json') {
            return $this->error('json', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }
        $limit = $this->ownerPasswordLimiter->create($key)->consume();
        if (!$limit->isAccepted()) {
            $response = $this->error('throttled', Response::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

            return $response;
        }

        return null;
    }

    private function signIn(Account $account, ?string $next): JsonResponse
    {
        $current = $this->security->getUser();
        if ($current instanceof Account && $current->getId() !== $account->getId()) {
            $this->security->logout(false);
        }
        $this->security->login($account, SignInAuthenticator::class);

        return $this->json(['redirect' => $next ?? $account->path()]);
    }

    private function error(string $code, int $status): JsonResponse
    {
        return $this->json(['error' => $code], $status);
    }
}
