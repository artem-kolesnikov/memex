<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;
use App\Entity\CuratorLogEntry;
use App\Entity\EditProposal;
use App\Entity\Note;
use App\Entity\VaultSettings;
use App\Entity\SkillServe;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * memex's MCP server: one JSON-RPC message in, its answer out, for the
 * connection whose token sent it. Spoken over HTTP by {@see \App\Controller\McpController}.
 */
final class McpServer
{
    private const LIVE_STATE_TAG = SystemTags::LIVE_STATE;
    private const PROFILE_TAG = SystemTags::USER_PROFILE;

    private const SYSTEM_TAG_EFFECTS = [
        SystemTags::SKILL =>
            'A VERIFIED note carrying this tag is served to every connected assistant as a '
            .'loadable instruction set (list_skills / get_skill). Add it only when the body IS '
            .'a followable instruction set.',
        SystemTags::LIVE_STATE =>
            'A note carrying this tag is served a standing write-back instruction on every get, '
            .'asking whoever changed the system it describes to correct it. Add it to notes '
            .'about live mutable state (hosts, runbooks, endpoints, pipelines, project status); '
            .'never to settled history or dated snapshots.',
        SystemTags::USER_PROFILE =>
            'A note carrying this tag is the owner\'s profile: named to every connection at '
            .'initialize, and served a reading instruction on every get (profile_notice). It '
            .'changes no permission — edits go through the same gate as any note. One note is '
            .'the convention; tag a second only when the owner asks for it.',
    ];

    private const SUMMARY_SOFT_CAP = 350;

    private const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private const EXAMINED_MAX = 60;

    private ?ApiToken $mcpToken = null;

    private string $origin = '';

    private ?array $servedRows = null;

    private const CURATOR_TOOLS = ['log', 'log_recent', 'resolve_curation_flag', 'duplicate_candidates'];

    private const OPEN_CURATION_TOOLS = ['curation_candidates', 'last_curated', 'blast_radius'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HybridSearch $hybridSearch,
        private readonly NoteWriter $noteWriter,
        private readonly CurationQueue $curationQueue,
        private readonly WorkPending $workPending,
        private readonly CurationFlags $curationFlags,
        private readonly CurationLeases $curationLeases,
        private readonly SkillLibrary $skills,
        private readonly CurationCharter $charter,
        private readonly SkillServes $serves,
        private readonly TagAdmin $tagAdmin,
        private readonly WriteHints $writeHints,
        private readonly CurationDigest $digest,
        private readonly UserProfiles $profiles,
        private readonly LoggerInterface $logger,
        private readonly ReleaseVersion $release,
        private readonly Owner $owner,
        private readonly EmbeddingSpace $space,
    ) {
    }

    private function serverInfo(): array
    {
        $origin = $this->origin;

        return [
            'name' => 'memex',
            'title' => 'Memex',
            'version' => $this->release->current(),
            'websiteUrl' => $origin,
            'icons' => [
                ['src' => $origin.'/icon-512.png', 'mimeType' => 'image/png', 'sizes' => ['512x512']],
                ['src' => $origin.'/favicon.svg', 'mimeType' => 'image/svg+xml', 'sizes' => ['any']],
            ],
        ];
    }

