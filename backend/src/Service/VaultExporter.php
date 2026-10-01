<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Note;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Every note in the vault, as a zip of markdown files.
 *
 * Shared by two callers on purpose. `ExportController::exportAll` serves it
 * over HTTP to the signed-in user. `app:export-vault` writes it straight to
 * disk for the nightly backup, which runs **on the same box as the vaults** and therefore has no
 * business making an authenticated round trip through Cloudflare to read a
 * table it is sitting on — that would put a live bearer token in
 * `/etc/memex-backup.env` forever, to buy nothing.
 *
 * One implementation rather than two, because the failure mode of two would be
 * a backup that quietly stops matching what the product exports.
 */
class VaultExporter
{
    /** Room for a collision suffix and `.md` under the common 255-byte file-name limit. */
    private const FILENAME_BYTES = 200;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Build the archive at `$path` and report how many notes went into it.
     *
     * **Pending notes are included.** A backup that drops everything an agent
     * filed and nobody has reviewed yet has a hole exactly where the newest
     * work is — and "not yet approved" is not "not worth keeping".
     */
    public function writeArchive(string $path): int
    {
        $ids = $this->em->getConnection()->fetchFirstColumn('SELECT id FROM notes ORDER BY id');

        $repo = $this->em->getRepository(Note::class);
        $notes = (function () use ($ids, $repo): \Generator {
            foreach ($ids as $id) {
                $note = $repo->find((int) $id);
                if ($note === null) {
                    continue;
                }
                try {
                    yield $note;
                } finally {
                    $this->em->detach($note);
                }
            }
        })();

        return $this->writeNotes($notes, $path);
    }

