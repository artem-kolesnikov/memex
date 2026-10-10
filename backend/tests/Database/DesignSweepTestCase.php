<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Tests\Support\ConnectionProbe;
use App\Tests\Support\DrivesOAuthFlow;
use App\Tests\Support\Tenant;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\RouterInterface;

/**
 * Every route memex answers, and every MCP tool, called by one account: from
 * its browser session and from its connections. The design tests read what
 * came back and which files were opened; they do not hand-pick routes, so a
 * route added tomorrow is swept too.
 *
 * Two symmetric accounts with the same note numbers, connections and settings,
 * so a request that reached the wrong vault would find something there.
 * Directory rows are numbered from a base per table that nothing else in a
 * response comes near, so a directory id can be recognised wherever it lands.
 */
abstract class DesignSweepTestCase extends ApiTestCase
{
    use DrivesOAuthFlow;

    private const ID_BASES = [
        'accounts' => 471100000,
        'identities' => 472200000,
        'user_sessions' => 473300000,
        'oauth_clients' => 474400000,
    ];
    private const ID_SPAN = 100000;

    /** Routes that end the principal the sweep is using, in the order they are sent last. */
    private const ENDINGS = ['DELETE /api/me', 'POST /api/logout', 'GET /api/logout'];

    /**
     * What the fixture left in each vault, by name, read back after it ran.
     *
     * @var array<string, int|string>
     */
    protected array $fixture = [];

