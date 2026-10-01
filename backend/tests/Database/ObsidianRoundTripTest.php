<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\FrontmatterParser;
use App\Tests\Support\Tenant;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * An Obsidian vault goes in, comes out as a vault Obsidian opens, and goes
 * into another account whole: the notes, the facts their frontmatter carries,
 * and every link.
 */
final class ObsidianRoundTripTest extends ApiTestCase
{
    private const TITLES = ['Alpha', 'Beta', 'Gamma: the third', 'Delta: the fourth'];

    public function testAVaultSurvivesImportExportAndImportIntoAnotherAccount(): void
    {
        $this->kb->a->note('Delta: the fourth', "Delta was written here and links to [[Beta]].\n");

        $this->import($this->kb->a, $this->zip($this->obsidianVault()));
        self::assertSame(201, $this->httpStatus());
        $report = $this->jsonResponse();
        self::assertSame(3, $report['created']);
        self::assertSame([['file' => 'attachments/diagram.png', 'reason' => 'unsupported type']], $report['skipped']);

        $first = $this->facts($this->kb->a);
        self::assertSame(['2023-04-01 00:00:00', '2023-04-01 00:00:00'], [$first['Alpha']['created'], $first['Alpha']['updated']]);
        self::assertSame(['alpha', 'project'], $first['Alpha']['tags']);
        self::assertSame(['2022-01-02 01:04:05', '2024-05-06 07:08:09'], [$first['Gamma: the third']['created'], $first['Gamma: the third']['updated']]);
        self::assertSame(['gamma', 'project'], $first['Gamma: the third']['tags']);
        self::assertSame(['What Gamma holds.', 'Claude'], [$first['Gamma: the third']['summary'], $first['Gamma: the third']['summary_by']]);
        self::assertSame(
            ['Alpha' => 'Projects/Alpha', 'Beta' => null, 'Gamma: the third' => 'Projects/Gamma', 'Delta: the fourth' => null],
            array_map(static fn (array $note): ?string => $note['path'], $first),
            'a folder is kept; a top-level name that is only the title is not',
        );
        $links = $this->links($this->kb->a);
        self::assertSame([
            ['Alpha', 'Beta', 'Beta'],
            ['Alpha', 'Projects/Gamma', 'Gamma: the third'],
            ['Alpha', 'diagram.png', null],
            ['Beta', 'Alpha', 'Alpha'],
            ['Beta', 'Gamma: the third', 'Gamma: the third'],
            ['Delta: the fourth', 'Beta', 'Beta'],
            ['Gamma: the third', 'Delta: the fourth', 'Delta: the fourth'],
        ], $links);

        $archive = $this->export($this->kb->a);
        self::assertSame(['Beta.md', 'Delta the fourth.md', 'Projects/Alpha.md', 'Projects/Gamma.md'], array_keys($archive));
        $parser = new FrontmatterParser();
        foreach ($archive as $path => $content) {
            $parsed = $parser->parse($content, 'fallback');
            self::assertSame($first[$parsed['title']]['body'], $parsed['body'], $path.' keeps its text byte for byte');
        }
        foreach (['Beta', 'Alpha', 'Projects/Gamma'] as $target) {
            self::assertTrue(self::opensInObsidian($target, array_keys($archive)), '[['.$target.']] opens in Obsidian');
        }

        $this->import($this->kb->b, $this->zip($archive));
        self::assertSame(201, $this->httpStatus());
        self::assertSame(4, $this->jsonResponse()['created']);

        $second = $this->facts($this->kb->b);
        self::assertSame(
            ['Alpha' => 'Projects/Alpha', 'Beta' => null, 'Gamma: the third' => 'Projects/Gamma', 'Delta: the fourth' => null],
            array_map(static fn (array $note): ?string => $note['path'], $second),
        );
        foreach (self::TITLES as $title) {
            unset($first[$title]['path'], $second[$title]['path']);
            self::assertSame($first[$title], $second[$title], $title.' arrives whole');
        }
        self::assertSame($links, $this->links($this->kb->b));
        self::assertSame(array_keys($archive), array_keys($this->export($this->kb->b)), 'A second export has the same shape');
    }