    /**
     * The answer to one JSON-RPC message from the connection $token, or null
     * for a notification, which is answered by nothing. $origin is the address
     * this server is reached at.
     *
     * @return array<string, mixed>|null
     */
    public function reply(string $message, ApiToken $token, string $origin): ?array
    {
        // Held for the whole dispatch because the charter's body depends on
        // which brief this connection runs under.
        $this->mcpToken = $token;
        $this->origin = $origin;
        $this->servedRows = null;

        try {
            $message = json_decode($message, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->rpcError(null, -32700, 'Parse error');
        }
        if (!is_array($message) || array_is_list($message) || !isset($message['method'])) {
            return $this->rpcError(null, -32600, 'Invalid request (single JSON-RPC object expected)');
        }

        $id = $message['id'] ?? null;
        $method = (string) $message['method'];
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if ($id === null) {
            return null;
        }

        return match ($method) {
            'initialize' => $this->initializeResult($id, $params),
            'ping' => $this->rpcResult($id, new \stdClass()),
            'tools/list' => $this->rpcResult($id, ['tools' => $this->toolDefinitions()]),
            'tools/call' => $this->callTool($id, $params),
            'prompts/list' => $this->rpcResult($id, ['prompts' => array_map(static fn (array $s) => [
                'name' => $s['slug'],
                'description' => $s['description'],
            ], $this->skills('command'))]),
            'prompts/get' => $this->promptsGet($id, $params),
            'resources/list' => $this->rpcResult($id, ['resources' => array_map(static fn (array $s) => [
                'uri' => 'memex://skill/'.$s['slug'],
                'name' => $s['title'],
                'description' => $s['description'],
                'mimeType' => 'text/markdown',
            ], $this->skills('auto'))]),
            'resources/read' => $this->resourcesRead($id, $params),
            default => $this->rpcError($id, -32601, 'Method not found: '.$method),
        };
    }

    private function initializeResult(mixed $id, array $params): array
    {
        $this->stampSkillsSeen();

        return $this->rpcResult($id, [
            'protocolVersion' => in_array($params['protocolVersion'] ?? '', self::PROTOCOL_VERSIONS, true)
                ? $params['protocolVersion']
                : self::PROTOCOL_VERSIONS[0],
            'capabilities' => [
                'tools' => new \stdClass(),
                'prompts' => new \stdClass(),
                'resources' => new \stdClass(),
            ],
            'instructions' => $this->serverInstructions(),
            'serverInfo' => $this->serverInfo(),
        ]);
    }

    /** @return array<int, array{id: int, slug: string, title: string, description: string, body: string, updated_at: string}> */
    private function serverInstructions(): string
    {
        $base = <<<'TXT'
            memex is the user's own knowledge base: markdown notes with tags, wiki-links and
            meaning-based search. You read it with `search` and `get`, and you write to it with
            `propose`.

            Six things worth knowing before you use it.

            **You write the descriptions.** When you save a note you are already holding the text,
            so write the `title`, the `summary` and the `tags` yourself and pass them as arguments.
            Call `list_tags` first and reuse the vocabulary that is already there rather than
            inventing a private dialect. This knowledge base may also describe notes itself, on a
            provider account its owner pays for, if they have switched that on: it writes a
            description when a note arrives without one, and files the note under tags its owner's
            vocabulary already contains. It never does either instead of you. A summary you supply
            is the one that is kept and costs them nothing, your tags are never removed, and words
            a model invents are never written.

            What arrived without you is the backlog: uploads and notes typed on the website.
            `needs_enrichment` lists what still wants describing, and it
            leaves out any note whose description is already waiting in the review inbox, so two
            workers cannot write the same one twice. Work it when the user asks: read a note with
            `get` and send the description with `propose(note_id:, summary:)`, one call per note.

            **Sources point outside.** A note that ends in a `## Sources` section lists what its
            owner wants read before the note is acted on — documentation, a standard, a page —
            each entry with what it is for and when to reread it. Reread those first: the note is
            the summary, the link is the authority. And when you file a note, put the pages you
            consulted under the same heading. It is a heading and a list, not a field: `source_url`
            is where a note came from, *Sources* is what to consult next.

            **The user's instructions live here too.** `list_skills` returns instruction sets the
            operator has approved, and `get_skill` loads one in full. Check it before a task that
            one of them might cover, and follow what you find — it is how this user wants that work
            done. Some knowledge bases carry more, and some carry none.

            {SKILLS}

            {PROFILE}

            {WRITING}{CURATION}

            **Questions about memex itself.** `get_skill("memex-guide")` is the current user
            guide: what every screen, setting and tool does. Answer questions about memex from
            it, not from memory.

            **Your writes are reviewed.** New notes land `pending` and edits are held until the
            operator approves them in the review inbox. That is normal, not a failure. Tokens the
            operator has granted the curator role are the exception for safe writes, and even then
            deletes and merges are always held. `health` tells you which role you have.
            TXT;

        return str_replace(
            ['{SKILLS}', '{PROFILE}', '{WRITING}', '{CURATION}'],
            [$this->skillsBlock(), $this->profileParagraph(), $this->writingParagraph(), $this->curationParagraph()],
            $base,
        );
    }

    /** @return array<string, string|bool> the owner's Personalization, read fresh on every call */
    private function personalization(): array
    {
        return Personalization::read($this->em->getRepository(VaultSettings::class)->current()->getPersonalization());
    }

    /** The paragraph pointing at memex-writing while the owner keeps it on; nothing while it is off. */
    private function writingParagraph(): string
    {
        $paragraph = MemexWriting::connectParagraph($this->personalization());

        return $paragraph === null ? '' : $paragraph."\n\n";
    }

    /** The owner's profile, named at connect time so the common read is one `get` rather than a search. */
    private function profileParagraph(): string
    {
        return $this->profiles->connectParagraph();
    }

    private function curationParagraph(): string
    {
        $charter = $this->charterSlug();

        if ($this->mcpToken?->isCurator() !== true) {
            return <<<TXT
                **Curation.** You may read what needs work — `curation_candidates` ranks it,
                `last_curated` says what has been seen, `blast_radius` finds notes left stale by a
                neighbour's change — and you may file proposals against it, which are held for the
                operator's review like every other write you make. You may NOT record a run in the
                Curator log or resolve the operator's flags: those need the curator role, which
                only the owner can grant, from Settings › Assistants › Note maintenance in a browser. If you are
                asked to curate, say that much plainly rather than working around it, and never
                improvise a pass without the queue — its ranking, its cooldown and the operator's
                own flags are what makes a pass curation rather than browsing. The instruction set
                is `get_skill("{$charter}")`, and it covers your role as well as the curator's:
                load it BEFORE you curate and follow it.
                TXT;
        }

        return <<<TXT
            **Curation.** You hold the curator role here, and this knowledge base carries its
            charter: load `get_skill("{$charter}")` BEFORE you curate, and follow it. It is
            the operator's complete instruction set for a pass — who may do what, what to hunt and
            in what order, how the rotation hands out notes, and how their past verdicts bind you.
            You are told its name at connect time because a pass that skipped it looks exactly like
            a pass that read it, and nobody would ever find out. Start at `curation_candidates`,
            finish at `log` with the notes you examined.
            TXT;
    }

    private function charterSlug(): string
    {
        return CurationCharter::SLUG;
    }

    private function skills(?string $surface = null): array
    {
        return $this->skills->all($this->mcpToken, $surface);
    }

    private function servedList(): array
    {
        if ($this->servedRows !== null) {
            return $this->servedRows;
        }

        $rows = [];
        foreach ($this->skills() as $skill) {
            $auto = $skill['id'] === null ? true : $skill['auto'];
            $command = $skill['id'] === null ? true : $skill['command'];
            if ($skill['id'] !== null && !$auto && !$command) {
                // Neither loading surface is on: prompts/get, get_skill and
                // resources/read all refuse it, so advertising it here would
                // hand out a call that cannot be honoured.
                continue;
            }
            $rows[$skill['slug']] = [
                'slug' => $skill['slug'],
                'description' => $skill['description'],
                'auto' => $auto,
                'command' => $command,
            ];
        }
        ksort($rows);

        return $this->servedRows = array_values($rows);
    }

    private function skillsVersion(): string
    {
        $signature = array_map(static fn (array $r) => [$r['slug'], $r['description'], $r['auto'], $r['command']], $this->servedList());

        return substr(sha1(json_encode($signature, JSON_THROW_ON_ERROR)), 0, 12);
    }

    private function skillsBlock(): string
    {
        $lines = [];
        foreach ($this->servedList() as $r) {
            $load = match (true) {
                $r['auto'] && $r['command'] => 'get_skill("'.$r['slug'].'") or prompt "'.$r['slug'].'"',
                $r['auto'] => 'get_skill("'.$r['slug'].'")',
                default => 'prompt "'.$r['slug'].'" (a command in your client, /mcp__memex__'.$r['slug'].' in Claude Code)',
            };
            $lines[] = '`'.$r['slug'].'` — '.$r['description'].' — '.$load;
        }

        return "**Skills served to this connection right now**, each with the call that loads it.\n".implode("\n", $lines);
    }

    private function stampSkillsSeen(): void
    {
        if ($this->mcpToken === null) {
            return;
        }
        $this->stampPersonalizationSeen();
        $version = $this->skillsVersion();
        if ($this->mcpToken->getSkillsSeen() !== $version) {
            $this->mcpToken->markSkillsSeen($version);
            $this->em->getConnection()->executeStatement(
                'UPDATE api_tokens SET skills_seen = :v WHERE id = :id',
                ['v' => $version, 'id' => $this->mcpToken->getId()],
            );
        }
    }

    private function withSkillsVersion(array $result): array
    {
        $version = $this->skillsVersion();
        $result['skills_version'] = $version;
        if ($this->mcpToken !== null && $this->mcpToken->getPersonalizationSeen() !== MemexWriting::version($this->personalization())) {
            // Told once, on the first result after the owner changes a preset,
            // because a connection opened earlier may hold the old text.
            $result['personalization_changed'] = MemexWriting::changedNotice($this->personalization());
            $this->stampPersonalizationSeen();
        }
        if ($this->mcpToken !== null && $this->mcpToken->getSkillsSeen() !== $version) {
            $result['skills_changed'] = str_replace("\n", ' · ', $this->skillsBlock());
            $this->stampSkillsSeen();
        }

        return $result;
    }

    private function stampPersonalizationSeen(): void
    {
        if ($this->mcpToken === null) {
            return;
        }
        $version = MemexWriting::version($this->personalization());
        if ($this->mcpToken->getPersonalizationSeen() !== $version) {
            $this->mcpToken->markPersonalizationSeen($version);
            $this->em->getConnection()->executeStatement(
                'UPDATE api_tokens SET personalization_seen = :v WHERE id = :id',
                ['v' => $version, 'id' => $this->mcpToken->getId()],
            );
        }
    }

    private function recordServe(array $skill, string $path): void
    {
        $this->serves->record($skill, $this->mcpToken, $path);
    }

    private function findSkill(string $slug, string $surface): ?array
    {
        foreach ($this->skills($surface) as $skill) {
            if ($skill['slug'] === $slug) {
                return $skill;
            }
        }

        return null;
    }

    private function promptsGet(mixed $id, array $params): array
    {
        $skill = $this->findSkill((string) ($params['name'] ?? ''), 'command');
        if ($skill === null) {
            return $this->rpcError($id, -32602, 'Unknown prompt: '.(string) ($params['name'] ?? ''));
        }

        $this->recordServe($skill, SkillServe::PATH_PROMPT);

        return $this->rpcResult($id, [
            'description' => $skill['description'],
            'messages' => [[
                'role' => 'user',
                'content' => ['type' => 'text', 'text' => $skill['body']],
            ]],
        ]);
    }

    private function resourcesRead(mixed $id, array $params): array
    {
        $uri = (string) ($params['uri'] ?? '');
        $slug = str_starts_with($uri, 'memex://skill/') ? substr($uri, strlen('memex://skill/')) : '';
        $skill = $slug === '' ? null : $this->findSkill($slug, 'auto');
        if ($skill === null) {
            return $this->rpcError($id, -32602, 'Unknown resource: '.$uri);
        }

        $this->recordServe($skill, SkillServe::PATH_RESOURCE);

        return $this->rpcResult($id, [
            'contents' => [[
                'uri' => $uri,
                'mimeType' => 'text/markdown',
                'text' => $skill['body'],
            ]],
        ]);
    }

    private function callTool(mixed $id, array $params): array
    {
        $tool = (string) ($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $isError = false;
        try {
            $args = $this->decodeArgs($args);
            $result = match ($tool) {
                'search' => $this->toolSearch($args),
                'get' => $this->toolGet($args),
                'inbox' => $this->toolInbox($args),
                'propose' => $this->toolPropose($args),
                'propose_delete' => $this->toolProposeDelete($args),
                'propose_merge' => $this->toolProposeMerge($args),
                'log' => $this->toolLog($args),
                'log_recent' => $this->toolLogRecent($args),
                'last_curated' => $this->toolLastCurated($args),
                'needs_enrichment' => $this->curationQueue->needsEnrichment(
                    min(100, max(1, (int) ($args['limit'] ?? 25))),
                    max(0, (int) ($args['offset'] ?? 0)),
                ),
                'curation_candidates' => $this->toolCurationCandidates($args),
                'resolve_curation_flag' => $this->toolResolveCurationFlag($args),
                'duplicate_candidates' => $this->toolDuplicateCandidates($args),
                'blast_radius' => $this->toolBlastRadius($args),
                'list_skills' => ['skills' => array_map(static fn (array $s) => [
                    'slug' => $s['slug'],
                    'title' => $s['title'],
                    'description' => $s['description'],
                    'updated_at' => $s['updated_at'],
                ], $this->skills('auto'))],
                'get_skill' => $this->toolGetSkill($args),
                'list_tags' => $this->toolListTags(),
                'health' => [
                    'status' => 'ok',
                    'authenticated_as' => $this->mcpToken?->getName(),
                    'role' => $this->mcpToken?->getRole(),
                    'work' => [
                        'inbox' => $this->workPending->inbox(),
                        'needs_enrichment' => $this->workPending->enrichment(),
                    ],
                ],
                default => null,
            };
            if ($result === null) {
                return $this->rpcError($id, -32602, 'Unknown tool: '.$tool);
            }

        } catch (\Throwable $e) {
            $limit = StorageLimitExceeded::fromThrowable($e);
            $message = $limit === null ? ClientMessage::of($e) : null;
            if ($limit === null && $message === null) {
                $this->logger->error('An MCP tool failed', ['tool' => $tool, 'exception' => $e]);
            }
            $result = $limit?->payload() ?? ['error' => $message ?? 'Tool failed: memex hit an internal error.'];
            $isError = true;
        }

        return $this->toolResult($id, $this->withSkillsVersion($result), $isError);
    }

    private function toolSearch(array $args): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        $limit = min(25, max(1, (int) ($args['limit'] ?? 10)));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $status = $args['status'] ?? null;
        if ($status !== null && !in_array($status, [Note::STATUS_VERIFIED, Note::STATUS_PENDING], true)) {
            throw new \InvalidArgumentException('status must be verified or pending');
        }
        $order = isset($args['order']) ? (string) $args['order'] : null;

        $tagIds = [];
        $unknownTags = [];
        if (!empty($args['tags']) && is_array($args['tags'])) {
            $requested = array_values(array_unique(array_map('strval', $args['tags'])));
            $found = $this->em->getConnection()->fetchAllAssociative(
                'SELECT id, name FROM tags WHERE name IN (:names)',
                ['names' => $requested],
                ['names' => \Doctrine\DBAL\ArrayParameterType::STRING]
            );
            $tagIds = array_column($found, 'id');
            $unknownTags = array_values(array_diff($requested, array_column($found, 'name')));
            if ($tagIds === []) {
                return ['total' => 0, 'offset' => $offset, 'returned' => 0, 'items' => [], 'unknown_tags' => $unknownTags];
            }
        }

        $result = $this->hybridSearch->search(
            $query !== '' ? $query : null,
            array_map('intval', $tagIds),
            null,
            $status,
            1,
            $limit,
            $order,
            $offset,
        );

        return [
            'total' => $result['total'],
            'offset' => $offset,
            'returned' => count($result['items']),
            ...($unknownTags === [] ? [] : ['unknown_tags' => $unknownTags]),
            ...($result['semantic_unavailable'] ? ['semantic_search_unavailable' =>
                'Meaning-based ranking did not run for this query, so these results are keyword matches only. '
                .'An empty result here means no note contains these words — not that the knowledge base has nothing on the subject. '
                .'Try different wording, or search again shortly.'] : []),
            ...(($result['keyword_only'] ?? false) ? ['exact_match' =>
                'The query carried OR, NOT or a quoted phrase, so these are the notes matching that expression as written; '
                .'meaning-based ranking is not used with operators. An empty result means no note matches the expression.'] : []),
            'items' => array_map(static fn (array $row) => [
                'id' => (int) $row['id'],
                'title' => $row['title'],
                'summary' => $row['summary'],
                'status' => $row['status'],
                'source_url' => $row['source_url'],
                'tags' => array_column($row['tags'], 'name'),
                'updated_at' => $row['updated_at'],
                ...(is_string($row['matched_section'] ?? null) && $row['matched_section'] !== '' ? ['matched_section' => $row['matched_section']] : []),
            ], $result['items']),
        ];
    }

    private function toolGet(array $args): array
    {
        $note = $this->requireNote($args, 'id');

        $conn = $this->em->getConnection();
        $links = $conn->fetchAllAssociative(
            'SELECT raw_target, to_note_id FROM note_links WHERE from_note_id = :id',
            ['id' => $note->getId()]
        );
        $backlinks = $conn->fetchAllAssociative(
            'SELECT n.id, n.title FROM note_links nl
             JOIN notes n ON n.id = nl.from_note_id
             WHERE nl.to_note_id = :id',
            ['id' => $note->getId()]
        );

        $data = NoteJson::note($note, $this->owner->mark(), true);
        $data['added_by'] = ActorView::added($note, $this->owner->mark());
        $data['links'] = $links;
        $data['backlinks'] = $backlinks;
        $flag = $this->curationFlags->open($note);
        if ($flag !== null) {
            $data['curation_flag'] = $flag->toArray();
        }

        $data = self::retrievalNotice($data);

        $body = $data['body_md'] ?? '';
        $total = mb_strlen($body);
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $maxChars = isset($args['max_chars']) ? max(1, (int) $args['max_chars']) : null;

        $data['body_chars'] = $total;
        if ($maxChars !== null || $offset > 0) {
            $slice = mb_substr($body, $offset, $maxChars);
            $data['body_md'] = $slice;
            $data['body_offset'] = $offset;
            $end = $offset + mb_strlen($slice);
            $data['body_truncated'] = $end < $total;
            $data['body_next_offset'] = $end < $total ? $end : null;
        }

        return $data;
    }

    private function toolInbox(array $args): array
    {
        $limit = min(50, max(1, (int) ($args['limit'] ?? 25)));
        $maxChars = isset($args['max_chars']) ? max(1, (int) $args['max_chars']) : null;

        $notes = $this->em->getRepository(Note::class)->findBy(
            ['status' => Note::STATUS_PENDING],
            ['createdAt' => 'ASC'],
            $limit,
        );
        $proposals = $this->em->getRepository(EditProposal::class)->findBy(
            ['status' => EditProposal::STATUS_HELD],
            ['createdAt' => 'ASC'],
            $limit,
        );
        $counts = $this->workPending->inbox();

        return [
            'counts' => $counts,
            'returned' => [
                'pending_notes' => count($notes),
                'edit_proposals' => count($proposals),
            ],
            'pending_notes' => array_map(function (Note $note) {
                return [
                    'id' => $note->getId(),
                    'title' => $note->getTitle(),
                    'summary' => $note->getSummary(),
                    'tags' => array_map(static fn ($tag) => $tag->getName(), SystemTags::sortBy($note->getTags()->toArray(), static fn ($tag) => $tag->getName())),
                    'source' => $note->getSource(),
                    'source_url' => $note->getSourceUrl(),
                    'proposed_by' => $note->getCreatedByToken()?->getName(),
                    'created_at' => $note->getCreatedAt()->format(DATE_ATOM),
                    'body_chars' => mb_strlen($note->getBodyMd()),
                ];
            }, $notes),
            'edit_proposals' => array_map(
                fn (EditProposal $p) => $this->heldItemToArray($p, $maxChars), $proposals
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function heldItemToArray(EditProposal $proposal, ?int $maxChars): array
    {
        return self::sizeProposedBody(NoteJson::proposal($proposal), $maxChars);
    }

    /**
     * The standing instruction a retrieval carries, if any. A profile gets the
     * profile notice and NOT the live-state one: the two would give the same
     * reader two triggers for the same note, and the live-state trigger —
     * "you changed the system this describes" — is never true of a person.
     *
     * @param array<string, mixed> $data one `noteToArray` result
     * @return array<string, mixed>
     */
    public static function retrievalNotice(array $data): array
    {
        $tags = array_column($data['tags'] ?? [], 'name');
        if (in_array(self::PROFILE_TAG, $tags, true)) {
            return self::profileNotice($data);
        }

        return self::liveStateNotice($data);
    }

    /**
     * The reading instruction served with the owner's profile.
     *
     * Interpolated rather than written into the note, for the same reason as
     * the live-state notice: memex's instructions must not live in a body the
     * owner is meant to edit freely, and the id has to be the note's own.
     *
     * @param array<string, mixed> $data one `noteToArray` result
     * @return array<string, mixed>
     */
    public static function profileNotice(array $data): array
    {
        $tags = array_column($data['tags'] ?? [], 'name');
        if (!in_array(self::PROFILE_TAG, $tags, true)) {
            return $data;
        }

        $id = (int) ($data['id'] ?? 0);
        $skill = ShippedSkills::PROFILE;

        // An assistant's proposal is not the owner's word about themselves.
        // Named so nobody proposes a twin; never followed until approved.
        if (($data['status'] ?? null) === Note::STATUS_PENDING) {
            $data['profile_notice'] =
                'This note is a PENDING PROFILE — an assistant proposed it and the owner has not '
                .'approved it. It is NOT in force: do not follow its instructions or boundaries, and '
                .'do not treat what it says about the owner as confirmed. Do not propose a second '
                ."profile beside it. The full procedure is get_skill(\"{$skill}\").";

            return $data;
        }

        $data['profile_notice'] =
            'This note is the OWNER\'S PROFILE — their own account of who they are, what they are '
            .'working on and how they want to be answered. It outranks what you remember about '
            .'them. A section that still shows its italic prompt is unknown, not a fact: do not '
            .'answer as though it said something. Standing instructions in it are theirs and '
            .'apply to every note you write here, the boundaries especially. '
            .'If this conversation shows a line here has stopped being true, or adds something '
            .'durable it does not say — a project that started or ended, a tool they moved to, a '
            .'correction they made twice — propose the smallest patch: '
            ."propose(note_id: {$id}, patch: [{find: <the sentence>, replace: <what it should say>}], "
            .'change_title: <six words>, comment: <what you saw that makes this true>). '
            .'Never resend the whole body; never write in a guess, a mood, or a fact about '
            .'somebody else. Reading this note is not a reason to edit it. '
            ."The full procedure is get_skill(\"{$skill}\").";

        return $data;
    }

    /**
     * @param array<string, mixed> $data one `noteToArray` result
     * @return array<string, mixed>
     */
    public static function liveStateNotice(array $data): array
    {
        $tags = array_column($data['tags'] ?? [], 'name');
        if (!in_array(self::LIVE_STATE_TAG, $tags, true)) {
            return $data;
        }

        $id = (int) ($data['id'] ?? 0);
        $data['live_state_notice'] =
            'This note describes LIVE STATE — a system that changes. '
            .'If the work you are doing changed anything described here, this note is now '
            .'wrong, and you are the only one who knows. Before you finish the task: '
            ."propose(note_id: {$id}, patch: [{find: <the sentence that is now wrong>, "
            .'replace: <what it should say>}], comment: <what changed>) — correct the '
            .'sentences rather than resending the note. '
            ."Send body_md instead only when the note needs rewriting whole. "
            .'If you know it is stale but not what replaces it, send '
            ."propose(note_id: {$id}, comment: <what you changed and what is now wrong>) "
            .'with no body — a comment-only proposal is a valid staleness report. '
            .'Saying it in chat instead leaves the note wrong. '
            .'The trigger is CHANGING the system this note describes, not reading the note: '
            .'if you changed nothing, do nothing.';

        return $data;
    }

    /**
     * @param array<string, mixed> $data one `proposalToArray` result
     * @return array<string, mixed>
     */
    public static function sizeProposedBody(array $data, ?int $maxChars): array
    {
        $body = $data['proposed_body_md'] ?? null;
        unset($data['proposed_body_md']);
        if (!is_string($body)) {
            $data['proposed_body_chars'] = 0;

            return $data;
        }

        $data['proposed_body_chars'] = mb_strlen($body);
        if ($maxChars !== null) {
            $slice = mb_substr($body, 0, $maxChars);
            $data['proposed_body_md'] = $slice;
            $data['proposed_body_truncated'] = mb_strlen($slice) < mb_strlen($body);
        }

        return $data;
    }

    private function toolPropose(array $args): array
    {
        if (isset($args['note_id'])) {
            return $this->toolProposeEdit($args);
        }

        $title = trim((string) ($args['title'] ?? ''));
        $bodyMd = (string) ($args['body_md'] ?? '');
        if ($title === '' || mb_strlen($title) > 500) {
            throw new \InvalidArgumentException('title is required (max 500 chars)');
        }
        if (trim($bodyMd) === '') {
            throw new \InvalidArgumentException('body_md is required');
        }
        $sourceUrl = $args['source_url'] ?? null;
        if ($sourceUrl !== null && Note::normaliseSourceUrl($sourceUrl) === null) {
            throw new \InvalidArgumentException('source_url must be an http(s) URL');
        }

        $result = $this->noteWriter->create(
            $this->mcpToken,
            $title,
            $bodyMd,
            Note::SOURCE_AGENT,
            is_string($sourceUrl) ? $sourceUrl : null,
            $this->tagNames($args),
            enrich: EmbeddingSpend::Metered,
            summary: self::summaryArg($args),
        );

        return $this->writeResult($result);
    }

    private function toolProposeEdit(array $args): array
    {
        $note = $this->requireNote($args, 'note_id');

        $title = isset($args['title']) ? trim((string) $args['title']) : null;
        if ($title !== null && ($title === '' || mb_strlen($title) > 500)) {
            throw new \InvalidArgumentException('title must be 1-500 chars');
        }
        $bodyMd = isset($args['body_md']) ? (string) $args['body_md'] : null;
        if ($bodyMd !== null && trim($bodyMd) === '') {
            throw new \InvalidArgumentException('body_md must not be empty');
        }
        $tags = isset($args['tags']) && is_array($args['tags']) ? array_map('strval', $args['tags']) : null;
        $summary = self::summaryArg($args);
        $patch = array_key_exists('patch', $args) ? NotePatch::parse($args['patch']) : null;

        $proposal = $this->noteWriter->propose(
            $note,
            $this->mcpToken,
            $title,
            $bodyMd,
            $tags,
            isset($args['comment']) && is_string($args['comment']) ? $args['comment'] : null,
            (bool) ($args['hold'] ?? false),
            summary: $summary,
            patch: $patch,
            changeTitle: isset($args['change_title']) && is_string($args['change_title']) ? $args['change_title'] : null,
        );

        $out = [
            'proposal' => NoteJson::proposal($proposal),
            'applied' => $proposal->isApplied(),
            'review' => match (true) {
                $proposal->isApplied() => 'Applied immediately (curator token) — the change is live and recorded in the activity log.',
                // A revised draft is the only proposal that comes back carrying
                // a revision timestamp: a fresh row has none, and only folding
                // sets one.
                $proposal->getRevisedAt() !== null => 'Edit folded into the draft you already have in review — the operator sees ONE item per note per connection, so this revised that one rather than adding a second. Everything you sent replaced the matching field; anchors were appended to the ones already held; fields you left out stand as filed. Read `proposal` for the whole document as it now reads.',
                $proposal->getType() === EditProposal::TYPE_REPORT => 'Report filed — the note is unchanged, and nothing here proposes changing it. The operator sees what you said in the review inbox and decides what to do about it.',
                NoteWriter::holdsInstructions($this->mcpToken, $note, $tags) => NoteWriter::INSTRUCTIONS_HELD,
                NoteWriter::wantsAnchoring($this->mcpToken, $bodyMd) => 'Edit held for review — it replaces the whole body, and your edits apply without review, so a full body could silently overwrite whatever another connection changed while you were reading. Send the same change as `patch` and it applies immediately; an anchor that no longer fits is refused rather than applied over somebody else\'s work.',
                default => 'Edit held for review — the note is unchanged until the operator approves the proposal in the review inbox.',
            },
        ];
        $cited = $this->citedBy([(int) $note->getId()], self::CITED_EDIT);
        if ($cited !== null) {
            $out['cited_by'] = $cited;
        }

        return $out;
    }

    private function toolProposeDelete(array $args): array
    {
        $note = $this->requireNote($args, 'note_id');
        $reason = isset($args['reason']) && is_string($args['reason']) ? $args['reason'] : null;
        if ($reason === null || trim($reason) === '') {
            throw new \InvalidArgumentException('reason is required — the operator decides from it');
        }

        $proposal = $this->noteWriter->proposeDelete($note, $this->mcpToken, $reason);

        $out = [
            'proposal' => NoteJson::proposal($proposal),
            'review' => 'Deletion held for review — the note stays until the operator approves it in the review inbox.',
        ];
        $cited = $this->citedBy([(int) $note->getId()], self::CITED_DELETE);
        if ($cited !== null) {
            $out['cited_by'] = $cited;
        }

        return $out;
    }

    private function toolProposeMerge(array $args): array
    {
        $absorb = $this->requireNote($args, 'note_id');
        $keeper = $this->requireNote($args, 'into_note_id');
        $mergedBody = isset($args['merged_body_md']) && is_string($args['merged_body_md']) ? $args['merged_body_md'] : null;
        $comment = isset($args['comment']) && is_string($args['comment']) ? $args['comment'] : null;

        $proposal = $this->noteWriter->proposeMerge(
            $absorb,
            $keeper,
            $this->mcpToken,
            $mergedBody,
            $comment,
        );

        $out = [
            'proposal' => NoteJson::proposal($proposal),
            'review' => 'Merge held for review — both notes stay until the operator approves it in the review inbox.',
        ];
        $cited = $this->citedBy(
            [(int) $absorb->getId(), (int) $keeper->getId()],
            self::CITED_MERGE,
        );
        if ($cited !== null) {
            $out['cited_by'] = $cited;
        }

        return $out;
    }

    private function toolLog(array $args): array
    {
        $token = $this->requireCurator('log');
        $action = (string) ($args['action'] ?? '');
        if (!in_array($action, CuratorLogEntry::AGENT_ACTIONS, true)) {
            throw new \InvalidArgumentException('action must be one of: '.implode(', ', CuratorLogEntry::AGENT_ACTIONS));
        }
        $description = trim((string) ($args['description'] ?? ''));
        if ($description === '' || mb_strlen($description) > 10000) {
            throw new \InvalidArgumentException('description is required (max 10000 chars)');
        }

        $entry = (new CuratorLogEntry($token->getName(), $action, $description))
            ->withToken($token)
            ->withClaims(self::parseClaims($args, $action))
            ->withRunStartedAt(self::parseStartedAt($args, $action));
        if (isset($args['note_id'])) {
            $entry->withNote($this->requireNote($args, 'note_id'));
        }
        $this->em->persist($entry);
        $this->em->flush();

        $examined = $this->recordExamined($args, $entry);
        if ($examined !== null) {
            $this->em->flush();
        }
        $stamped = null;
        if ($action === 'run-summary') {
            // The pass is over, so its window is finally knowable: stamp what
            // it wrote as curation, and the summary itself as its own head.
            // Rows are stamped only when the pass stated where it began —
            // {@see CurationDigest::stampRun()} for why an unbounded window is
            // left alone.
            $entry->withCurationRun($entry);
            $this->em->flush();
            $stamped = $this->digest->stampRun((int) $entry->getId());
        }

        $result = ['logged' => true, 'id' => $entry->getId(), 'action' => $action];
        if ($entry->getClaims() !== null) {
            $result['claimed'] = $entry->getClaims();
        }
        if ($stamped !== null) {
            // Said out loud, because the alternative is a pass that believes
            // its work was filed as curation when it was not. Every
            // run-summary in the operator's vault at the time this shipped had
            // omitted `started_at`, so the honest answer to "show me curation"
            // was 76 rows out of 678 — the summaries and the notes they read,
            // and none of the work.
            $result['curation_rows'] = $stamped;
            if ($entry->getRunStartedAt() === null) {
                $result['note'] = 'This pass did not send started_at, so only this summary and its examined rows are '
                    .'recorded as curation — everything you wrote stays in the journal but reads as ordinary agent work. '
                    .'Memex cannot infer the boundary: your connection writes the same rows when somebody drives it by hand.';
            }
        }

        return $examined === null ? $result : $result + $examined;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, int>|null
     */
    private static function parseClaims(array $args, string $action): ?array
    {
        if (!isset($args['claimed'])) {
            return null;
        }
        if ($action !== 'run-summary') {
            throw new \InvalidArgumentException('claimed belongs on a run-summary — an observation or tooling-gap covers no window, so there is nothing to check it against');
        }
        if (!is_array($args['claimed']) || array_is_list($args['claimed'])) {
            throw new \InvalidArgumentException('claimed must be an object of counts, e.g. {"edited": 7, "held": 3}');
        }

        $claims = [];
        foreach ($args['claimed'] as $key => $value) {
            if (!array_key_exists($key, CurationDigest::CLAIMS)) {
                throw new \InvalidArgumentException(
                    'claimed: "'.$key.'" is not something a run can claim — the keys are: '.implode(', ', array_keys(CurationDigest::CLAIMS))
                );
            }
            if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                throw new \InvalidArgumentException('claimed.'.$key.' must be a whole number of items');
            }
            $value = (int) $value;
            if ($value < 0 || $value > CurationDigest::CLAIM_MAX) {
                throw new \InvalidArgumentException('claimed.'.$key.' must be between 0 and '.CurationDigest::CLAIM_MAX);
            }
            $claims[$key] = $value;
        }

        return $claims === [] ? null : $claims;
    }

    /** @param array<string, mixed> $args */
    private static function parseStartedAt(array $args, string $action): ?\DateTimeImmutable
    {
        if (!isset($args['started_at']) || $args['started_at'] === '') {
            return null;
        }
        if ($action !== 'run-summary') {
            throw new \InvalidArgumentException('started_at belongs on a run-summary — it is the boundary of a pass, and an observation is not one');
        }
        if (!is_string($args['started_at'])) {
            throw new \InvalidArgumentException('started_at must be an ISO-8601 timestamp, e.g. "2026-08-26T02:05:00Z"');
        }

        $at = null;
        foreach (['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP', 'Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i:s.u\Z'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $args['started_at'], new \DateTimeZone('UTC'));
            if ($parsed !== false && \DateTimeImmutable::getLastErrors() === false) {
                $at = $parsed;
                break;
            }
        }
        if ($at === null) {
            throw new \InvalidArgumentException('started_at is not an ISO-8601 timestamp — send one like "2026-08-26T02:05:00Z" or "2026-08-26T02:05:00+00:00"');
        }
        if ($at->getTimestamp() > time() + 60) {
            throw new \InvalidArgumentException('started_at is in the future — a run cannot begin after it ended');
        }

        return $at->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * @param array<string, mixed> $args
     * @return array{examined: int, still_defective: list<array{note_id: int, reasons: string[]}>}|null
     */
    private function recordExamined(array $args, CuratorLogEntry $summary): ?array
    {
        if (!isset($args['examined']) || !is_array($args['examined'])) {
            return null;
        }
        $ids = array_values(array_unique(array_map('intval', array_filter($args['examined'], 'is_numeric'))));
        if ($ids === []) {
            return null;
        }
        if (count($ids) > self::EXAMINED_MAX) {
            throw new \InvalidArgumentException(
                'examined takes at most '.self::EXAMINED_MAX.' note ids — that is more than twice the charter\'s per-run budget, so a longer list is a mistake rather than a big pass'
            );
        }

        $token = $summary->getToken();
        $stillDefective = [];

        foreach ($ids as $id) {
            $note = $this->em->find(Note::class, $id);
            if ($note === null) {
                throw new \InvalidArgumentException('examined: note '.$id.' is not in this knowledge base');
            }

            $reasons = [];
            foreach (CurationQueue::reasons() as $reason) {
                if ($reason !== 'operator_flag' && $this->curationQueue->noteHasDefect($id, $reason)) {
                    $reasons[] = $reason;
                }
            }
            if ($reasons !== []) {
                $stillDefective[] = ['note_id' => $id, 'reasons' => $reasons];
            }

            $row = (new CuratorLogEntry(
                $summary->getTokenName(),
                CuratorLogEntry::ACTION_EXAMINED,
                'Read during a curation pass and needed nothing (log entry '.$summary->getId().').'
            ))->withNote($note)->withRun($summary)->withCurationRun($summary);
            if ($token !== null) {
                $row->withToken($token);
            }
            $this->em->persist($row);
        }

        $this->curationLeases->release($token?->getId());

        return ['examined' => count($ids), 'still_defective' => $stillDefective];
    }

    private function toolLogRecent(array $args): array
    {
        $this->requireCurator('log_recent');
        $limit = min(200, max(1, (int) ($args['limit'] ?? 40)));
        $offset = max(0, (int) ($args['offset'] ?? 0));

        $criteria = [];
        if (isset($args['note_id'])) {
            $criteria['note'] = $this->requireNote($args, 'note_id');
        }
        if (($args['precedent_only'] ?? false) === true) {
            $criteria['isPrecedent'] = true;
        }
        if (isset($args['action'])) {
            $action = (string) $args['action'];
            if (!in_array($action, CuratorLogEntry::ACTIONS, true)) {
                throw new \InvalidArgumentException('action must be one of: '.implode(', ', CuratorLogEntry::ACTIONS));
            }
            $criteria['action'] = $action;
        }

        $repo = $this->em->getRepository(CuratorLogEntry::class);
        /** @var CuratorLogEntry[] $rows */
        $rows = $repo->findBy($criteria, ['createdAt' => 'DESC', 'id' => 'DESC'], $limit, $offset);

        return [
            'total' => $repo->count($criteria),
            'offset' => $offset,
            'entries' => array_map(static function (CuratorLogEntry $e): array {
                $entry = [
                    'id' => $e->getId(),
                    'at' => $e->getCreatedAt()->format(DATE_ATOM),
                    'by' => $e->getTokenName(),
                    'action' => $e->getAction(),
                    'description' => $e->getDescription(),
                    'note_id' => $e->getNote()?->getId(),
                    'note_title' => $e->getNoteTitle(),
                ];
                if ($e->getOperatorComment() !== null) {
                    $entry['operator_comment'] = $e->getOperatorComment();
                    $entry['is_precedent'] = $e->isPrecedent();
                }

                return $entry;
            }, $rows),
        ];
    }

    private function toolLastCurated(array $args): array
    {
        $curator = $this->mcpToken?->isCurator() === true;
        $raw = is_array($args['note_ids'] ?? null) ? $args['note_ids'] : [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $raw), static fn (int $id) => $id > 0)));
        if ($ids === []) {
            if (array_key_exists('note_ids', $args)) {
                throw new \InvalidArgumentException('note_ids was sent but empty — omit it entirely to ask about the knowledge base instead');
            }

            $preflight = $this->curationQueue->preflight();
            if (!$curator) {
                unset($preflight['last_run_by']);
            }

            return $preflight;
        }
        if (count($ids) > 500) {
            throw new \InvalidArgumentException('note_ids accepts at most 500 ids per call');
        }

        $conn = $this->em->getConnection();

        $known = array_map('intval', $conn->fetchFirstColumn(
            'SELECT id FROM notes WHERE id IN (:ids)',
            ['ids' => $ids],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
        ));

        $rows = $conn->fetchAllAssociative(
            'SELECT note_id, created_at, action, token_name, entries FROM (
                 SELECT cl.note_id, cl.created_at, cl.action, cl.token_name,
                        COUNT(*) OVER (PARTITION BY cl.note_id) AS entries,
                        ROW_NUMBER() OVER (PARTITION BY cl.note_id ORDER BY cl.created_at DESC, cl.id DESC) AS rn
                 FROM curator_log cl
                 WHERE cl.note_id IN (:ids)
                   AND '.CuratorLogEntry::curationWorkOnly('cl').'
             ) latest WHERE rn = 1',
            ['ids' => $known ?: [0]],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );
        $latest = [];
        foreach ($rows as $row) {
            $latest[(int) $row['note_id']] = $row;
        }

        $notes = [];
        foreach ($ids as $id) {
            if (!in_array($id, $known, true)) {
                continue;
            }
            $row = $latest[$id] ?? null;
            $entry = [
                'note_id' => $id,
                'last_at' => $row === null ? null : (new \DateTimeImmutable($row['created_at'], new \DateTimeZone('UTC')))->format(DATE_ATOM),
            ];
            if ($curator) {
                $entry['last_action'] = $row['action'] ?? null;
                $entry['last_by'] = $row['token_name'] ?? null;
                $entry['entries'] = (int) ($row['entries'] ?? 0);
            }
            $notes[] = $entry;
        }

        return [
            'notes' => $notes,
            'unknown_note_ids' => array_values(array_diff($ids, $known)),
        ];
    }

    private function toolCurationCandidates(array $args): array
    {
        $tokenId = $this->mcpToken?->getId();

        $paging = array_key_exists('offset', $args);
        $held = $paging ? [] : $this->curationLeases->heldElsewhere($tokenId);

        $result = $this->curationQueue->candidates(
            min(100, max(1, (int) ($args['limit'] ?? 25))),
            max(0, (int) ($args['offset'] ?? 0)),
            min(365, max(0, (int) ($args['cooldown_days'] ?? CurationQueue::DEFAULT_COOLDOWN_DAYS))),
            isset($args['reason']) ? (string) $args['reason'] : null,
            (bool) ($args['include_pending'] ?? false),
            isset($args['stale_days']) ? min(3650, max(1, (int) $args['stale_days'])) : null,
            $held,
        );

        if (!$paging) {
            $this->curationLeases->claim($tokenId, array_column($result['candidates'], 'note_id'));
        }

        if ($result['leased_elsewhere'] === 0) {
            unset($result['leased_elsewhere']);
        }

        return $result;
    }

    private function toolResolveCurationFlag(array $args): array
    {
        $token = $this->requireCurator('resolve_curation_flag');
        $note = $this->requireNote($args, 'note_id');

        if ($this->curationFlags->awaitingDestructiveReview($note)) {
            throw new \InvalidArgumentException(
                'This note already has a delete or merge awaiting the operator. Leave the flag open: '
                .'the note is out of the queue until that verdict lands, so nothing will re-propose it, '
                .'and the proposal IS your answer. Approval takes the note and its flag together; '
                .'rejection hands it back still flagged, which is what you would want if the operator says no.'
            );
        }

        $resolution = trim((string) ($args['resolution'] ?? ''));
        if ($resolution === '') {
            throw new \InvalidArgumentException('resolution is required: say what you did about the flag, or why you disagree with it');
        }
        if (mb_strlen($resolution) > 10000) {
            throw new \InvalidArgumentException('resolution must be at most 10000 characters');
        }

        $flag = $this->curationFlags->resolve($note, $token->getName(), $resolution, $token);

        return [
            'resolved' => true,
            'note_id' => $note->getId(),
            'flagged_at' => $flag->getCreatedAt()->format(DATE_ATOM),
            'resolution' => $resolution,
        ];
    }

    private function toolDuplicateCandidates(array $args): array
    {
        $this->requireCurator('duplicate_candidates');

        return $this->curationQueue->duplicates(
            isset($args['max_distance']) ? (float) $args['max_distance'] : null,
            min(100, max(1, (int) ($args['limit'] ?? 25))),
            max(0, (int) ($args['offset'] ?? 0)),
            (bool) ($args['include_pending'] ?? false),
        );
    }

    private function toolBlastRadius(array $args): array
    {
        return $this->curationQueue->blastRadius(
            min(365, max(1, (int) ($args['since_days'] ?? CurationQueue::DEFAULT_BLAST_RADIUS_DAYS))),
            min(100, max(1, (int) ($args['limit'] ?? 25))),
            max(0, (int) ($args['offset'] ?? 0)),
            (bool) ($args['include_pending'] ?? false),
        );
    }

    private function requireCurator(string $verb): ApiToken
    {
        $token = $this->mcpToken;
        if ($token === null || !$token->isCurator()) {
            throw new \InvalidArgumentException($verb.' is a curator verb — this token has the agent role');
        }

        return $token;
    }

    private function toolGetSkill(array $args): array
    {
        $slug = (string) ($args['slug'] ?? '');
        $skill = $this->findSkill($slug, 'auto');
        if ($skill === null) {
            $known = implode(', ', array_column($this->skills('auto'), 'slug'));
            throw new \InvalidArgumentException('Unknown skill: "'.$slug.'". Available: '.($known === '' ? '(none)' : $known));
        }

        $this->recordServe($skill, SkillServe::PATH_TOOL);
        if ($skill['slug'] === ShippedSkills::GUIDE) {
            $this->recordGuideRead();
        }

        return [
            'slug' => $skill['slug'],
            'title' => $skill['title'],
            'description' => $skill['description'],
            'updated_at' => $skill['updated_at'],
            'instructions' => $skill['body'],
        ];
    }

    /**
     * The first-run wizard's last step asks the person to have their
     * assistant open the memex guide, and this is how memex finds out it did.
     * Nothing else records what a connection READ.
     */
    private function recordGuideRead(): void
    {
        if ($this->mcpToken === null || $this->mcpToken->getGuideReadAt() !== null) {
            return;
        }
        $this->mcpToken->markGuideRead();
        $this->em->flush();
    }

    private function requireNote(array $args, string $key): Note
    {
        $id = (int) ($args[$key] ?? 0);
        $note = $id > 0 ? $this->em->find(Note::class, $id) : null;
        if ($note === null) {
            throw new \InvalidArgumentException('Note not found: '.$key.'='.$id);
        }

        return $note;
    }

    private function toolListTags(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT t.name, COUNT(nt.note_id) AS note_count
             FROM tags t LEFT JOIN note_tag nt ON nt.tag_id = t.id
             GROUP BY t.id, t.name ORDER BY '.SystemTags::sqlRank('t.name').', t.name'
        );

        $retired = $this->tagAdmin->retired();

        return [
            'tags' => array_map(static fn (array $r) => [
                'name' => $r['name'],
                'note_count' => (int) $r['note_count'],
            ] + (SystemTags::isSystem($r['name'])
                ? ['system' => true, 'system_effect' => self::SYSTEM_TAG_EFFECTS[$r['name']]]
                : []), $rows),
            'retired' => $retired,
        ];
    }

