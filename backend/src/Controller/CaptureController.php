<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\ReleasesSession;
use App\Entity\CuratorLogEntry;
use App\Entity\Note;
use App\Entity\Tag;
use App\Service\EmbeddingSpend;
use App\Service\AnalyzeAllowance;
use App\Service\EnrichmentSettings;
use App\Service\FrontmatterParser;
use App\Service\Journal;
use App\Service\MlClient;
use App\Service\NoteWriter;
use App\Service\SpendLimiter;
use App\Service\StorageLimitExceeded;
use App\Service\StorageLimits;
use App\Service\WriteHints;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CaptureController extends ApiController
{
    /**
     * Most files one /api/upload request may carry. Each one becomes a note
     * and buys an embedding, so an uncapped request is unbounded work.
     */
    public const MAX_UPLOAD_FILES = 20;

    public function __construct(
        private readonly NoteWriter $noteWriter,
        private readonly FrontmatterParser $frontmatter,
        private readonly MlClient $mlClient,
        private readonly WriteHints $writeHints,
        private readonly EnrichmentSettings $settings,
        private readonly SpendLimiter $spendLimiter,
        private readonly EntityManagerInterface $em,
        private readonly StorageLimits $storageLimits,
        private readonly AnalyzeAllowance $allowance,
        private readonly Journal $journal,
    ) {
    }

    /**
     * Check a draft BEFORE it is saved — Analyze first, Save second (operator,
     * 2026-08-24). Optional, and it writes nothing.
     *
     * **This is the button that was removed on 2026-08-23, brought back after
     * the reason for removing it stopped being true.** It went because it
     * spent the operator's money the instant it was pressed, and a user may
     * have configured no provider of their own: *"Calling it immediately
     * doesn't work, as users might not have any API specified for enrichment,
     * and I don't want them to use my API."* That is now structurally
     * impossible for the expensive half — every text call takes
     * {@see \App\Service\AiCredentials} with no default and returns nothing
     * when the vault has not switched server-side AI on, so a vault with no key
     * of its own cannot reach `summarize`, `suggest-tags` or `suggest-title`
     * at all.
     *
     * What is left on the operator's account is ONE embedding, which is the
     * standing arrangement for every vector in the product (CLAUDE.md §Spend:
     * one model, one key, one vector space, for every vault on the box) and is
     * bounded per vault by {@see SpendLimiter::assertAnalyze()}. A save would
     * have bought the same embedding a moment later; pressing this buys it a
     * moment earlier, and buys the chance to not create the duplicate at all.
     *
     * Two of the three checks cost nothing whatsoever — the link and tag
     * candidates in {@see WriteHints} are string matching against the vault's
     * own titles and vocabulary — so a knowledge base with no AI configured
     * still gets a useful answer from this button rather than an empty card.
     *
     * Spend per click: 1 embedding always; 1 summarize + 1 suggest-tags only
     * when the vault has its own key, plus 1 title completion when the title is
     * also filename-shaped.
     */
    /** What the editor's assistant says before anybody presses Analyze. */
    #[Route('/api/analyze', methods: ['GET'])]
    public function analyzeAllowance(): JsonResponse
    {
        return $this->json($this->allowance->current());
    }

    #[ReleasesSession]
    #[Route('/api/analyze', methods: ['POST'])]
    public function analyze(Request $request): JsonResponse
    {
        $data = $request->toArray();

        $title = trim((string) ($data['title'] ?? ''));
        $bodyMd = (string) ($data['body_md'] ?? '');
        if (trim($bodyMd) === '') {
            return $this->json($this->json400('body_md is required'), Response::HTTP_BAD_REQUEST);
        }
        $creds = $this->settings->forVault();
        // After validation, never before: a limit charged for a malformed
        // request is allowance spent on nothing. Both ceilings in one call:
        // this click buys an embedding AND, when the credentials will
        // generate, three text calls on this box's account. Two separate
        // asserts charged the first and then refused on the second.
        $this->spendLimiter->assertAnalyze($creds->textEnabled);
        // The note being edited, so it is not reported as its own duplicate.
        $noteId = isset($data['note_id']) && is_numeric($data['note_id'])
            ? (int) $this->noteByNumber($this->em, (int) $data['note_id'])->getId()
            : null;

        // Same prep as enrichment: title + body, whitespace collapsed, capped.
        $text = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $title.' '.$bodyMd)), 0, 20000);

        // All three text calls below are refused when the vault has server-side
        // AI switched off, so the box returns duplicates and nothing else. The
        // form says why rather than looking broken.
        $summary = $this->mlClient->summarize($text, $creds);

        $vocab = [];
        foreach ($this->em->getRepository(Tag::class)->findAll() as $tag) {
            $vocab[] = ['id' => $tag->getId(), 'name' => $tag->getName()];
        }
        $suggestions = $this->mlClient->suggestTags($title, $text, $vocab, $creds);

        $suggestedTitle = Note::isWeakTitle($title) ? $this->mlClient->suggestTitle($text, $creds) : null;
        // Never suggest the title the operator already has.
        if ($suggestedTitle !== null && mb_strtolower($suggestedTitle) === mb_strtolower($title)) {
            $suggestedTitle = null;
        }

        if ($creds->isOwnAccount() && ($summary !== null || $suggestedTitle !== null || $suggestions['tag_ids'] !== [] || $suggestions['new_tags'] !== [])) {
            $this->settings->markUsed($creds->credentialId);
        }

        // The one deliberate purchase on this path, and the reason `forDraft`
        // takes a vector rather than fetching one: a draft has no note yet, so
        // it has no stored embedding to read. A query's short timeout is the
        // right one here — a slow ml-processor degrades this card to "no
        // duplicates" instead of hanging the button.
        //
        // The link and tag halves of the answer do not depend on it and come
        // back either way.
        $vector = $this->mlClient->embedDraft($text);

        return $this->json([
            'summary' => $summary,
            'suggested_tags' => $suggestions,
            'suggested_title' => $suggestedTitle,
            // So the form can explain an empty answer instead of the operator
            // wondering whether the model had nothing to say.
            'ai_enabled' => $creds->textEnabled,
            'allowance' => $this->allowance->current(),
            // The same three checks, in the same shape, that every MCP write
            // returns (App\Service\WriteHints). One definition, so the person
            // at the form and the agent at the API are shown one answer to
            // "does this already exist, what does it name, what is it about"
            // rather than two that can drift.
            'hints' => $this->writeHints->forDraft(
                $title,
                $bodyMd,
                $vector,
                $noteId,
                array_map('strval', is_array($data['tags'] ?? null) ? $data['tags'] : []),
            ),
        ]);
    }

    /**
     * File upload: .md / .txt files become notes. YAML frontmatter (title, tags)
     * is honored when present; otherwise the filename is the title.
     */
    #[ReleasesSession]
    #[Route('/api/upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $token = $this->requestToken($request);

        $files = $request->files->all();
        $flat = [];
        array_walk_recursive($files, function ($file) use (&$flat) { $flat[] = $file; });
        // The 2026-08-24 audit reported that a null slot in the multipart
        // body reaches this loop and 500s on getClientOriginalName(). TESTED,
        // AND IT DOES NOT: Symfony's FileBag drops null leaves before the
        // controller sees them, so an empty file input already produces the
        // "No files uploaded" 400 below. The filter stays as one line making
        // the invariant explicit for a caller that does not come through
        // HTTP, but it is not the fix for a live bug — recorded here because
        // a guard with no reachable failure is exactly the kind of thing that
        // gets mistaken for one later.
        $flat = array_values(array_filter($flat, static fn ($f) => $f instanceof UploadedFile));
        if ($flat === []) {
            return $this->json($this->json400('No files uploaded'), Response::HTTP_BAD_REQUEST);
        }
        // One request created one note PER FILE, with no cap of any kind in
        // application code — so a single POST was unbounded server work, and
        // (before the embedding limit) an unbounded number of purchases on the
        // operator's key. The spend is now bounded at the write; this bounds
        // the REQUEST, which is a different problem with a different fix.
        //
        // Twenty is above any hand-assembled upload — somebody moving a real
        // vault in has the import panel, which is the path built for volume
        // and deliberately does not enrich inline.
        if (count($flat) > self::MAX_UPLOAD_FILES) {
            return $this->json(
                $this->json400(sprintf(
                    'Too many files in one upload (%d). Send at most %d at a time, or use Import for a whole vault.',
                    count($flat),
                    self::MAX_UPLOAD_FILES
                )),
                Response::HTTP_BAD_REQUEST
            );
        }

        // Every file is read, parsed and sized before any note is created, so
        // a file that cannot be saved is named beside the ones that can rather
        // than discovered after some of them already are.
        $accepted = [];
        $errors = [];
        foreach ($flat as $file) {
            $name = $file->getClientOriginalName();
            $ext = strtolower($file->getClientOriginalExtension());
            if (!in_array($ext, ['md', 'txt', 'markdown'], true)) {
                $errors[] = ['file' => $name, 'error' => 'Only .md and .txt files are supported'];
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if ($content === false || trim($content) === '') {
                $errors[] = ['file' => $name, 'error' => 'File is empty or unreadable'];
                continue;
            }

            try {
                $parsed = $this->frontmatter->parse($content, pathinfo($name, PATHINFO_FILENAME));
                $this->storageLimits->assertBody($parsed['body']);
            } catch (\InvalidArgumentException|StorageLimitExceeded $error) {
                $errors[] = ['file' => $name, 'error' => $error->getMessage()];
                continue;
            }
            $accepted[] = ['name' => $name, 'parsed' => $parsed];
        }

        $created = [];
        $write = function () use ($accepted, $token, &$created, &$errors): void {
            foreach ($accepted as $index => ['name' => $name, 'parsed' => $parsed]) {
                try {
                    $result = $this->noteWriter->create(
                        $token,
                        mb_substr($parsed['title'], 0, 500),
                        $parsed['body'],
                        Note::SOURCE_UPLOAD,
                        null,
                        $parsed['tags'],
                        // One embedding PER FILE, and this route takes as many files
                        // as a multipart body can carry — see the cap above, which is
                        // the other half of the same finding.
                        enrich: EmbeddingSpend::Metered,
                        summary: $parsed['summary'],
                        summaryBy: $parsed['summary_by'],
                        createdAt: $parsed['created'],
                        updatedAt: $parsed['updated'],
                    );
                } catch (\Throwable $error) {
                    $limit = StorageLimitExceeded::fromThrowable($error);
                    if ($limit === null) {
                        throw $error;
                    }
                    // The memex is full, or the body limit moved between the check
                    // above and the row, whose failed flush closed the entity manager.
                    // Either way the rest of the batch is not attempted: what was
                    // saved is reported as saved, and what was not is named, so a
                    // retry duplicates nothing.
                    $errors[] = ['file' => $name, 'error' => $limit->getMessage()];
                    foreach (array_slice($accepted, $index + 1) as ['name' => $left]) {
                        $errors[] = ['file' => $left, 'error' => 'Not attempted: an earlier file in this upload was refused. Upload it again.'];
                    }
                    break;
                }
                $created[] = $this->noteToArray($result['note']);
            }
        };
        // The owner's upload is one act and one row. A connection's is a held
        // note per file, each its own item in the inbox, so each keeps its row
        // and its place in that note's history.
        if ($token === null) {
            $this->journal->batch($write, static fn (array $notes): CuratorLogEntry => new CuratorLogEntry(
                'operator',
                CuratorLogEntry::ACTION_IMPORT,
                count($notes) === 1 ? 'Uploaded 1 note' : 'Uploaded '.count($notes).' notes',
            ));
        } else {
            $write();
        }

        return $this->json(
            ['created' => $created, 'errors' => $errors],
            $created !== [] ? Response::HTTP_CREATED : Response::HTTP_BAD_REQUEST
        );
    }

}