    protected function beforeTenants(): void
    {
        $directory = static::getContainer()->get('doctrine.dbal.directory_connection');
        foreach (static::idBases() as $table => $base) {
            $directory->executeStatement('DELETE FROM sqlite_sequence WHERE name = ?', [$table]);
            $directory->insert('sqlite_sequence', ['name' => $table, 'seq' => $base]);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        ConnectionProbe::stop();
        foreach ([$this->kb->a, $this->kb->b] as $tenant) {
            $this->populate($tenant);
        }
        $this->populateServer();
        $this->readFixture($this->kb->a);
        $this->leave();
        $this->client->restart();
        ConnectionProbe::takeOpened();
    }

    protected function tearDown(): void
    {
        ConnectionProbe::stop();
        ConnectionProbe::takeOpened();
        parent::tearDown();
    }

    /**
     * Send every request of the sweep as $tenant and hand each exchange to
     * $observe with the files it opened. $before runs ahead of each request.
     *
     * @param \Closure(string, Response, list<array{connection: string, file: string}>): void $observe
     * @param null|\Closure(string): void                                                  $before
     */
    protected function sweep(Tenant $tenant, \Closure $observe, ?\Closure $before = null): void
    {
        $send = function (string $label, \Closure $request) use ($observe, $before): void {
            ConnectionProbe::takeOpened();
            if ($before !== null) {
                $before($label);
            }
            $response = $this->exchange($request);
            ConnectionProbe::stop();
            $observe($label, $response, ConnectionProbe::takeOpened());
        };

        $this->client->restart();
        foreach ($this->mcpCalls() as $label => $body) {
            foreach (['agent' => $tenant->agentBearer, 'curator' => $tenant->curatorBearer] as $role => $bearer) {
                $send("$role $label", fn () => $this->request('POST', '/mcp', $bearer, $body));
            }
        }
        foreach (['agent' => $tenant->agentBearer, 'curator' => $tenant->curatorBearer] as $role => $bearer) {
            foreach ($this->routeRequests() as [$label, $method, $uri, $json, $files]) {
                $send("$role $label", fn () => $this->send($method, $uri, $json, $files, $bearer));
            }
        }

        $this->loginAs($tenant);
        ConnectionProbe::takeOpened();
        foreach ($this->routeRequests() as [$label, $method, $uri, $json, $files]) {
            $send("session $label", fn () => $this->send($method, $uri, $json, $files, null));
        }
    }

    /**
     * Every route but control's, each method once, with its parameters filled
     * from the fixture and a body that does real work where one is known.
     *
     * @return list<array{string, string, string, ?array<mixed>, array<string, UploadedFile>}>
     */
    protected function routeRequests(): array
    {
        $reads = [];
        $writes = [];
        $endings = [];
        foreach (static::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            $path = $route->getPath();
            if ($name === '_preview_error' || !$this->swept($path)) {
                continue;
            }
            foreach ($route->getMethods() ?: ['GET'] as $method) {
                $key = "$method $path";
                $uri = preg_replace_callback('/\{(\w+)\}/', fn (array $m) => $this->parameter($key, $m[1]), $path).$this->query($key);
                $request = [$key, $method, $uri, $this->bodyFor($key), $this->files($key)];
                if (in_array($key, self::ENDINGS, true)) {
                    $endings[array_search($key, self::ENDINGS, true)] = $request;
                } elseif ($method === 'GET' || $method === 'OPTIONS') {
                    $reads[] = $request;
                } else {
                    $writes[] = $request;
                }
            }
        }
        ksort($endings);

        return [...$reads, ...$writes, ...$endings];
    }

    /**
     * Directory tables whose ids the sweep recognises, each numbered from its own base.
     *
     * @return array<string, int>
     */
    protected static function idBases(): array
    {
        return self::ID_BASES;
    }

    /** Whether the sweep sends requests to $path. */
    protected function swept(string $path): bool
    {
        return true;
    }

    /**
     * Every MCP method a client sends, and every tool the curator is offered,
     * each called with its required arguments filled from the fixture.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function mcpCalls(): array
    {
        $this->client->restart();
        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        $tools = $this->jsonResponse()['result']['tools'] ?? [];
        self::assertNotSame([], $tools, 'tools/list returned no tools, so the sweep would call none');

        $calls = [
            'initialize' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'probe', 'version' => '1']]],
            'tools/list' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            'resources/list' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list'],
            'prompts/list' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'prompts/list'],
        ];
        foreach ($tools as $tool) {
            $arguments = $this->toolArguments($tool['name'], $tool['inputSchema'] ?? []);
            $calls['tools/call '.$tool['name']] = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool['name'], 'arguments' => $arguments === [] ? new \stdClass() : $arguments]];
        }

        return $calls;
    }

    /**
     * Which directory table a number in $text would be an id of.
     *
     * @return list<string> "table id" for each directory id found
     */
    protected static function directoryIdsIn(string $text): array
    {
        preg_match_all('/(?<!\d)\d{9}(?!\d)/', $text, $matches);
        $found = [];
        foreach ($matches[0] as $number) {
            foreach (static::idBases() as $table => $base) {
                if ((int) $number > $base && (int) $number < $base + self::ID_SPAN) {
                    $found[] = "$table $number";
                }
            }
        }

        return array_values(array_unique($found));
    }

    /** What a client receives, headers and body, with a zip's files read out. */
    protected static function received(Response $response): string
    {
        $headers = $response->headers->allPreserveCase();
        unset($headers['Set-Cookie']);
        $body = (string) $response->getContent();
        if (str_starts_with($body, "PK\x03\x04")) {
            $path = sys_get_temp_dir().'/memex-sweep-'.bin2hex(random_bytes(4)).'.zip';
            file_put_contents($path, $body);
            $zip = new \ZipArchive();
            if ($zip->open($path) === true) {
                for ($i = 0; $i < $zip->numFiles; ++$i) {
                    $body .= "\n".$zip->getNameIndex($i)."\n".$zip->getFromIndex($i);
                }
                $zip->close();
            }
            unlink($path);
        }

        return json_encode($headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n".$body;
    }

    /**
     * The response a client receives, with a streamed body as it was sent. A
     * stream that breaks partway has sent its headers and what it had
     * streamed; the test client throws instead, so that is rebuilt here.
     */
    private function exchange(\Closure $request): Response
    {
        $level = ob_get_level();
        try {
            $request();
        } catch (\Throwable $e) {
            $streamed = $this->client->getResponse();
            if (!$streamed instanceof StreamedResponse) {
                throw $e;
            }
            $sent = '';
            while (ob_get_level() > $level) {
                $sent = ob_get_clean().$sent;
            }

            return new Response($sent, $streamed->getStatusCode(), $streamed->headers->all());
        }
        $response = $this->client->getResponse();
        if ($response->getContent() !== false) {
            return $response;
        }

        return new Response($this->client->getInternalResponse()->getContent(), $response->getStatusCode(), $response->headers->all());
    }

    private function send(string $method, string $uri, ?array $json, array $files, ?string $bearer): void
    {
        $server = $bearer === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer];
        if ($files !== []) {
            $this->client->request($method, $uri, $json ?? [], $files, $server);

            return;
        }
        $this->client->request(
            $method,
            $uri,
            server: $server + ['CONTENT_TYPE' => 'application/json'],
            content: $json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR),
        );
    }