    /** @return string[] */
    private function tagNames(array $args): array
    {
        return !empty($args['tags']) && is_array($args['tags']) ? array_map('strval', $args['tags']) : [];
    }

    public static function summaryArg(array $args): ?string
    {
        return NoteWriter::normaliseSummary($args['summary'] ?? null);
    }

    /** @param array{note: Note, suggestions: array{tag_ids: int[], new_tags: string[]}} $result */
    private function writeResult(array $result): array
    {
        $out = [
            'note' => NoteJson::note($result['note'], $this->owner->mark()),
            'review' => 'Note saved with status "'.$result['note']->getStatus().'". Pending notes await operator approval in the review inbox.',
        ];
        $hints = $this->writeHints->forNote($result['note']);
        if ($hints !== []) {
            $out['hints'] = $hints;
            $out['hints_note'] = self::HINTS_NOTE;
        }
        $cited = $this->citedBy([(int) $result['note']->getId()], self::CITED_CREATE);
        if ($cited !== null) {
            $out['cited_by'] = $cited;
        }

        return $out;
    }

    private const HINTS_NOTE = 'These are things the server noticed about this note for free — nothing was written and nothing was spent. `duplicates`: notes already in the vault that are close to this one; read `reading` and open both with `get` before deciding, then `propose_merge` for a genuine twin. `links`: notes this text names but does not link, each with a ready anchored `find`/`replace` — send the ones you agree with straight to `propose(note_id:, patch:)`. `tags`: words already in this knowledge base\'s vocabulary that the text uses and this note is not filed under — apply with `propose(note_id:, tags:)`. Act on what is right and ignore the rest; none of it is a defect and none of it is required.';

