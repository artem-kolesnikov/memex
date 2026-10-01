<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Test only: faults asked for with {@see ConnectionProbe::failFromController()}
 * start after the request has authenticated, so they land in the code that
 * serves it rather than in the door.
 */
final class ConnectionProbeArming
{
    #[AsEventListener(event: KernelEvents::CONTROLLER, priority: -4096)]
    public function __invoke(ControllerEvent $event): void
    {
        if ($event->isMainRequest()) {
            ConnectionProbe::armPending();
        }
    }
}