    private function parameter(string $route, string $name): string
    {
        $f = $this->fixture;

        return (string) match (true) {
            $name === 'revisionId' => $f['revision'],
            $name === 'slug' => 'skill-handoff',
            $name === 'name' => 'design',
            $name === 'provider' => 'google',
            $name === 'endpoint' => 'token',
            $name === 'suffix' => 'mcp',
            $name === 'code' => 'en',
            str_contains($route, '/api/me/identities/') => $f['identity'],
            str_contains($route, '/api/me/sessions/') => $f['other_session'],
            str_contains($route, '/api/tokens/{id}') => $f['spare_connection'],
            str_contains($route, '/api/skills/{id}') => $f['skill'],
            str_contains($route, '/api/curation/presets/{id}') => $f['curation_profile'],
            str_contains($route, '/api/notes/{id}/revisions'), str_contains($route, '/api/notes/{id}/flag') => $f['revised'],
            $route === 'PUT /api/notes/{id}' => 4,
            $route === 'DELETE /api/notes/{id}' => 5,
            $route === 'POST /api/notes/{id}/propose-merge' => 6,
            $route === 'POST /api/notes/{id}/approve' => $f['pending'],
            $route === 'POST /api/notes/{id}/reject' => $f['pending_other'],
            str_starts_with($route, 'POST /api/deleted/'), str_starts_with($route, 'DELETE /api/deleted/') => $f['limbo'],
            $route === 'POST /api/proposals/{id}/reject' => $f['delete_proposal'],
            default => 1,
        };
    }

    private function query(string $route): string
    {
        return match ($route) {
            'GET /api/export' => '?ids=1,2',
            'GET /oauth/authorize', 'POST /oauth/authorize' => '?'.http_build_query($this->fixture['authorize']),
            default => '',
        };
    }

    /** @return ?array<mixed> */
    protected function bodyFor(string $route): ?array
    {
        $f = $this->fixture;

        return match ($route) {
            'POST /api/notes' => ['title' => 'Probe note', 'body_md' => 'Written by the sweep. See [[Alpha]].', 'tags' => ['probe']],
            'PUT /api/notes/{id}' => ['title' => 'Delta, renamed by the sweep', 'discard_proposals' => true],
            'DELETE /api/notes/{id}' => ['discard_proposals' => true],
            'POST /api/notes/{id}/approve' => ['expected_version' => $f['pending_version'], 'comment' => 'Approved by the sweep.'],
            'POST /api/notes/{id}/reject', 'POST /api/proposals/{id}/reject' => ['comment' => 'Rejected by the sweep.'],
            'POST /api/proposals/{id}/approve' => ['expected_revision' => $f['edit_revision'], 'expected_version' => $f['edited_version']],
            'POST /api/notes/{id}/propose-merge' => ['into_note_id' => 5, 'comment' => 'Merge from the sweep.'],
            'POST /api/notes/{id}/flag' => ['comment' => 'Flagged by the sweep.'],
            'POST /api/presets' => ['name' => 'Probe filter', 'q' => 'Beta'],
            'PATCH /api/presets/{id}' => ['name' => 'Probe filter, renamed', 'q' => 'Gamma'],
            'POST /api/inbox/batch' => ['action' => 'reject', 'items' => [['kind' => 'proposal', 'id' => $f['merge_proposal']]]],
            'POST /api/curation/presets' => ['name' => 'Probe profile'],
            'PUT /api/curation/presets/{id}' => ['name' => 'Probe profile, renamed'],
            'POST /api/curation/preview' => [],
            'PUT /api/curation/wiring/{id}' => ['preset_id' => 1],
            'PATCH /api/skills/{id}' => ['enabled' => true],
            'POST /api/analyze' => ['title' => 'Alpha', 'body_md' => 'Alpha body.'],
            'PUT /api/settings/ai' => ['enabled' => true, 'credential_id' => 1, 'model' => 'gpt-4o-mini'],
            'PUT /api/settings/ai/sections' => ['embed_credential_id' => 1],
            'POST /api/settings/ai/keys' => ['provider' => 'openai', 'name' => 'Probe key', 'api_key' => 'sk-a-valid-key'],
            'POST /api/tokens' => ['name' => 'Probe connection'],
            'PATCH /api/tokens/{id}' => ['display_name' => 'Probe', 'description' => 'A connection the sweep renamed.'],
            'PATCH /api/tokens/{id}/role' => ['role' => 'curator'],
            'PATCH /api/me' => ['name' => 'Probe Owner'],
            'PATCH /api/me/memex' => ['name' => 'Probe memex'],
            'PATCH /api/me/appearance' => ['accent' => ['light' => '#3366cc']],
            'PATCH /api/me/map' => ['view' => '3d'],
            'PATCH /api/me/locale' => ['locale' => 'en'],
            'PATCH /api/personalization' => ['format' => 'prose'],
            'DELETE /api/me' => ['confirm_email' => $f['email']],
            'POST /oauth/register' => ['client_name' => 'Probe client', 'redirect_uris' => ['https://probe.example.test/cb']],
            default => null,
        };
    }

