<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ReleaseVersion;
use App\Storage\Sqlite;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What an off-box monitor asks in order to know memex is SERVING, not merely
 * powered on.
 *
 * Until 2026-08-25 this returned `{"status":"ok"}` unconditionally. That is a
 * useful answer to "is nginx up and does PHP parse" and a worthless one to the
 * question anybody points a monitor at it to ask, because **the failure that
 * matters is the storage failing while the box stays up** — and against that,
 * a hardcoded `ok` is not a weak check, it is a green light wired to nothing.
 * AUDIT3 §8.3 asked for an external ping on this path; shipping the ping
 * without this would have bought a dashboard that agrees with the code instead
 * of testing it.
 *
 * It checks the STORAGE and nothing else, deliberately. ml-processor is
 * degradable — enrichment stops, the product keeps working — so a health
 * endpoint that went red for it would train whoever watches it to ignore
 * red. Storage is different: without the directory there is no session and
 * no sign-in, and every other alarm in this system is written to a table
 * inside it; without sqlite-vec no vault opens at all. Those are the
 * dependencies whose absence means memex is not serving. No vault of anybody's
 * is opened here: the extension is loaded into a scratch database in memory.
 *
 * PUBLIC (`security.yaml`), so it says as little as it can get away with: a
 * status word, the release version when serving, and an HTTP code, never the
 * exception. An error carries paths
 * and schema, and this route can be read by anybody.
 */
class HealthController extends AbstractController
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.directory_connection')]
        private readonly Connection $directory,
        private readonly ReleaseVersion $release,
    ) {
    }

    #[Route('/api/health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        try {
            // A real query, not `isConnected()` or `getDatabasePlatform()`:
            // both can be satisfied from cached state without the file being
            // opened, which would be a check that looks like one.
            $this->directory->executeQuery('SELECT 1')->fetchOne();

            $scratch = new \SQLite3(':memory:');
            try {
                Sqlite::configure($scratch);
                Sqlite::loadVectors($scratch);
                $scratch->querySingle('SELECT vec_version()');
            } finally {
                $scratch->close();
            }
        } catch (\Throwable) {
            // 503 rather than 500: this is "not available right now", which is
            // what a monitor and a load balancer both need to hear, and it is
            // the code that keeps a retry sensible.
            return $this->json(['status' => 'degraded'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json(['status' => 'ok', 'version' => $this->release->current()]);
    }
}