    public function testAnExportFromBeforeTitleFileNamesComesBackNamedByTitle(): void
    {
        $this->import($this->kb->a, $this->zip([
            'memex-tools-todo.md' => self::exportedBefore('memex.tools — TODO', "Open work. See [[The Family Packing List]].\n"),
            'note-12.md' => self::exportedBefore('Заметка о переезде', "Кириллица.\n"),
            'the-family-packing-list.md' => self::exportedBefore('The Family Packing List', "Links to [[memex.tools — TODO]].\n"),
            'alpha-notes.md' => "---\ntitle: Alpha\n---\nA file name chosen by hand.\n",
        ]));
        self::assertSame(201, $this->httpStatus());
        self::assertSame(4, $this->jsonResponse()['created']);

        $this->in($this->kb->a);
        self::assertSame(
            ['Alpha' => 'alpha-notes', 'The Family Packing List' => null, 'memex.tools — TODO' => null, 'Заметка о переезде' => null],
            $this->em->getConnection()->fetchAllKeyValue("SELECT title, import_path FROM notes WHERE title IN ('Alpha', 'The Family Packing List', 'memex.tools — TODO', 'Заметка о переезде') ORDER BY title"),
        );
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM note_links WHERE to_note_id IS NULL'));

        $this->loginAs($this->kb->a);
        $this->client->request('GET', '/api/export/all');
        $path = tempnam(sys_get_temp_dir(), 'memex-export-');
        file_put_contents($path, $this->body());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();
        unlink($path);
        foreach (['alpha-notes.md', 'The Family Packing List.md', 'memex.tools — TODO.md', 'Заметка о переезде.md'] as $name) {
            self::assertContains($name, $names);
        }
    }

    /** A note as memex's export wrote it before files were named by title. */
    private static function exportedBefore(string $title, string $body): string
    {
        return "---\ntitle: ".json_encode($title, JSON_UNESCAPED_UNICODE)."\ntags: [\"memex\"]\nsource: manual\nstatus: verified\ncreated: 2025-03-04T05:06:07+00:00\nupdated: 2026-09-01T10:00:00+00:00\n---\n\n".$body;
    }

    public function testOneUploadedNoteKeepsItsDatesToo(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'memex-upload-');
        file_put_contents($path, "---\ntitle: \"Gamma: the third\"\ncreated: 2022-01-02T03:04:05+02:00\nupdated: 2024-05-06T07:08:09Z\n---\n\nGamma.\n");
        $this->loginAs($this->kb->a);
        $this->client->request('POST', '/api/upload', files: ['files' => [new UploadedFile($path, 'Gamma the third.md', 'text/markdown', test: true)]]);
        self::assertSame(201, $this->httpStatus());