    /**
     * @param int[] $noteIds notes the write touched
     * @return array<string, mixed>|null
     */
    private function citedBy(array $noteIds, string $says): ?array
    {
        $found = $this->curationQueue->citedBy($noteIds);
        if ($found['total'] === 0) {
            return null;
        }

        $named = count($found['notes']);

        return [
            'total' => $found['total'],
            'notes' => $found['notes'],
            'says' => str_replace(
                '{n notes}',
                $found['total'] === 1 ? '1 note' : $found['total'].' notes',
                $says
            ).($named < $found['total']
                    ? ' Named here: the '.$named.' least recently updated of them — `get` on this note returns all '
                        .$found['total'].' backlinks.'
                    : ''),
        ];
    }

    private const CITED_EDIT = 'Cited by {n notes}. An edit here can leave them describing something that is no longer true, and nothing in memex compares them for you. Read what your change touches (`get`) and correct what no longer agrees (`propose(note_id:, patch:)`). If they still agree, do nothing.';
    private const CITED_DELETE = 'Cited by {n notes}. If the operator approves the deletion their `[[links]]` stop resolving, and the text around them is left pointing at nothing. Say in your reason where they should point instead, or propose the edits that repoint them.';
    private const CITED_MERGE = 'Cited by {n notes}, across both of these. A merge does not break their links — they are repointed to the surviving note — so what changes for them is what they now point AT. Read them and correct any that no longer agree with the merged text.';
    private const CITED_CREATE = 'Already cited by {n notes}: they used this title as a `[[link]]` and it now resolves here. That match is by name alone, so it can be the wrong note — open them and check this is what they meant.';

