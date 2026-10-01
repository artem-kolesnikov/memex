<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Attribute\ReleasesSession;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Saves the session before a {@see ReleasesSession} controller runs, and keeps
 * it closed to the end of the request. The firewall has already read the
 * signed-in account by then, and the account stays in memory; what is given up
 * is writing the session back when the request ends, over whatever another
 * request from the same browser wrote to it meanwhile.
 */
final class ReleaseSessionListener
{
    #[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS)]
    public function __invoke(ControllerArgumentsEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getAttributes(ReleasesSession::class) === []) {
            return;
        }

        $request = $event->getRequest();
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
            // Symfony's ContextListener writes the token back at response time
            // whenever this marker is set, which would reopen the session this
            // request has let go of. If another tab signed out and in meanwhile,
            // the reopened session is a stale one, and its cookie would replace
            // the new sign-in. Nothing on these routes changes who is signed in.
            $request->attributes->remove('_security_firewall_run');
        }
    }
}