    /** @return array<string, UploadedFile> */
    private function files(string $route): array
    {
        $file = fn (string $name, string $content) => new UploadedFile($this->scratch($name, $content), $name, null, null, true);

        return match ($route) {
            'POST /api/upload' => ['files' => [$file('probe.md', "---\ntags: [probe]\n---\n# Uploaded probe\n\nBody.\n")]],
            'POST /api/import' => ['archive' => $file('probe.zip', $this->zip(['Imported probe.md' => "Imported body.\n"]))],
            'POST /api/skills/import' => ['files' => [$file('probe-skill.md', "---\nname: probe-skill\ndescription: A skill the sweep imports.\n---\n# Probe skill\n\nDo nothing.\n")]],
            'POST /api/me/icon', 'POST /api/tokens/{id}/icon' => ['icon' => $file('probe.png', self::png())],
            default => [],
        };
    }

    /**
     * What each tool is called with: its required arguments, filled by name or
     * type, with the notes chosen so the tools and the routes do not undo
     * each other's work.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function toolArguments(string $tool, array $schema): array
    {
        $given = match ($tool) {
            'propose' => ['note_id' => 2, 'summary' => 'Beta, described by the sweep.'],
            'propose_delete' => ['note_id' => 2, 'reason' => 'The sweep proposes it.'],
            'propose_merge' => ['note_id' => 2, 'into_note_id' => 5, 'comment' => 'The sweep proposes it.'],
            'resolve_curation_flag' => ['note_id' => $this->fixture['revised'], 'resolution' => 'The sweep resolved it.'],
            'get_skill' => ['slug' => 'memex-curation'],
            'last_curated' => ['note_ids' => [2, 3]],
            'get' => ['id' => 2],
            'blast_radius' => ['note_ids' => [2]],
            default => [],
        };
        foreach ($schema['properties'] ?? [] as $property => $spec) {
            if (array_key_exists($property, $given) || !in_array($property, $schema['required'] ?? [], true)) {
                continue;
            }
            $given[$property] = $spec['enum'][0] ?? match ($spec['type'] ?? null) {
                'integer' => 2,
                'boolean' => false,
                'array' => [],
                default => 'Probe text from the sweep.',
            };
        }

        return $given;
    }

    /**
     * Notes 1–6 verified (3 revised by its owner and flagged), two pending, one
     * in limbo, a served skill; held proposals from the agent: an edit of 1, a
     * delete of 6, a merge of 4 into 2; a spare connection, a saved filter, a
     * curation profile, an AI key, a journal line, and a token minted by the
     * OAuth front door.
     */
    protected function populate(Tenant $tenant): void
    {
        foreach (['Alpha' => 'Alpha links [[Beta]].', 'Beta' => 'Beta body.', 'Gamma' => 'Gamma body.', 'Delta' => 'Delta body.', 'Epsilon' => 'Epsilon body.', 'Zeta' => 'Zeta body.'] as $title => $body) {
            $tenant->note($title, $body, ['design']);
        }
        $tenant->connection('spare-'.strtolower($tenant->label), 'mxt_spare_'.strtolower($tenant->label));

        $mcp = function (string $bearer, string $tool, array $arguments) use ($tenant): void {
            $this->request('POST', '/mcp', $bearer, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]);
            $result = $this->jsonResponse()['result'] ?? [];
            self::assertNotTrue($result['isError'] ?? false, "fixture $tool for {$tenant->label} failed: ".json_encode($result['structuredContent']['error'] ?? $result));
        };
        $this->client->restart();
        $mcp($tenant->agentBearer, 'propose', ['title' => 'Eta', 'body_md' => 'Eta, pending.']);
        $mcp($tenant->agentBearer, 'propose', ['title' => 'Iota', 'body_md' => 'Iota, pending.']);
        $mcp($tenant->agentBearer, 'propose', ['note_id' => 1, 'body_md' => 'Alpha, as the agent would have it. [[Beta]]', 'comment' => 'Agent edit.']);
        $mcp($tenant->agentBearer, 'propose_delete', ['note_id' => 6, 'reason' => 'Zeta is superseded.']);
        $mcp($tenant->agentBearer, 'propose_merge', ['note_id' => 4, 'into_note_id' => 2, 'comment' => 'Delta folds into Beta.']);
        $mcp($tenant->curatorBearer, 'log', ['action' => 'observation', 'description' => 'The fixture curated.']);
        $tenant->note('Theta', 'Theta goes to limbo.');