    /** @param iterable<Note> $notes */
    public function writeNotes(iterable $notes, string $path): int
    {
        $zip = new \ZipArchive();
        if (@$zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not open '.$path.' for writing');
        }

        $files = [];
        $folders = [];
        $added = 0;
        $closed = false;
        $complete = false;
        try {
            foreach ($notes as $note) {
                $name = self::freeName(substr($this->archivePath($note), 0, -3), (int) $note->getId(), $files, $folders);
                $parts = explode('/', $name);
                array_pop($parts);
                $folder = '';
                foreach ($parts as $part) {
                    $folder = ltrim($folder.'/'.$part, '/');
                    $key = mb_strtolower($folder);
                    if (isset($files[$key])) {
                        [$taken, $id] = $files[$key];
                        unset($files[$key]);
                        $aside = self::freeName(substr($taken, 0, -3), $id, $files, $folders + [$key => true]);
                        if (!$zip->renameName($taken, $aside)) {
                            throw new \RuntimeException('Could not rename '.$taken.' in export: '.$zip->getStatusString());
                        }
                        $files[mb_strtolower($aside)] = [$aside, $id];
                    }
                    $folders[$key] = true;
                }
                $files[mb_strtolower($name)] = [$name, (int) $note->getId()];
                if (!$zip->addFromString($name, $this->toMarkdown($note))) {
                    throw new \RuntimeException('Could not add '.$name.' to export: '.$zip->getStatusString());
                }
                ++$added;
            }
            $closed = true;
            if (!@$zip->close()) {
                throw new \RuntimeException('Could not finish export archive '.$path);
            }
            $complete = true;

            return $added;
        } finally {
            if (!$closed) {
                @$zip->close();
            }
            if (!$complete && is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * `$base.md`, or with the note's number added when a file or a folder
     * already has that name, compared case-insensitively.
     *
     * @param array<string, array{0: string, 1: int}> $files
     * @param array<string, true>                     $folders
     */
    private static function freeName(string $base, int $id, array $files, array $folders): string
    {
        $name = $base.'.md';
        for ($suffix = 0; isset($files[mb_strtolower($name)]) || isset($folders[mb_strtolower($name)]); ++$suffix) {
            $name = $base.'-'.$id.($suffix === 0 ? '' : '-'.$suffix).'.md';
        }

        return $name;
    }

    /**
     * One note as markdown with YAML frontmatter — the no-lock-in promise
     * (PRODUCT.md §Export) in its concrete form.
     */
    public function toMarkdown(Note $note): string
    {
        $tags = array_map(static fn ($t) => $t->getName(), SystemTags::sortBy($note->getTags()->toArray(), static fn ($t) => $t->getName()));
        $frontmatter = "---\n";
        $frontmatter .= 'title: '.json_encode($note->getTitle(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        if ($tags !== []) {
            $frontmatter .= 'tags: '.json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        }
        // The description and WHO wrote it. Both were missing until 2026-08-21,
        // so the artifact PRODUCT.md calls the no-lock-in promise — and the
        // nightly markdown backup, which is this same method — silently dropped
        // the summaries that became first-class two days earlier, and with them
        // the claim that the markdown export is the whole note.
        //
        // json_encode for the same reason `title` uses it: a summary is free
        // text with colons and quotes in it, and an unquoted YAML scalar would
        // turn the frontmatter into something the parser below silently drops.
        if ($note->getSummary() !== null) {
            $frontmatter .= 'summary: '.json_encode($note->getSummary(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
            if ($note->getSummaryBy() !== null) {
                $frontmatter .= 'summary_by: '.json_encode($note->getSummaryBy(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
            }
        }
        $frontmatter .= 'source: '.$note->getSource()."\n";
        if ($note->getSourceUrl() !== null) {
            $frontmatter .= 'source_url: '.json_encode($note->getSourceUrl(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        }
        $frontmatter .= 'status: '.$note->getStatus()."\n";
        $frontmatter .= 'created: '.$note->getCreatedAt()->format(DATE_ATOM)."\n";
        $frontmatter .= 'updated: '.$note->getUpdatedAt()->format(DATE_ATOM)."\n";
        $frontmatter .= "---\n\n";

        return $frontmatter.$note->getBodyMd();
    }

    /**
     * Where a note sits in the archive: the path it was imported from, so a
     * vault comes back out in the shape it went in and its path links still
     * open, or else its title, at the top.
     */
    public function archivePath(Note $note): string
    {
        $segments = [];
        foreach (explode('/', (string) $note->getImportPath()) as $segment) {
            $segment = self::safeName($segment, $note);
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }

        return $segments === [] ? $this->filename($note) : implode('/', $segments).'.md';
    }

    /** The note's title as a file name, so `[[Title]]` opens it in Obsidian, which finds a note by its file name. */
    public function filename(Note $note): string
    {
        $name = self::safeName($note->getTitle(), $note);

        return ($name === '' ? 'note-'.$note->getId() : $name).'.md';
    }

    /**
     * Whether a top-level file's name says nothing its title does not: the
     * title as this exporter names a file, or the slug memex's export named
     * files by until 2026-09-27, which a vault exported then still carries.
     * Import keeps no path for such a file, so the note exports by its title.
     */
    public static function namedByTitle(string $fileBase, string $title): bool
    {
        if ($fileBase === self::cleanName($title)) {
            return true;
        }
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9\-\x{00C0}-\x{024F}]+/u', '-', $title), '-'));

        return $slug === '' ? preg_match('/\Anote-\d+\z/', $fileBase) === 1 : $fileBase === mb_substr($slug, 0, 80);
    }

    /** Windows also refuses its device names. */
    private static function safeName(string $name, Note $note): string
    {
        $name = self::cleanName($name);
        if (preg_match('/\A(?:con|prn|aux|nul|com\d|lpt\d)\z/i', $name) === 1) {
            $name .= '-'.$note->getId();
        }

        return $name;
    }

    /**
     * Characters a file name cannot hold on some system, or that Obsidian
     * reads as link syntax, become a space; a leading dot would hide the file
     * and a trailing one is refused by Windows.
     */
    private static function cleanName(string $name): string
    {
        $name = (string) preg_replace('/(?:\s*[\x00-\x1F\x7F\\\\\/:*?"<>|#^\[\]])+\s*/u', ' ', $name);

        return trim(mb_strcut($name, 0, self::FILENAME_BYTES, 'UTF-8'), ' .');
    }
}
