<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\StorageLimitExceeded;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

#[AsEventListener(event: ExceptionEvent::class)]
final class StorageLimitListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $error = StorageLimitExceeded::fromThrowable($event->getThrowable());
        if ($error !== null) {
            $event->setResponse(new JsonResponse($error->payload(), 413));
        }
    }
}
