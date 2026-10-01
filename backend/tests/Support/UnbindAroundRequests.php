<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Storage\VaultContext;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Test only: a request starts with no vault bound, whatever test code had
 * entered, and ends by forgetting whose vault it was and who signed in, so
 * test code must enter a vault by name before touching one.
 */
final class UnbindAroundRequests
{
    public function __construct(
        private readonly VaultContext $context,
        private readonly TokenStorageInterface $tokens,
        private readonly ManagerRegistry $doctrine,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4096)]
    public function beforeRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->unbind();
        }
    }

    /**
     * The test client terminates the kernel before it streams a body, so a
     * streamed response is unbound once it has been sent.
     */
    #[AsEventListener(event: KernelEvents::TERMINATE, priority: -2048)]
    public function afterRequest(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $response = $event->getResponse();
        if ($response instanceof StreamedResponse && ($stream = $response->getCallback()) !== null) {
            $response->setCallback(function () use ($stream): void {
                try {
                    $stream();
                } finally {
                    $this->forget();
                }
            });

            return;
        }
        $this->forget();
    }

    private function forget(): void
    {
        $this->tokens->setToken(null);
        $this->unbind();
    }

    private function unbind(): void
    {
        if ($this->context->resolve() === null) {
            return;
        }
        $manager = $this->doctrine->getManager('vault');
        $manager->isOpen() ? $manager->clear() : $this->doctrine->resetManager('vault');
        $this->context->reset();
    }
}
