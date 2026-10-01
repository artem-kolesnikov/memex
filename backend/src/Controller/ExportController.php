<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\ReleasesSession;
use App\Entity\Note;
use App\Service\HybridSearch;
use App\Service\VaultExporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Markdown export — the no-lock-in promise (PRODUCT.md §Export). Single note as
 * .md with YAML frontmatter; any filter result as a zip of .md files. Pending
 * notes are excluded unless explicitly included.
 */
class ExportController extends ApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HybridSearch $hybridSearch,
        private readonly VaultExporter $exporter,
    ) {
    }

    #[Route('/api/notes/{id}/export', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function exportNote(int $id): Response
    {
        $note = $this->noteByNumber($this->em, $id);

        return new Response($this->toMarkdown($note), Response::HTTP_OK, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $this->filename($note),
                (string) preg_replace('/[^\x20-\x7E]|%/', '_', $this->filename($note)),
            ),
        ]);
    }

    /**
     * Everything, as markdown, with no selection and no cap.
     *
     * Separate from `exportSet` rather than a flag on it, because it answers a
     * different question. That one exports *a selection* and is capped at a
     * page of search results — which is right for "export these", and is why it
     * could not be the backup: the vault passed 100 notes long ago, so the
     * existing endpoint would have handed back a confident, silent subset.
     *
     * Editing is recoverable (`note_revisions`), but that is recovery *inside*
     * the product; this is the copy that survives losing the product. Pending notes are included: a backup that drops
     * everything an agent filed and nobody has reviewed yet is a backup with a
     * hole exactly where the newest work is.
     */
    #[ReleasesSession]
    #[Route('/api/export/all', methods: ['GET'])]
    public function exportAll(Request $request): Response
    {
        return $this->archiveResponse(
            fn (string $path): int => $this->exporter->writeArchive($path),
            'This vault has no notes to export',
            'memex-vault-'.date('Ymd-His').'.zip',
            true,
        );
    }

    #[ReleasesSession]
    #[Route('/api/export', methods: ['GET'])]
    public function exportSet(Request $request): Response
    {
        $includePending = $request->query->getBoolean('include_pending');
        $tagIds = array_values(array_filter(array_map('intval', explode(',', $request->query->get('tags', ''))), static fn (int $i) => $i > 0));
        $ids = array_values(array_filter(array_map('intval', explode(',', $request->query->get('ids', ''))), static fn (int $i) => $i > 0));
        $query = $request->query->get('q');
        if ($query === null && $tagIds === [] && $ids === []) {
            return $this->json($this->json400('Provide ids, q or tags to select the note set'), Response::HTTP_BAD_REQUEST);
        }

        if ($ids !== []) {
            // Explicit selection (the list's export bar): the operator picked the
            // rows, so pending notes are included when selected.
            $rows = array_map(
                static fn (int $id) => ['id' => $id],
                array_slice($ids, 0, 500),
            );
        } else {
            $result = $this->hybridSearch->search(
                $query,
                $tagIds,
                null,
                $includePending ? null : Note::STATUS_VERIFIED,
                1,
                100,
            );
            $rows = $result['items'];
            // `semantic_unavailable` is deliberately not surfaced here, and the
            // reason is a property rather than an oversight: the vector tier
            // only ever runs when NOTHING matches the query literally, so a
            // degraded search cannot return a partial set — it returns an empty
            // one, and the 404 below is what the caller gets. An export is the
            // one place a quietly-incomplete answer would be unrecoverable, and
            // this path cannot produce one.
        }
        if ($rows === []) {
            return $this->json($this->json400('No notes match the selection'), Response::HTTP_NOT_FOUND);
        }

        $repo = $this->em->getRepository(Note::class);
        $notes = (static function () use ($rows, $repo): \Generator {
            foreach ($rows as $row) {
                $note = $repo->find($row['id']);
                if ($note !== null) {
                    yield $note;
                }
            }
        })();
        return $this->archiveResponse(
            fn (string $path): int => $this->exporter->writeNotes($notes, $path),
            'No notes match the selection',
            'memex-export.zip',
        );
    }

    private function archiveResponse(callable $write, string $emptyMessage, string $filename, bool $includeCount = false): Response
    {
        $path = @tempnam(sys_get_temp_dir(), 'memex-export-');
        if ($path === false) {
            throw new \RuntimeException('Could not create export temporary file');
        }
        try {
            $added = $write($path);
            if ($added === 0) {
                return $this->json($this->json400($emptyMessage), Response::HTTP_NOT_FOUND);
            }
            $content = @file_get_contents($path);
            if ($content === false) {
                throw new \RuntimeException('Could not read export archive '.$path);
            }
            $headers = [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ];
            if ($includeCount) {
                $headers['X-Memex-Note-Count'] = (string) $added;
            }

            return new Response($content, Response::HTTP_OK, $headers);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    // The two helpers below are one-line delegates on purpose. Both
    // implementations moved into `VaultExporter` when the nightly backup
    // started building the same archive from a console command: two copies of
    // "what a note looks like as markdown" would drift, and the day they did,
    // the backup would stop matching the export.
    private function toMarkdown(Note $note): string
    {
        return $this->exporter->toMarkdown($note);
    }

    private function filename(Note $note): string
    {
        return $this->exporter->filename($note);
    }
}
