<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Tests\Support\MockMlResponder;
use App\Tests\Support\ResolvesNoteNumbers;
use App\Tests\Support\Tenant;
use App\Tests\Support\TestData;
use App\Tests\Support\TwoTeamFixture;
use App\Tests\Support\UnbindAroundRequests;
use App\Tests\Support\Vaults;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The same harness as {@see DatabaseTestCase}, driven through HTTP.
 *
 * Tenancy has to hold at the boundary a real client touches, not at the
 * boundary a test finds convenient: a repository method that filters correctly
 * is worth nothing if the controller calling it resolves the id first and
 * checks the vault never. So these tests send requests and read status codes.
 *
 * A request starts and ends with no vault bound ({@see UnbindAroundRequests}):
 * after one, test code enters the vault it reads with {@see in()}, and an
 * entity it held before the request is detached.
 */
abstract class ApiTestCase extends WebTestCase
{
    use ResolvesNoteNumbers;

    /**
     * memex's host in the suite. A relative URI resolves against the last
     * request's host, so a test that has spoken to another names this one outright.
     */
    protected const MEMEX = 'http://localhost';

    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    protected MockMlResponder $ml;
    protected TwoTeamFixture $kb;

    protected function setUp(): void
    {
        parent::setUp();
        // A kernel left booted by the previous test makes createClient() throw.
        // WebTestCase shuts down in tearDown, but disableReboot() plus an
        // explicitly closed EntityManager is enough to leave one behind.
        static::ensureKernelShutdown();
        TestData::fresh();
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->ml = $container->get(MockMlResponder::class);

        // Spend-limiter state lives in a cache pool on the filesystem, not in
        // the data directory. A test that reaches a limit would leave it
        // reached for every test after it.
        $container->get('test.cache.rate_limiter')->clear();
        // The spend counters are in a pool of their own since the limits
        // stopped being yaml constants. Missing this one is invisible until a
        // limit is low enough to reach twice in one suite.
        $container->get('test.cache.spend_limits')->clear();
        // Curation leases live in a pool of their own, on the filesystem for
        // the same reason. A claim left behind by one test would silently empty
        // another test's queue, which is the hardest kind of failure to read.
        $container->get('test.cache.curation_leases')->clear();

        $this->beforeTenants();
        $this->kb = new TwoTeamFixture($container);
    }

    /** Runs on the empty directory, before the fixture's accounts exist. */
    protected function beforeTenants(): void
    {
    }

    /** Make a tenant's vault the one this test reads and writes directly. */
    protected function in(Tenant $tenant): void
    {
        $tenant->enter();
    }

    /** Work in no vault, before a job that walks them all. */
    protected function leave(): void
    {
        Vaults::leave(static::getContainer());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TestData::discard();
    }

    /**
     * A request as one tenant's token. Everything the isolation suite does goes
     * through here, so a test reads as "B asks for A's thing".
     */
    protected function request(string $method, string $uri, string $bearer, ?array $json = null): void
    {
        $this->client->request(
            $method,
            $uri,
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$bearer,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Sign in as a tenant's human owner, for the endpoints that refuse bearer
     * tokens outright (revision restore, limbo purge, inbox batch, token
     * management). The browser keeps the session cookie for later requests.
     *
     * **Through a provider**, because that is the only way any session on
     * memex.tools is established. It goes through `Security::login()`, so the
     * session registry sees it exactly as it sees a real browser.
     */
    protected function loginAs(\App\Tests\Support\Tenant $tenant): void
    {
        // A fresh browser, because that is what signing in is. Without it the
        // cookie jar can still hold a session left by an earlier BEARER request
        // in the same test — the firewall is stateful, so it stored a token
        // there — and App\EventListener\SessionListener rightly answers 401 to
        // a session carrying a user it never saw sign in.
        $this->client->restart();

        $this->client->request('GET', self::MEMEX.'/api/auth/google/start');
        $location = (string) $this->client->getResponse()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        self::assertArrayHasKey('state', $params, 'Sign-in did not start for tenant '.$tenant->label);

        $this->client->request('GET', '/api/auth/google/callback?'.http_build_query([
            'code' => $tenant->signInCode,
            'state' => $params['state'],
        ]));
        // The app's home, matching SocialAuthController::home(). It was `/`
        // until the landing page took that path on 2026-08-30, and `/notes`
        // until every page moved inside the vault's own handle — a destination
        // naming no knowledge base is a 404 on the box.
        //
        // Asserted against the handle this tenant's vault actually holds rather
        // than a pattern: the landing place has been wrong twice, and both
        // times it was a real path that simply belonged to somebody else.
        self::assertSame(
            '/'.$tenant->handle().'/notes',
            $this->client->getResponse()->headers->get('Location'),
            'Fixture sign-in failed for tenant '.$tenant->label
        );
    }

    protected function reviewedRequest(string $method, string $uri, ?array $json = null): void
    {
        $snapshot = function (string $kind, int $id): array {
            $this->sessionRequest('GET', '/api/'.($kind === 'proposal' ? 'proposals' : 'notes').'/'.$id);
            if ($this->httpStatus() !== 200) {
                return [];
            }
            $row = $this->jsonResponse();
            if ($kind === 'note') {
                return ['expected_version' => $row['version']];
            }
            $result = ['expected_revision' => $row['revision'], 'expected_version' => $row['note']['version']];
            if ($row['merge_into'] !== null) {
                $result['expected_merge_version'] = $row['merge_into']['version'];
            }
            return $result;
        };
        if (preg_match('~^/api/(notes|proposals)/(\d+)/approve$~', $uri, $matches)) {
            $json = ($json ?? []) + $snapshot($matches[1] === 'notes' ? 'note' : 'proposal', (int) $matches[2]);
        } elseif ($uri === '/api/inbox/batch' && ($json['action'] ?? null) === 'approve') {
            foreach ($json['items'] ?? [] as $i => $item) {
                if (is_array($item) && in_array($item['kind'] ?? null, ['note', 'proposal'], true) && is_int($item['id'] ?? null)) {
                    $json['items'][$i] = $item + $snapshot($item['kind'], $item['id']);
                }
            }
        }
        $this->sessionRequest($method, $uri, $json);
    }

    /** A session request — no Authorization header, so session-only endpoints accept it. */
    protected function sessionRequest(string $method, string $uri, ?array $json = null): void
    {
        $this->client->request(
            $method,
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * A session request carrying one uploaded file, as a browser sends it.
     *
     * Multipart rather than a JSON field, because that is what the endpoint
     * takes and a test that posts base64 would be testing a route nobody uses.
     */
    protected function sessionUpload(string $method, string $uri, string $field, string $path, string $filename): void
    {
        $this->client->request(
            $method,
            $uri,
            files: [$field => new UploadedFile($path, $filename, null, null, true)],
        );
    }

    /** @return array<string, mixed> */
    protected function jsonResponse(): array
    {
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);
        $decoded = json_decode($content, true);
        self::assertIsArray($decoded, 'Response was not JSON: '.mb_substr($content, 0, 300));

        return $decoded;
    }

    protected function httpStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /** The whole response body, for asserting that a tenant's text does not appear in it. */
    protected function body(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