        $this->loginAs($tenant);
        $limbo = $this->noteId($tenant, 'Theta');
        $this->sessionRequest('DELETE', "/api/notes/$limbo");
        self::assertSame(200, $this->httpStatus(), 'fixture retire: '.$this->body());
        $this->sessionRequest('PUT', '/api/notes/3', ['body_md' => 'Gamma, revised by its owner.']);
        self::assertSame(200, $this->httpStatus(), 'fixture edit: '.$this->body());
        $this->sessionRequest('POST', '/api/notes/3/flag', ['comment' => 'Check Gamma.']);
        $this->sessionRequest('POST', '/api/presets', ['name' => 'Designs', 'q' => 'Alpha']);
        $this->sessionRequest('POST', '/api/curation/presets', ['name' => 'Profile']);
        $this->sessionRequest('POST', '/api/settings/ai/keys', ['provider' => 'openai', 'name' => 'Key', 'api_key' => 'sk-a-valid-key']);
        $this->sessionRequest('POST', '/api/skills/skill-handoff/add');
        self::assertSame(201, $this->httpStatus(), 'fixture skill: '.$this->body());

        $client = $this->registerClient();
        $this->requestToken($this->consent($client), $client);
        self::assertArrayHasKey('access_token', $this->jsonResponse(), 'fixture OAuth token: '.$this->body());
        $this->client->restart();
    }

    private function readFixture(Tenant $tenant): void
    {
        $authorize = [];
        parse_str((string) parse_url($this->authorizeUri($this->registerClient()), PHP_URL_QUERY), $authorize);
        $tenant->enter();
        $vault = static::getContainer()->get('doctrine.dbal.vault_connection');
        $directory = static::getContainer()->get('doctrine.dbal.directory_connection');
        $proposal = fn (string $type): array => $vault->fetchAssociative("SELECT id, revision, note_id FROM edit_proposals WHERE type = ? AND status = 'held' ORDER BY id", [$type]);
        $pending = $vault->fetchAllAssociative("SELECT id, version FROM notes WHERE status = 'pending' ORDER BY id");
        $edit = $proposal('edit');

        $this->fixture = [
            'pending' => (int) $pending[0]['id'],
            'pending_version' => (int) $pending[0]['version'],
            'pending_other' => (int) $pending[1]['id'],
            'limbo' => (int) $vault->fetchOne('SELECT id FROM deleted_notes'),
            'revised' => 3,
            'revision' => (int) $vault->fetchOne('SELECT id FROM note_revisions WHERE note_id = 3'),
            'edit_revision' => (int) $edit['revision'],
            'edited_version' => (int) $vault->fetchOne('SELECT version FROM notes WHERE id = ?', [$edit['note_id']]),
            'delete_proposal' => (int) $proposal('delete')['id'],
            'merge_proposal' => (int) $proposal('merge')['id'],
            'skill' => $this->noteId($tenant, 'skill — handoff'),
            'curation_profile' => (int) $vault->fetchOne("SELECT id FROM curation_presets WHERE name = 'Profile'"),
            'spare_connection' => (int) $vault->fetchOne("SELECT id FROM api_tokens WHERE name LIKE 'spare-%'"),
            'identity' => (string) $directory->fetchOne('SELECT ref FROM identities WHERE account_id = ?', [$tenant->accountId]),
            'other_session' => (string) $directory->fetchOne('SELECT ref FROM user_sessions WHERE account_id = ? ORDER BY id LIMIT 1', [$tenant->accountId]),
            'email' => $tenant->email,
            'authorize' => $authorize,
        ];
        $missing = array_keys(array_filter($this->fixture, static fn (mixed $v, string $k): bool => $k !== 'edit_revision' && in_array($v, [0, ''], true), ARRAY_FILTER_USE_BOTH));
        self::assertSame([], $missing, 'The fixture is missing rows the sweep relies on');
    }

    private function noteId(Tenant $tenant, string $title): int
    {
        $tenant->enter();

        return (int) static::getContainer()->get('doctrine.dbal.vault_connection')->fetchOne('SELECT id FROM notes WHERE title = ?', [$title]);
    }

    /** Rows beyond the two accounts the sweep should find, written before it starts. */
    protected function populateServer(): void
    {
    }

    private function scratch(string $name, string $content): string
    {
        $path = sys_get_temp_dir().'/memex-sweep-'.bin2hex(random_bytes(4)).'-'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    /** @param array<string, string> $entries */
    private function zip(array $entries): string
    {
        $path = sys_get_temp_dir().'/memex-sweep-'.bin2hex(random_bytes(4)).'.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private static function png(): string
    {
        $image = imagecreatetruecolor(64, 64);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
