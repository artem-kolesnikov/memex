<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\ReleasesSession;
use App\Entity\CuratorLogEntry;
use App\Entity\Note;
use App\Service\BoundedImportArchive;
use App\Service\FrontmatterParser;
use App\Service\GrowthLimits;
use App\Service\Journal;
use App\Service\NoteEnricher;
use App\Service\NoteLimbo;
use App\Service\NoteWriter;
use App\Service\StorageLimitExceeded;
use App\Service\StorageLimits;
use App\Service\VaultExporter;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vault import: a zip of .md/.txt files becomes notes (PRODUCT.md MVP2 —
 * "vault import is a first-class migration"). Two-phase: dry_run=1 analyzes
 * the archive (nothing written), then the confirmed call imports.
 *
 * **Session-only**, unlike every other capture path — see the guard in
 * `import()` for why: this one reaches past the review gate by design.
 *
 * Notes are created WITHOUT inline AI enrichment — a large vault would hang
 * the request and burst OpenAI spend; the memex-embed sweep (15 min) backfills
 * embeddings, mm2's proven pattern. Wiki-links resolve regardless of file
 * order via syncLinks' catch-up.
 */
class ImportController extends ApiController
{
    private const ALLOWED_EXTENSIONS = ['md', 'markdown', 'txt'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NoteWriter $noteWriter,
        private readonly FrontmatterParser $frontmatter,
        private readonly NoteEnricher $enricher,
        private readonly NoteLimbo $limbo,
        private readonly StorageLimits $storageLimits,
        private readonly BoundedImportArchive $archive,
        private readonly GrowthLimits $growth,
        private readonly Journal $journal,
    ) {
    }

    #[ReleasesSession]
    #[Route('/api/import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        // Session-only (L-1, closed 2026-08-22). Import is not an ordinary
        // write and never was: it backfills `import_path` on notes that already
        // EXIST with a raw UPDATE, then runs a relink pass that can rewire
        // wiki-links inside verified notes — both outside `NoteWriter` and
        // therefore outside the review gate. So a review-gated agent token,
        // whose every ordinary write is held for a person to read, could change
        // the operator's verified notes by uploading a zip. Nothing but the
        // import panel in the SPA has ever called this.
        $this->assertSessionAuth($request, 'Vault import');

        $file = $request->files->get('archive');
        if ($file === null) {
            return $this->json($this->json400('Upload a zip file as "archive"'), Response::HTTP_BAD_REQUEST);
        }