        $this->in($this->kb->a);
        self::assertSame(
            ['created_at' => '2022-01-02 01:04:05', 'updated_at' => '2024-05-06 07:08:09'],
            $this->em->getConnection()->fetchAssociative('SELECT created_at, updated_at FROM notes WHERE title = ?', ['Gamma: the third']),
        );
    }

    /** @return array<string, string> path => content, as Obsidian keeps a vault */
    private function obsidianVault(): array
    {
        return [
            '.obsidian/app.json' => '{"alwaysUpdateLinks": true}',
            'attachments/diagram.png' => "\x89PNG\r\n\x1a\n",
            'Projects/Alpha.md' => "---\ntags: [project, alpha]\ncreated: 2023-04-01\naliases: [A]\n---\nAlpha links to [[Beta]] and to [[Projects/Gamma|the third one]].\n\n![[diagram.png]]\n",
            'Beta.md' => "Beta has no frontmatter, links back to [[Alpha]] and names [[Gamma: the third]].\n",
            'Projects/Gamma.md' => "---\ntitle: \"Gamma: the third\"\nsummary: \"What Gamma holds.\"\nsummary_by: Claude\ncreated: 2022-01-02T03:04:05+02:00\nupdated: 2024-05-06T07:08:09Z\ntags: \"project, gamma\"\n---\nGamma links to [[Delta: the fourth]].\n",
        ];
    }

    /** @param array<string, string> $files */
    private function zip(array $files): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'memex-vault-').'.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return new UploadedFile($path, 'vault.zip', 'application/zip', test: true);
    }

    private function import(Tenant $tenant, UploadedFile $zip): void
    {
        $this->loginAs($tenant);
        $this->client->request('POST', '/api/import', files: ['archive' => $zip]);
    }

    /** @return array<string, string> path => content, for this test's notes */
    private function export(Tenant $tenant): array
    {
        $this->loginAs($tenant);
        $this->client->request('GET', '/api/export/all');
        self::assertSame(200, $this->httpStatus());
        $path = tempnam(sys_get_temp_dir(), 'memex-export-');
        try {
            file_put_contents($path, $this->body());
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            $files = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $content = (string) $zip->getFromIndex($i);
                if (\in_array((new FrontmatterParser())->parse($content, '')['title'], self::TITLES, true)) {
                    $files[(string) $zip->getNameIndex($i)] = $content;
                }
            }
            $zip->close();
            ksort($files);

            return $files;
        } finally {
            unlink($path);
        }
    }

    /** @return array<string, array{body: string, summary: ?string, summary_by: ?string, created: string, updated: string, path: ?string, tags: list<string>}> */
    private function facts(Tenant $tenant): array
    {
        $this->in($tenant);
        $facts = [];
        foreach ($this->em->getConnection()->fetchAllAssociative(
            'SELECT n.id, n.title, n.body_md, n.summary, n.summary_by, n.created_at, n.updated_at, n.import_path FROM notes n WHERE n.title IN (:titles)',
            ['titles' => self::TITLES],
            ['titles' => \Doctrine\DBAL\ArrayParameterType::STRING],
        ) as $row) {
            $tags = $this->em->getConnection()->fetchFirstColumn(
                'SELECT t.name FROM note_tag nt JOIN tags t ON t.id = nt.tag_id WHERE nt.note_id = :id ORDER BY t.name',
                ['id' => $row['id']],
            );
            $facts[$row['title']] = [
                'body' => $row['body_md'],
                'summary' => $row['summary'],
                'summary_by' => $row['summary_by'],
                'created' => $row['created_at'],
                'updated' => $row['updated_at'],
                'path' => $row['import_path'],
                'tags' => $tags,
            ];
        }
        self::assertSame(self::TITLES, array_values(array_intersect(self::TITLES, array_keys($facts))));

        return array_merge(array_flip(self::TITLES), $facts);
    }

    /** @return list<array{string, string, ?string}> from title, target as written, to title */
    private function links(Tenant $tenant): array
    {
        $this->in($tenant);

        return array_map(
            static fn (array $row): array => [$row['from_title'], $row['raw_target'], $row['to_title']],
            $this->em->getConnection()->fetchAllAssociative(
                'SELECT f.title AS from_title, nl.raw_target, t.title AS to_title
                 FROM note_links nl JOIN notes f ON f.id = nl.from_note_id LEFT JOIN notes t ON t.id = nl.to_note_id
                 WHERE f.title IN (:titles) ORDER BY f.title, nl.raw_target',
                ['titles' => self::TITLES],
                ['titles' => \Doctrine\DBAL\ArrayParameterType::STRING],
            ),
        );
    }

    /**
     * Obsidian's rule for `[[target]]`: a target with a folder names the file
     * at that path; a bare one names a file of that name in any folder.
     *
     * @param list<string> $paths
     */
    private static function opensInObsidian(string $target, array $paths): bool
    {
        $wanted = mb_strtolower($target.'.md');
        foreach ($paths as $path) {
            $path = mb_strtolower($path);
            if (str_contains($target, '/') ? $path === $wanted : basename($path) === $wanted) {
                return true;
            }
        }

        return false;
    }
}