    private const ENCODABLE_FIELDS = ['title', 'body_md', 'merged_body_md', 'comment', 'reason', 'description', 'query'];

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function decodeArgs(array $args): array
    {
        $encoding = $args['body_encoding'] ?? 'text';
        unset($args['body_encoding']);
        if ($encoding === 'text') {
            return $args;
        }
        if ($encoding !== 'base64') {
            throw new \InvalidArgumentException('body_encoding must be "text" or "base64"');
        }

        $decode = static function (mixed $value, string $field): string {
            if (!is_string($value)) {
                throw new \InvalidArgumentException("$field must be a base64 string when body_encoding=base64");
            }
            $plain = base64_decode($value, true);
            if ($plain === false) {
                throw new \InvalidArgumentException("$field is not valid base64 (body_encoding=base64 was set)");
            }
            if (!mb_check_encoding($plain, 'UTF-8')) {
                throw new \InvalidArgumentException("$field did not decode to UTF-8 text");
            }

            return $plain;
        };

        foreach (self::ENCODABLE_FIELDS as $field) {
            if (isset($args[$field])) {
                $args[$field] = $decode($args[$field], $field);
            }
        }

        if (isset($args['patch'])) {
            $patch = $args['patch'];
            if (is_string($patch)) {
                $parsed = json_decode($patch, true);
                if (is_array($parsed)) {
                    $patch = $parsed;
                }
            }
            if (is_array($patch)) {
                foreach ($patch as $i => $op) {
                    if (!is_array($op)) {
                        continue;
                    }
                    foreach (['find', 'replace'] as $field) {
                        if (isset($op[$field])) {
                            $patch[$i][$field] = $decode($op[$field], "patch[$i].$field");
                        }
                    }
                }
                $args['patch'] = $patch;
            }
        }

        return $args;
    }

    private const TOOL_SAFETY = [
        'search' => 'read',
        'get' => 'read',
        'inbox' => 'read',
        'log_recent' => 'read',
        'curation_candidates' => 'read',
        'duplicate_candidates' => 'read',
        'blast_radius' => 'read',
        'last_curated' => 'read',
        'needs_enrichment' => 'read',
        'list_skills' => 'read',
        'get_skill' => 'read',
        'list_tags' => 'read',
        'health' => 'read',
        'propose' => 'write',
        'log' => 'write',
        'resolve_curation_flag' => 'write',
        'propose_delete' => 'write',
        'propose_merge' => 'write',
    ];