        $zip = new \ZipArchive();
        if ($zip->open($file->getPathname()) !== true) {
            return $this->json($this->json400('Not a readable zip archive'), Response::HTTP_BAD_REQUEST);
        }
        try {
            return $this->importArchive($request, $zip);
        } catch (\InvalidArgumentException $error) {
            return $this->json($this->json400($error->getMessage()), Response::HTTP_BAD_REQUEST);
        } finally {
            $zip->close();
        }
    }

    private function importArchive(Request $request, \ZipArchive $zip): JsonResponse
    {
        $limits = $this->storageLimits->current();
        // Every entry's declared size is summed against the import limit before
        // a byte is read, hidden and unsupported files included: the limit is
        // on what the archive expands to, not on what memex would keep.
        $entries = $this->archive->preflight($zip, $limits['max_import_bytes']);
        $dryRun = $request->query->getBoolean('dry_run') || $request->request->getBoolean('dry_run');
        $skipDuplicates = $this->boolOption($request, 'skip_duplicates', true);
        // Opt-in, and deliberately so: bringing back a note the operator
        // deleted is a decision, not a default. See the tombstone filter below.
        $resurrectRetired = $this->boolOption($request, 'resurrect_retired', false);
        $folderTags = $this->boolOption($request, 'folder_tags', false);
        $extraTags = array_values(array_filter(array_map('trim', explode(',', (string) $request->request->get('tags', '')))));

        $seenTitles = [];
        $importable = [];
        $duplicates = [];
        $skipped = [];

        foreach ($entries as $i => $entry) {
            $name = $entry['name'];
            $base = basename($name);
            // Archive junk and hidden trees (.obsidian/, .git/, __MACOSX/…): silent.
            if (str_starts_with($name, '__MACOSX/') || str_starts_with($base, '.') || preg_match('#(^|/)\.[^/]#', $name)) {
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                $skipped[] = ['file' => $name, 'reason' => 'unsupported type'];
                continue;
            }
            if ($entry['size'] > $limits['max_note_bytes']) {
                $skipped[] = ['file' => $name, 'reason' => 'file too large (max '.$limits['max_note_bytes'].' bytes, including frontmatter)'];
                continue;
            }
            $content = $this->archive->read($zip, $i, $entry, $limits['max_note_bytes']);
            if (trim($content) === '') {
                $skipped[] = ['file' => $name, 'reason' => 'empty file'];
                continue;
            }

            $parsed = $this->parseFile($content, $name);
            $title = mb_substr($parsed['title'], 0, 500);
            $titleKey = mb_strtolower($title);
            $importPath = mb_substr(preg_replace('/\.[^.\/]+$/', '', $name) ?? $name, 0, 600);
            if (VaultExporter::namedByTitle($importPath, $title)) {
                $importPath = null;
            }

            if (isset($seenTitles[$titleKey])) {
                $skipped[] = ['file' => $name, 'reason' => 'duplicate title within archive'];
                continue;
            }
            $seenTitles[$titleKey] = true;

            // Bodies are read again at creation time rather than held here, so
            // a two-thousand-file archive costs one note of memory, not all.
            $importable[] = ['index' => $i, 'title' => $title, 'file' => $name, 'path' => $importPath];
            unset($content, $parsed);
        }

        // Nothing below is reached unless every file parsed, so a bad archive
        // refuses before the duplicate backfill writes anything.
        $existingTitles = $this->existingTitles(array_column($importable, 'title'));
        $backfills = [];
        $keep = [];
        foreach ($importable as $item) {
            $key = mb_strtolower($item['title']);
            if (isset($existingTitles[$key])) {
                $duplicates[] = $item['title'];
                if ($skipDuplicates) {
                    // Metadata backfill on re-import: an earlier import may
                    // predate import_path (link identity) — record it now.
                    $backfills[] = ['path' => $item['path'], 'key' => $key];
                    continue;
                }
            }
            $keep[] = $item;
        }
        $importable = $keep;

        // A title that was deliberately RETIRED is not a new note. Import
        // dedupes against notes that exist, and a retired one does not, so
        // without this a re-import silently resurrects everything curation
        // removed — the failure the permanent tombstone exists to catch.
        $retired = $this->limbo->tombstonesByTitle(array_column($importable, 'title'));

        // …and then ACT on it. Computing this list and importing anyway was
        // the whole bug: the guard existed, was documented as the reason the
        // permanent tombstone exists, and ran only in the dry-run branch —
        // whose result the interface did not display either. So the ordinary
        // case, re-importing the zip the nightly backup just produced, put
        // every curated-away note back with nothing said. Retired titles are
        // now held out of the import and named in the response; the operator
        // asks for them back with resurrect_retired=1 if that is what they
        // meant.
        $retiredHeld = [];
        if (!$resurrectRetired && $retired !== []) {
            $keep = [];
            foreach ($importable as $item) {
                $key = mb_strtolower($item['title']);
                if (isset($retired[$key])) {
                    $retiredHeld[] = $item['title'];
                    continue;
                }
                $keep[] = $item;
            }
            $importable = $keep;
        }

        $retiredReport = array_values(array_map(
            static fn (array $r): array => [
                'title' => $r['title'],
                'note_id' => $r['note_id'],
                'deleted_at' => $r['deleted_at'],
                'reason' => $r['deleted_reason'],
                'restorable' => !$r['purged'],
            ],
            $retired
        ));

        if ($dryRun) {
            return $this->json([
                'dry_run' => true,
                'importable' => count($importable),
                'titles' => array_column(array_slice($importable, 0, 50), 'title'),
                'duplicates' => $duplicates,
                'previously_retired' => $retiredReport,
                'previously_retired_held' => $retiredHeld,
                'skipped' => $skipped,
                'note' => 'Embeddings are generated by the background sweep after import (~15 min); search works progressively.',
            ]);
        }

        // The whole archive or none of it: refused before the backfill below
        // writes anything, rather than stopping at the file that met the limit.
        if ($importable !== []) {
            $this->growth->assertNoteRoom(count($importable));
        }

        foreach ($backfills as $parameters) {
            $this->em->getConnection()->executeStatement(
                'UPDATE notes SET import_path = :path
                 WHERE LOWER(title) = :key AND import_path IS NULL',
                $parameters
            );
        }

        $created = [];
        $createdCount = 0;
        $refused = $this->journal->batch(function () use ($importable, $zip, $entries, $limits, $extraTags, $folderTags, &$created, &$createdCount): ?JsonResponse {
            foreach ($importable as $item) {
                $content = $this->archive->read($zip, $item['index'], $entries[$item['index']], $limits['max_note_bytes']);
                $parsed = $this->parseFile($content, $item['file']);
                $tags = array_merge($parsed['tags'], $extraTags);
                if ($folderTags) {
                    $folder = strtolower(trim(explode('/', $item['file'])[0]));
                    if ($folder !== '' && $folder !== basename($item['file']) && !str_contains($folder, '.')) {
                        $tags[] = $folder;
                    }
                }
                try {
                    $result = $this->noteWriter->create(
                        // No token, by construction: the guard above means the only
                        // caller is a person at the import panel, so these notes land
                        // verified and attributed to them.
                        null,
                        $item['title'],
                        $parsed['body'],
                        Note::SOURCE_UPLOAD,
                        null,
                        $tags,
                        enrich: null,
                        importPath: $item['path'],
                        // Carried so a vault exported from memex imports back whole.
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
                    $payload = $limit->payload();
                    if ($createdCount > 0) {
                        $payload['error'] = $createdCount.' files were imported before '.$item['file'].' reached the storage limit. '.$payload['error'];
                    }

                    return $this->json($payload + ['created' => $createdCount, 'notes' => $created, 'failed_file' => $item['file']], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
                }
                ++$createdCount;
                if (count($created) < 50) {
                    $created[] = ['id' => $result['note']->getId(), 'title' => $result['note']->getTitle()];
                }
                $this->em->detach($result['note']);
                foreach ($result['note']->getTags() as $tag) {
                    $this->em->detach($tag);
                }
                unset($result, $parsed, $content);
                // Detached PersistentCollections still form owner cycles; release
                // each body now instead of waiting for PHP's GC threshold.
                gc_collect_cycles();
            }

            return null;
        }, static fn (array $notes): CuratorLogEntry => new CuratorLogEntry(
            'operator',
            CuratorLogEntry::ACTION_IMPORT,
            count($notes) === 1 ? 'Imported 1 note' : 'Imported '.count($notes).' notes',
        ));
        if ($refused !== null) {
            return $refused;
        }

        // Backfilled import_paths (duplicate skips above) may satisfy links
        // that predate them — one relink pass covers everything.
        $relinked = $this->enricher->relinkUnresolved();

        return $this->json([
            'created' => $createdCount,
            'notes' => $created,
            'duplicates_skipped' => $skipDuplicates ? $duplicates : [],
            // Named on the confirmed response too, not just the dry run. A
            // caller that never asks for the analysis — the MCP surface, curl,
            // a script — is exactly the one that needs telling.
            'previously_retired' => $retiredReport,
            'previously_retired_held' => $retiredHeld,
            'resurrected_retired' => $resurrectRetired,
            'skipped' => $skipped,
            'links_resolved' => $relinked,
        ], $createdCount > 0 || $relinked > 0 ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /**
     * @param string[] $candidates
     * @return array<string, true> lowercased titles
     */
    private function existingTitles(array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }
        $titles = $this->em->getConnection()->fetchFirstColumn(
            'SELECT LOWER(title) FROM notes WHERE LOWER(title) IN (:titles)',
            ['titles' => array_map('mb_strtolower', $candidates)],
            ['titles' => ArrayParameterType::STRING]
        );

        return array_fill_keys($titles, true);
    }

    /** @return array{title: string, body: string, tags: string[], summary: ?string, summary_by: ?string, created: ?\DateTimeImmutable, updated: ?\DateTimeImmutable} */
    private function parseFile(string $content, string $name): array
    {
        try {
            return $this->frontmatter->parse($content, pathinfo(basename($name), PATHINFO_FILENAME));
        } catch (\InvalidArgumentException $error) {
            throw new \InvalidArgumentException($name.': '.$error->getMessage(), previous: $error);
        }
    }

    private function boolOption(Request $request, string $name, bool $default): bool
    {
        $value = $request->request->get($name);

        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
