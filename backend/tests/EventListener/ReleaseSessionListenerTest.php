<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Attribute\ReleasesSession;
use App\Controller\CaptureController;
use App\Controller\ExportController;
use App\Controller\ImportController;
use App\Controller\InboxController;
use App\Controller\NoteController;
use App\Controller\ProposalController;
use App\Controller\RevisionController;
use App\Controller\SkillController;
use App\EventListener\ReleaseSessionListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ReleaseSessionListenerTest extends TestCase
{
    public function testTheSessionIsSavedBeforeAMarkedController(): void
    {
        $request = null;
        $session = $this->run_([new ReleaseSessionFixture(), 'marked'], $request);
        self::assertFalse($session->isStarted());
        self::assertFalse($request->attributes->has('_security_firewall_run'), 'the token is not written back at response time');
        self::assertSame('kept', $session->get('signed_in'), 'saving keeps what the session held');
    }

    public function testAnUnmarkedControllerKeepsItsSessionOpen(): void
    {
        $request = null;
        self::assertTrue($this->run_([new ReleaseSessionFixture(), 'unmarked'], $request)->isStarted());
        self::assertTrue($request->attributes->has('_security_firewall_run'));
    }

    /**
     * The routes that run long: they decide held items and embed what they
     * apply, write a note and describe it, call a provider, read an upload, or
     * build a whole-vault archive.
     */
    public function testTheLongRoutesAreMarked(): void
    {
        $routes = [
            [InboxController::class, 'batch'],
            [NoteController::class, 'approve'],
            [NoteController::class, 'reject'],
            [ProposalController::class, 'approve'],
            [ProposalController::class, 'reject'],
            [CaptureController::class, 'analyze'],
            [CaptureController::class, 'upload'],
            [ImportController::class, 'import'],
            [ExportController::class, 'exportAll'],
            [ExportController::class, 'exportSet'],
            [NoteController::class, 'create'],
            [NoteController::class, 'update'],
            [RevisionController::class, 'restore'],
            [SkillController::class, 'import'],
            [SkillController::class, 'add'],
        ];
        foreach ($routes as [$class, $method]) {
            self::assertCount(1, (new \ReflectionMethod($class, $method))->getAttributes(ReleasesSession::class), $class.'::'.$method);
        }
    }

    private function run_(callable $controller, ?Request &$request): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('signed_in', 'kept');
        $request = new Request();
        $request->setSession($session);
        $request->attributes->set('_security_firewall_run', '_security_main');

        $event = new ControllerArgumentsEvent(
            $this->createMock(HttpKernelInterface::class),
            $controller,
            [],
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
        (new ReleaseSessionListener())($event);

        return $session;
    }
}

final class ReleaseSessionFixture
{
    #[ReleasesSession]
    public function marked(): void
    {
    }

    public function unmarked(): void
    {
    }
}
