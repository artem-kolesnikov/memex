<?php

declare(strict_types=1);

namespace App\Tests\Database;

use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A request that released its session never opens it again. The case that
 * matters: another tab signs out while a slow request runs, which deletes the
 * session. If the slow request's response wrote the token back, it would
 * recreate that session under a stale id, and its cookie could replace the
 * other tab's fresh sign-in.
 */
final class ReleasedSessionLifecycleTest extends ApiTestCase
{
    public function testASessionEndedDuringASlowRequestStaysEnded(): void
    {
        $this->kb->a->note('Something to export');
        $this->loginAs($this->kb->a);

        $stored = null;
        static::getContainer()->get('event_dispatcher')->addListener(
            KernelEvents::CONTROLLER_ARGUMENTS,
            function (ControllerArgumentsEvent $event) use (&$stored): void {
                $stored = static::getContainer()->getParameter('kernel.cache_dir').'/sessions/'.$event->getRequest()->getSession()->getId().'.mocksess';
                self::assertFileExists($stored, 'the session was saved before the controller ran');
                unlink($stored);
            },
            -100,
        );

        $this->sessionRequest('GET', '/api/export/all');

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertNotNull($stored);
        self::assertFileDoesNotExist($stored, 'the response reopened the session another tab had ended');
    }
}