    /** @return array<string, bool> the four hints for one safety class */
    private static function annotationsFor(string $class): array
    {
        return match ($class) {
            'read' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            'write' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
        };
    }

    private function toolDefinitions(): array
    {
        $curator = $this->mcpToken?->isCurator();
        $vectors = $this->space->model();
        $tags = ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tag names; unknown names are created. On edits this is the FULL replacement tag list (omit to keep current tags)'];

        $bodyEncoding = ['type' => 'string', 'enum' => ['text', 'base64'], 'default' => 'text', 'description' => 'Send "base64" ONLY as a retry: if a call fails with a raw Cloudflare HTML block page ("Sorry, you have been blocked"), a security filter on the connector path rejected your text for looking like shell commands. Re-send the SAME call with every free-text field (title, body_md, merged_body_md, comment, reason, description, query — and `find`/`replace` inside patch[]) base64-encoded and this flag set. Never encode ids, tags, or booleans. Content round-trips exactly, so runbooks keep their literal commands.'];

        $tools = [
            [
                'name' => 'search',
                'description' => 'Search or browse the knowledge base. With `query`: hybrid semantic+keyword ranking. WITHOUT `query`: lists notes, filtered by tags/status — use this to browse a tag or list all notes (do NOT pass a filler query with a tag filter; query and tags are ANDed and a non-matching query hides tagged notes). `order` + `offset` walk a result set page by page: `total` tells you how far it goes, and the sort is stable, so successive offsets neither skip nor repeat a note. Returns summaries; use `get` for full content.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Free-text query (semantic + keyword). Omit to list/browse — no wildcard needed ("*" is treated as no query). Operators, uppercase: `OR`, `NOT` (or `-word`), parentheses, and "quotes" for an exact phrase — `(memex OR memory) NOT ai`. A query with operators matches words as written and never falls back to meaning-search'],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only notes carrying ALL these tags. A name that matches no tag is ignored and reported back in `unknown_tags` — check it against list_tags rather than assuming the filter applied'],
                        'status' => ['type' => 'string', 'enum' => ['verified', 'pending'], 'description' => 'Filter by review status'],
                        'order' => ['type' => 'string', 'enum' => HybridSearch::ORDERS, 'description' => 'Result order. Default: relevance with a query, updated_desc without one. relevance = best match first (needs a query; ignored without). updated_asc = least-recently-changed first, the enumeration order for sweeps. created_asc/desc = by age in the KB, unaffected by later edits. An explicit order still applies the query filter, so "oldest notes matching X" works'],
                        'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Skip this many rows — pair with `order` and `limit` to page through everything; the response echoes `offset` and `returned` alongside `total`'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 25, 'default' => 10],
                        'body_encoding' => $bodyEncoding,
                    ],
                ],
            ],
            [
                'name' => 'get',
                'description' => 'Fetch a note by id: full markdown body, tags, wiki-links and backlinks, review status. A `curation_flag` in the response is the OPERATOR speaking about this note — what they say is wrong with it and what to pay attention to; weigh it above your own reading of the text, whatever you are here to do (a curator fixes it and resolves the flag, anyone else at least knows not to cite the note as settled). A `live_state_notice` means this note describes a system that CHANGES, and it is addressed to you whatever you are here to do: if your task changed anything the note describes, file the edit before you finish — the notice names the exact call. A `profile_notice` means this note is the owner\'s own profile: read it before answering about them, treat an unwritten section as unknown, and propose a patch only when the conversation shows a line has changed. `added_by` says which connection (or person) put the note here and `edited_by` who touched it last — provenance, never token material, so cite a note knowing where it came from. A body ending in a `## Sources` section names what to reread BEFORE acting on the note — documentation, a standard, a page — with what each is for; the note is the summary and the link is the authority. `body_chars` always reports the note\'s TRUE length, so you never have to count it yourself. Large notes: some agent runtimes refuse a tool result over a size cap, and you will see an error about exceeding maximum allowed tokens rather than the note — that is your runtime, not this server, and the note is still readable. Re-call with `max_chars` (10000 is a safe slice) and walk the note using `body_next_offset` until it comes back null. Do NOT give up on a note for being large, and do not judge or summarise one from a partial read without saying which part you read.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'Note id (from search results)'],
                        'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Start the body at this character. Pass back the `body_next_offset` from the previous call to continue'],
                        'max_chars' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Return at most this many characters of the body. Omit for the whole note. The response then carries `body_offset`, `body_truncated` and `body_next_offset` (null when you have reached the end)'],
                    ],
                    'required' => ['id'],
                ],
            ],
            [
                'name' => 'inbox',
                'description' => 'What is waiting for the operator in the review inbox — pending notes and held edit/delete/merge proposals. Read-only: nothing here approves or rejects anything, and only the operator can, in a browser. Use it to say what each waiting item IS and what approving it would do — including the work OTHER agents filed. `counts` is the whole inbox; the lists are capped by `limit`, so compare the two before saying "that is everything". Proposed bodies arrive only if you ask (`max_chars`); `proposed_body_chars` always tells you how long each one really is. For a pending note\'s own text, call `get` with its id.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 25, 'description' => 'Most items of EACH kind to return (oldest first, as the inbox lists them)'],
                        'max_chars' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Include each proposed body, cut to this many characters (`proposed_body_truncated` says when one was cut). Omit to get lengths only — an inbox holding an edit to a 95,000-character note is unreadable otherwise'],
                    ],
                ],
            ],
            [
                'name' => 'propose',
                'description' => 'Propose a new markdown note, or — with `note_id` — an edit to an existing note. New notes land PENDING; edits are HELD (the note stays unchanged) until the operator approves them in the review inbox. Exception: curator-role tokens apply creates/edits immediately (audit-logged); the response says which happened. Connect notes with [[Note Title]] wiki-links, never with a note\'s web address, id or number: those belong to one account and break in an export. End a new note\'s body with a `## Sources` section listing the pages you consulted — one entry per link, what it is for, when to reread it (`source_url` is where the note came from; Sources is what to consult next). **If your edits apply without review (curator role), a whole-body edit is HELD and an anchored `patch` is not.** That is not a rule about size: several curation runs may be working this knowledge base at once, and a full body silently overwrites whatever another one changed while you were reading, where an anchor that no longer fits is refused instead. Send `patch` and the change applies on the spot. **A held edit is your working copy, and you get ONE per note.** Proposing again on a note you already have in review REVISES that draft rather than queueing a second: fields you send replace the matching ones, anchors are appended to the anchors already held, and what you omit stands as filed. So correct a draft freely — but say the whole of what you mean, because the operator decides on one document, not on your sequence of attempts. Another connection\'s draft on the same note is untouched and stays its own item.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'note_id' => ['type' => 'integer', 'description' => 'Existing note to propose an edit to (from search/get). Omit to propose a new note. With note_id, every other field is optional — send ONLY what should change'],
                        'title' => ['type' => 'string', 'maxLength' => 500, 'description' => 'Required for new notes; for edits, the replacement title'],
                        'body_md' => ['type' => 'string', 'description' => 'Markdown body; [[wiki-links]] supported. Required for new notes; for edits, the FULL replacement body. To change PART of an existing note, use `patch` instead — it is safer on a long note and does not overwrite somebody else\'s held edit'],
                        'patch' => [
                            'type' => 'array',
                            'maxItems' => 50,
                            'description' => 'Change PART of a note instead of resending all of it: ordered {find, replace} operations, applied in order. Prefer this to `body_md` on any note long enough that resending it is a transcription risk — a correction to three sentences should not regenerate forty thousand characters. `find` is matched LITERALLY (no regex) and must appear EXACTLY ONCE: zero matches means the note is not what you think and the call is refused; more than one means you have not said which, so quote more of the surrounding text. `replace` may be "" to delete. Mutually exclusive with `body_md`. Held patches are applied to the note AS IT THEN STANDS at approval, so two patches to different parts of one note both land instead of overwriting each other.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'find' => ['type' => 'string', 'description' => 'The exact text to replace, copied from the note. Must occur exactly once'],
                                    'replace' => ['type' => 'string', 'description' => 'What it becomes. Empty string deletes the text'],
                                ],
                                'required' => ['find', 'replace'],
                            ],
                        ],

                        'summary' => ['type' => 'string', 'maxLength' => 5000, 'description' => 'A short description of what this note contains, written by YOU. Send it with every new note — you are already holding the text, so describing it costs you nothing extra, and memex then makes no summarizing call of its own. With note_id it is an edit like any other, and a summary ALONE is a complete edit: that is how you describe a note that has none. TWO OR THREE SENTENCES, about '.self::SUMMARY_SOFT_CAP.' characters, plain, no preamble: what the note is about and what a reader would come to it for. This is a hint that helps somebody decide whether to open the note, NOT an abstract of it — a description that has to be read in full has failed at its job'],
                        'tags' => $tags,
                        'source_url' => ['type' => 'string', 'description' => 'Provenance URL, if any (new notes only)'],
                        'change_title' => ['type' => 'string', 'maxLength' => 120, 'description' => 'SIX OR SEVEN WORDS saying what this edit does and why — a subject line, not a sentence. "Correct the deploy host after the move", "Add the missing arming period". It is what the operator sees in the review inbox and in the note\'s history, so it is the line that decides whether they open the row: a title reading "update note" wastes the only glance they give it. Different from `comment`, which is the prose underneath'],
                        'comment' => ['type' => 'string', 'description' => 'For edits: a REPORT of what the change does, under `change_title`. '.FilingRules::SENTENCE.', saying what the note says now — and where there is more than one, '.FilingRules::BULLETS.', in the order they appear in the note. Evidence a sentence needs (a note id, a quoted phrase) goes inside that sentence. Nothing else: no "if you approve / if you reject", no account of your reasoning or your uncertainty, no restatement of the note. The one thing that may be added is an observation or a tooling gap you have no other place to file — that goes '.FilingRules::OBSERVATION.'. The operator is looking at the diff and reads this in seconds, usually on a phone — an essay here is a rejection waiting to happen no matter how right it was. Markdown renders, so write '.FilingRules::LINE_BREAKS],
                        'hold' => ['type' => 'boolean', 'default' => false, 'description' => 'Curator tokens only: true = hold this edit for operator review instead of auto-applying — the sanctioned way to express uncertainty; the approve/reject verdict is the answer. Frame the comment so approving confirms your judgment.'],
                        'body_encoding' => $bodyEncoding,
                    ],
                ],
            ],
            [
                'name' => 'propose_delete',
                'description' => 'Propose retiring a note (duplicate, superseded, dead). HELD for review: the note stays until the operator approves. Give a reason the operator can decide from — which note supersedes it, why it has no forward value. For a duplicate with a clear keeper, prefer propose_merge.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'note_id' => ['type' => 'integer', 'description' => 'Note to retire (from search/get)'],
                        'reason' => ['type' => 'string', 'description' => 'Why this note should be deleted: '.FilingRules::SENTENCE.', saying what goes and what supersedes it — shown to the operator in the review inbox, where it is read in seconds. No essay; when memex answers that the note is cited, one more sentence says '.FilingRules::CITED],
                        'body_encoding' => $bodyEncoding,
                    ],
                    'required' => ['note_id', 'reason'],
                ],
            ],
            [
                'name' => 'propose_merge',
                'description' => 'Propose folding a duplicate/superseded note into a keeper. HELD for review: both notes stay until the operator approves; then the keeper inherits the absorbed note\'s tags and backlinks, and the absorbed note is deleted. Optionally supply merged_body_md when the keeper\'s body should also change (e.g. to absorb unique facts) — it is the keeper\'s FULL replacement body.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'note_id' => ['type' => 'integer', 'description' => 'The note to absorb (it will be deleted on approval)'],
                        'into_note_id' => ['type' => 'integer', 'description' => 'The keeper note (it survives)'],
                        'merged_body_md' => ['type' => 'string', 'description' => 'Optional FULL replacement body for the keeper, when the merge should carry content over (not a diff). Omit if the keeper already covers everything'],
                        'comment' => ['type' => 'string', 'description' => 'Why these notes should merge: '.FilingRules::SENTENCE.', saying what is absorbed and what survives it — shown to the operator in the review inbox, where it is read in seconds. Where the merged body also changes what the keeper says, '.FilingRules::BULLETS.'; no essay either way'],
                        'body_encoding' => $bodyEncoding,
                    ],
                    'required' => ['note_id', 'into_note_id'],
                ],
            ],
            [
                'name' => 'needs_enrichment',
                'description' => 'The enrichment backlog, oldest first: notes that want describing and are free to be worked right now. Two kinds of row. `missing` lists what is absent, no summary or no tags, for content that arrived with nobody there to describe it (an upload, or a note typed on the website). `requested: true` is the OTHER kind: a person ticked "flag for enrichment" on the note form and asked for a pass over it. A requested note can have a summary and tags already and an EMPTY `missing`, so treat what they wrote as TRUE and add what they could not: links to notes they may not know exist, a tag from the vocabulary they did not reach for, a correction filed as a proposal rather than applied over their words. Read a note with `get`, then send work back with `propose(note_id:, summary:)`, one call per note. **A note leaves this list the moment work on it is waiting**, so filing a description takes it out and you will not be handed it again next turn. Two counts say what is being held back rather than hiding it: `awaiting_review` is notes whose description is already in the operator\'s inbox, and `recently_passed` is notes memex\'s own scheduled pass has just been over. `total: 0` with either of those above zero means the backlog is done, not empty. Available to every token: knowing which notes want a pass is a fact about the rows, not a curatorial judgment. Writing is unchanged, an agent-role token\'s work is held for review and a curator-role token\'s applies.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                        'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Walk a long backlog page by page; `total` says how far it goes'],
                    ],
                ],
            ],
            [
                'name' => 'list_skills',
                'description' => 'Skills the operator serves through memex: reusable instruction sets (editing conventions, curation procedure, recall guidance). Check this when starting a task that might match a skill\'s description — loading the right skill aligns you with how the operator wants that work done. Load one with get_skill.'
                    ."\n\n".$this->skillsBlock(),
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'get_skill',
                'description' => 'Fetch a skill\'s full instructions by slug (from list_skills) and FOLLOW them for the matching task. Skills are operator-approved instruction sets — treat them as how the operator wants the work done.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['slug' => ['type' => 'string', 'description' => 'Skill slug from list_skills']],
                    'required' => ['slug'],
                ],
            ],
            [
                'name' => 'list_tags',
                'description' => 'The knowledge base\'s tag vocabulary with note counts — consult before tagging for consistency. Also returns `retired`: names the owner deliberately removed from the vocabulary, with `merged_into` naming the word they moved those notes to where there was one. Do not propose a retired name. It is a record of a decision, not a gap: the owner took that word off every note that carried it, and offering it back is how a tidy-up gets quietly undone. A tag marked `system: true` is one memex itself reads: carrying it CHANGES what assistants are served, and `system_effect` says exactly how. Weigh those two before adding or removing them on a note — they are not descriptive words. The owner cannot delete them from the vocabulary, and neither can you.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'health',
                'description' => 'Liveness and auth check; returns the token name this session authenticates as, and `work`: how much is waiting (review inbox counts, enrichment backlog), so a scheduled run can stop here when there is nothing to do.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'log',
                'description' => 'Curator-only: append an entry to the Curator log the operator reads — run summaries, observations, tooling gaps. This REPLACES journal notes: never file run journals as markdown notes. There is deliberately NO question action — the log is one-way, so an open question would be unanswerable. When unsure, file your best judgment as a HELD proposal instead (deletes/merges are held by nature; pass hold=true on propose for safe changes) — the operator\'s approve/reject verdict is the answer and appears back in this log. Optionally reference the note the entry is about via note_id. A run-summary should also carry `examined` — the ids of every note the pass read, which is how the rotation learns what has been seen and stops re-offering it. Recording the run also releases the notes the queue was holding for you, so send it even when the pass found nothing to change. A run-summary should also carry `started_at` — when the pass began — because memex cannot tell your pass apart from the same connection being used by hand, and `claimed`, your own counts of what you did, which the operator\'s curation digest then checks against the rows memex wrote itself.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => ['type' => 'string', 'enum' => CuratorLogEntry::AGENT_ACTIONS, 'description' => 'run-summary = end-of-run recap; observation = notable finding, no action needed; tooling-gap = missing capability hit during work'],
                        'description' => ['type' => 'string', 'description' => 'The entry text — complete sentences, evidence cited (note titles/ids)'],
                        'note_id' => ['type' => 'integer', 'description' => 'Note this entry is about, if any'],
                        'examined' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'CLOSE YOUR RUN WITH THIS. Every note id the pass actually READ, including the ones that turned out to need nothing — that is the half nothing else records. A note you read and left alone is otherwise indistinguishable from one nobody has ever opened, so without this list your next pass re-reads the same notes forever and never reaches the ones behind them. It is also what lets a settled note rest longer each time a pass approves of it (7, 14, 28, 56 days), so the budget goes to notes that have not been read rather than to the same evergreen cluster. Send the ids you genuinely opened, not the ids the queue offered you. If one still carries a defect you decided was a false positive, list it anyway — you are told so in `still_defective` and the record keeps your judgment repeatable.',
                        ],
                        'started_at' => [
                            'type' => 'string',
                            'description' => 'Run-summary only: when this pass BEGAN, ISO-8601 (e.g. "2026-08-26T02:05:00Z"). Send it. Memex cannot work it out — your connection writes when a pass runs and when somebody drives it by hand, and the rows look identical — so without this the operator\'s digest can only summarise everything your connection has done since its last pass, which mixes your run in with work that was not part of it. It is also what marks your work as CURATION in the operator\'s journal, which they filter on, and what lets `claimed` be checked at all: neither is ever held against a run whose boundary memex does not know.',
                        ],
                        'claimed' => [
                            'type' => 'object',
                            'description' => 'Run-summary only: what you say this pass did, as counts. The operator\'s digest shows these beside the rows memex logged and names any that disagree — so this is how a pass gets CHECKED rather than taken at its word, and a discrepancy is usually a write you believed landed that did not. Claim only what you counted: a key you leave out is not compared and nothing is held against you, while `0` is a claim that you changed nothing of that kind and can be contradicted.',
                            'properties' => [
                                'edited' => ['type' => 'integer', 'minimum' => 0, 'maximum' => CurationDigest::CLAIM_MAX, 'description' => 'Notes you changed that APPLIED on the spot — your role\'s safe writes'],
                                'held' => ['type' => 'integer', 'minimum' => 0, 'maximum' => CurationDigest::CLAIM_MAX, 'description' => 'Safe changes you sent for review instead of applying — hold=true, or a whole-body edit the server held for you'],
                                'proposed' => ['type' => 'integer', 'minimum' => 0, 'maximum' => CurationDigest::CLAIM_MAX, 'description' => 'Deletes and merges you asked the operator to decide. These are held from every role, so the count is what is waiting on a verdict'],
                            ],
                            'additionalProperties' => false,
                        ],
                        'body_encoding' => $bodyEncoding,
                    ],
                    'required' => ['action', 'description'],
                ],
            ],
            [
                'name' => 'log_recent',
                'description' => 'Curator-only: read Curator log entries, newest first. Bootstrap with this every run: operator approved/rejected rows are the VERDICTS on your held items, and your last run-summary says where the previous pass stopped. A verdict may carry `operator_comment` — the operator\'s reasoning in their own words, which OUTRANKS your judgment on that case; read it as an instruction, not as feedback to weigh. `is_precedent: true` means the operator marked that reasoning as general: apply it to comparable cases from now on. Reasoning WITHOUT the flag binds only the case it was written about — do not generalise it, and never read a bare verdict (no comment at all) as blessing a pattern. A pattern you want made standing is a separate proposal to amend the charter, reviewed as policy. With `note_id` it becomes one note\'s history instead — everything ever done to that note and how the operator ruled on it; read that before touching a note you have worked on before, so you do not re-propose something already rejected.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'note_id' => ['type' => 'integer', 'description' => 'Only entries about this note (its full curation history, including the operator\'s approve/reject verdicts on proposals you filed against it). Omit for the whole log'],
                        'action' => ['type' => 'string', 'enum' => CuratorLogEntry::ACTIONS, 'description' => 'Only entries of this kind, e.g. "rejected" to review every verdict against you, or "run-summary" to read past passes'],
                        'precedent_only' => ['type' => 'boolean', 'default' => false, 'description' => 'Only verdicts whose reasoning the operator marked as general — the standing guidance you have been given. Read this at the start of a run as well as the recent entries: precedent set weeks ago is far outside the newest 40 rows, and guidance you never retrieved is guidance you will break'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 40],
                        'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Skip this many entries — page further back through the log; the response carries the matching `total`'],
                    ],
                ],
            ],
            [
                'name' => 'curation_candidates',
                'description' => 'The work queue — which notes need attention and WHY, ranked. Start every run here instead of browsing notes hoping to spot problems; the database checks every note in the vault, you only read the ones it names. **A candidate carrying `operator_flag` is the operator telling you, in their own words, what is wrong with that note — read `operator_flag.comment` as an instruction that outranks your own reading of the note, do that work FIRST, and close it with `resolve_curation_flag` saying what you did.** Flagged notes sort ahead of everything else, ignore the cooldown, and keep coming back every run until you resolve them — EXCEPT while a delete or merge you filed for that note is awaiting the operator, when the note drops out of this queue entirely and its flag stays open, so never re-propose something already in the inbox; if you think the flag is mistaken, say so in the resolution rather than leaving it open or quietly skipping it. Each candidate carries `reasons` (structural defects found), `defects` (how many, flags NOT counted), and `last_curated_at` (null = never). `reason_counts` is the census of everything ELIGIBLE this run — notes on cooldown excluded, but deliberately NOT narrowed by the `reason` you filtered to, so the numbers stay comparable across reasons and mean the same thing whether you filter or not. Beside it, `never_read` counts the eligible notes no pass has ever opened and `defect_free` counts every eligible note with no structural defect and no flag — the difference between them is notes read and approved before, back because their earned rest expired, which is different work — read it, because those notes are the work `reason_counts` cannot show you and it is not derivable from the census (a note with three defects appears in three of those tallies). Ranking: notes with defects first, most-broken first; then notes whose NEIGHBOURS changed since the note was last read (`changed_neighbours` — the staleness a note\'s own timestamp never shows); then never-curated; then longest-unseen. An EMPTY RESULT — no candidates at all — means there is nothing to do; end the run rather than inventing work. `reason_counts` all zero does NOT mean that, and reading it that way is the one failure this verb has actually produced: the census counts structural defects only, so zero there means nothing is BROKEN, while the rows underneath it are the notes no pass has ever read. Those are the work — contradictions, staleness, `live-state` membership, tag hygiene, missing links, naming — and none of them is a defect a counter can see. `total` is how much queue there is; the census is only what is broken in it. Rest is EARNED, not fixed: each consecutive pass that reads a note and finds nothing doubles its rest — 7, 14, 28, then 56 days (`clean_passes` and `rest_days` on every row) — so an evergreen cluster stops consuming the budget and the notes behind it get reached. Rest is cut short the moment anything happens the note has not been read against: someone else edits it, a linked note changes, the operator flags it, or a structural defect appears. Working ONE `reason` at a time makes a coherent pass ("tag the 12 untagged notes") — that is usually better than fixing one note six ways. **Available to every role, since 2026-08-26.** What the curator role gates is DOING curation, not seeing what needs it: any connection may read this queue and file proposals against it, which are HELD for the operator\'s review like every other agent write. Curator-role connections additionally record runs in the Curator log (`log`), resolve the operator\'s flags (`resolve_curation_flag`), and have safe edits apply without review. If you hold the agent role and are asked to curate, say exactly that — you can work the queue and leave everything for review, and only the owner can grant the curator role, from Settings › Assistants › Note maintenance in a browser. Never improvise a curation pass without this queue: the ranking, the cooldown and the operator\'s flags are the whole point of it. **Notes another connection is currently working are not offered to you**, so several curation runs can share one knowledge base without doing the same work twice; `leased_elsewhere` appears when that happened and says how many, and `total` is always the whole queue rather than your slice of it. A claim is released when you record the run with `log`, and expires on its own within the half hour if you never do — so a pass that dies holds nothing for long. **Passing `offset` turns that off**: an offset walk is enumerating the queue rather than taking a batch, so it is filtered by nothing and claims nothing — take a batch with `limit` alone and let the queue hand you the next one.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'reason' => ['type' => 'string', 'enum' => CurationQueue::reasons(), 'description' => 'Only notes with this defect. operator_flag (the operator flagged this note and said why — always work these first) / untagged / no_summary / not_embedded (invisible to semantic search) / disconnected (no links in or out) / dangling_links (wiki-links pointing nowhere) / nonstandard_tags (tag with spaces or capitals — the vocabulary is lower-case kebab) / weak_title (filename- or slug-shaped; a heuristic, so judge it rather than trusting it)'],
                        'stale_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3650, 'description' => 'Only notes no pass has read in this many days. Notes never read are INCLUDED — unread is unbounded staleness, and a filter for old work that hid the oldest work would answer the wrong question. Narrows `candidates` and `total`; `reason_counts` stays the census of the whole eligible queue, exactly as it does under `reason`'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                        'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Skip this many candidates; `total` says how many there are'],
                        'cooldown_days' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 365, 'default' => CurationQueue::DEFAULT_COOLDOWN_DAYS, 'description' => 'Hold back notes you curated within this many days (0 = show everything, for a deliberate re-sweep)'],
                        'include_pending' => ['type' => 'boolean', 'default' => false, 'description' => 'Also return pending notes. They are ADVISORY only: your edits to them are held for review, so use this to escalate (a duplicate at the gate, a contradiction with verified content), never to quietly rewrite what an agent proposed'],
                    ],
                ],
            ],
            [
                'name' => 'resolve_curation_flag',
                'description' => 'Curator-only: close the operator flag on a note by saying what you did about it. Call this ONCE the flagged work is done or decided — an open flag ignores the cooldown and comes back on every run until it is resolved, which is deliberate: the operator asked for something and is owed an answer. The `resolution` is required and is written into the Curator log where the operator reads it, so make it specific — what you changed (with the proposal it went into), or why you did not. Disagreeing IS a valid resolution ("the note is current; note 88 confirms the host moved back") and so is being unable to act ("this needs information the vault does not contain"). What is NOT valid is resolving a flag you did not actually engage with: the operator can re-flag, and the log shows both sides of the exchange. This changes no note and is not held for review — it is an acknowledgment, not a write. It REFUSES when the note already has a delete or merge awaiting the operator: filing a deletion is not performing it, so that flag stays open and the note simply drops out of the queue until the verdict lands — nothing will re-propose it meanwhile, approval takes note and flag together, and rejection hands it back still flagged. File the proposal and move on; the proposal is the answer.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'note_id' => ['type' => 'integer', 'description' => 'The flagged note (from `curation_candidates`, where it arrives with `operator_flag`)'],
                        'resolution' => ['type' => 'string', 'description' => 'What you did about the flag, or why you did not — complete sentences, evidence cited, ids named'],
                        'body_encoding' => $bodyEncoding,
                    ],
                    'required' => ['note_id', 'resolution'],
                ],
            ],
            [
                'name' => 'duplicate_candidates',
                'description' => 'Curator-only: pairs of notes that may be the same document, by cosine distance over the stored embeddings — closest first. This finds what you cannot: judging that two notes duplicate each other means holding both in mind at once, so reading alone only ever catches the pairs that happen to land in one run. Every note is measured against every other as its vector is written, at no token cost; `unsettled` counts notes still being measured, whose pairs may be missing until a later call. Reading the distance: **≤'.$vectors->duplicateCertain().' is near-certain** (typically an import twin — same title with an em-dash instead of a hyphen); **'.$vectors->duplicateCertain().'-'.$vectors->duplicateTopical().' is related but distinct**, often a distillation and the archive it cites, which should usually NOT be merged. ALWAYS open both notes with `get` before proposing anything — the vector says "similar", not "redundant" — and pick the keeper on content, not on which id is lower. Pairs with a merge or delete already awaiting review are omitted; **rejected pairs are NOT**, because a rejection leaves no pair-level record, so check `log_recent(note_id: …)` for a prior verdict before re-proposing a pair you have proposed before.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'max_distance' => ['type' => 'number', 'minimum' => 0.01, 'maximum' => $vectors->neighbourHorizon(), 'default' => $vectors->duplicateDistance(), 'description' => 'Cosine-distance ceiling. The default is calibrated for this knowledge base\'s embedding model; raising it past ~'.$vectors->duplicateTopical().' returns topical neighbours (two lectures from one course) rather than duplicates'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                        'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                        'include_pending' => ['type' => 'boolean', 'default' => false, 'description' => 'Also pair against pending notes — catching a duplicate AT the review gate costs the operator one click, where catching it after approval costs a merge proposal. Escalate what you find; do not rewrite pending notes'],
                    ],
                ],
            ],
            [
                'name' => 'blast_radius',
                'description' => 'Notes whose NEIGHBOURS changed recently — the stale-by-association queue. This catches what nothing else can. When note A changes, the notes that link to it can go quietly wrong (the runbook still cites the host that moved, the roster still lists the agent that was renamed), and B\'s own timestamp never moves, so no recency sweep will ever look at B again. Each result carries `changed` — which neighbours moved, when, and who moved them — so start by reading those, then check whether this note still agrees with them. Ranked by how many neighbours moved. Your OWN edits are excluded from what counts as a change, deliberately: otherwise curating one note would enqueue its neighbours, and curating those would re-enqueue the first, forever. Only what the operator, an agent or an import changed counts.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'since_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => CurationQueue::DEFAULT_BLAST_RADIUS_DAYS, 'description' => 'How far back a neighbour\'s change still counts. This is a window, not a log: a change older than this ages out unexamined, so widen it after a long gap between runs'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25, 'description' => 'Also the drain cap — a bulk import makes every imported note a change, and this is what keeps that from becoming one enormous run'],
                        'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                        'include_pending' => ['type' => 'boolean', 'default' => false, 'description' => 'Also return affected notes that are pending (advisory only — your edits there are held)'],
                    ],
                ],
            ],
            [
                'name' => 'last_curated',
                'description' => 'For a batch of note ids, when each was last CURATED. With the curator role the row also says by whom, with what action, and how many entries the note has in the Curator log; without it you get the timestamp alone, which is what tells you whether a note has just been worked — the Curator log itself is the operator\'s audit and is not read by agent-role connections. This is how you tell what you have already looked at — there is no "last curated" field on a note; the log IS the record. Rows where the operator ASKED for work rather than work being done — `flag-raised` — are excluded, so flagging a note never makes it look freshly curated. Use it to skip recently-curated notes and to find the ones never curated at all (`last_at: null`, the strongest claim on a pass). Send the whole candidate set in ONE call, not one call per note. Ids that no longer exist come back in `unknown_note_ids` — a deleted note is not an uncurated one. **CALL IT WITH NO ARGUMENTS AT ALL for the preflight**: the same question about the whole knowledge base rather than about notes — when it was last curated and by what, how many notes have changed since (your own curator edits excluded), how much is in the queue right now, and `due` with `due_reasons` saying what kind of work is waiting. That is the call to make before deciding a run is worth a turn, and the one for a scheduled job to poll — **hourly is the right cadence and fifteen minutes is the floor**. Not politeness: this answer is computed from your whole curation history, which grows with every pass ever logged rather than with the size of the knowledge base, so a tight loop costs real work on the server for an answer that cannot have changed. Nothing that can flip `due` — a new note, someone else\'s edit, a neighbour changing, a rest period expiring — happens on a sub-hour cadence. `due` carries no cadence of its own — it is simply "the queue is not empty", and the queue has already applied the cooldown and the earned rest, so nothing here decides WHEN you should run. Read `due_reasons` rather than `reason_counts` alone: the census counts structural defects, so a queue made entirely of sound notes nobody has read reports all zeroes, and the reasons say so in words.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'note_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'minItems' => 1,
                            'maxItems' => 500,
                            'description' => 'Note ids to look up (from search). OMIT ENTIRELY to ask about the knowledge base instead of about notes — see the preflight in this tool\'s description. Sending an empty list is refused rather than treated as omitted',
                        ],
                    ],
                ],
            ],
        ];

        $writing = $this->personalization()['writing'] === true;
        $tools = array_map(static function (array $tool) use ($writing): array {
            if ($writing && $tool['name'] === 'propose') {
                $tool['description'] .= ' '.MemexWriting::PROPOSE_SENTENCE;
            }
            $class = self::TOOL_SAFETY[$tool['name']] ?? null;
            if ($class !== null) {
                $tool['annotations'] = self::annotationsFor($class);
            }

            return $tool;
        }, $tools);

        if ($curator) {
            return $tools;
        }

        return array_values(array_filter(
            $tools,
            static fn (array $tool) => !in_array($tool['name'], self::CURATOR_TOOLS, true)
        ));
    }

    private function toolResult(mixed $id, array $data, bool $isError = false): array
    {
        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            'structuredContent' => $data,
            'isError' => $isError,
        ]);
    }

    private function rpcResult(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function rpcError(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
