<?php

declare(strict_types=1);

namespace App\Tests\Database;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ImportBoundsTest extends ApiTestCase
{
    /** @var string[] */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            unlink($path);
        }
        parent::tearDown();
    }

    /** @param array<string, string> $files */
    private function archive(array $files, bool $oversized = false): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import-bound-');
        $this->temporary[] = $path;
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
        foreach ($files as $name => $body) {
            $zip->addFromString($name, $body);
        }
        if ($oversized) {
            $source = tempnam(sys_get_temp_dir(), 'import-expanded-');
            $this->temporary[] = $source;
            file_put_contents($source, str_repeat('x', 1048576));
            for ($i = 0; $i < 101; ++$i) {
                $zip->addFile($source, ($i % 2 ? '.hidden/' : 'unsupported/').$i.'.bin');
            }
        }
        self::assertTrue($zip->close());

        return new UploadedFile($path, 'vault.zip', 'application/zip', test: true);
    }

    /** @param array<string, string> $options */
    private function upload(UploadedFile $archive, array $options = []): void
    {
        $this->loginAs($this->kb->a);
        $this->client->request('POST', '/api/import', parameters: $options, files: ['archive' => $archive]);
    }

    public function testEveryExpandedEntryCountsBeforeAnyBackfillOrCreate(): void
    {
        $existing = $this->kb->a->note('Existing', 'Retained body');
        $this->upload($this->archive(['Existing.md' => 'duplicate', 'New.md' => 'new'], true));
        self::assertSame(400, $this->httpStatus(), $this->body());
        $this->in($this->kb->a);
        self::assertNull($this->em->getConnection()->fetchOne('SELECT import_path FROM notes WHERE id = ?', [$existing->getId()]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM notes'));
    }

    public function testUtf8ExactByteBoundaryAndAnOversizedEntry(): void
    {
        $body = str_repeat('é', 1048576);
        $this->upload($this->archive(['Exact.md' => $body, 'Too big.md' => $body.'a']));
        self::assertSame(201, $this->httpStatus());
        self::assertSame(1, $this->jsonResponse()['created']);
        self::assertSame('Too big.md', $this->jsonResponse()['skipped'][0]['file']);
        $this->in($this->kb->a);
        self::assertSame(2097152, (int) $this->em->getConnection()->fetchOne('SELECT octet_length(body_md) FROM notes WHERE title = ?', ['Exact']));
    }

    public function testInvalidUtf8FailsBeforeTheDuplicateBackfill(): void
    {
        $existing = $this->kb->a->note('Existing', 'Retained body');
        $this->upload($this->archive(['Existing.md' => 'duplicate', 'Invalid.md' => "bad\xff"]));
        self::assertSame(400, $this->httpStatus());
        $this->in($this->kb->a);
        self::assertNull($this->em->getConnection()->fetchOne('SELECT import_path FROM notes WHERE id = ?', [$existing->getId()]));
    }

    public function testACorruptEntryFailsBeforeTheDuplicateBackfill(): void
    {
        $existing = $this->kb->a->note('Existing', 'Retained body');
        $archive = $this->archive(['Existing.md' => 'duplicate', 'Corrupt.md' => 'unique payload marker']);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive->getPathname()));
        self::assertTrue($zip->setCompressionName('Corrupt.md', \ZipArchive::CM_STORE));
        self::assertTrue($zip->close());
        $bytes = file_get_contents($archive->getPathname());
        self::assertSame(1, substr_count($bytes, 'unique payload marker'));
        file_put_contents($archive->getPathname(), str_replace('unique payload marker', 'broken payload marker', $bytes));
        $this->upload($archive);
        self::assertSame(400, $this->httpStatus());
        $this->in($this->kb->a);
        self::assertNull($this->em->getConnection()->fetchOne('SELECT import_path FROM notes WHERE id = ?', [$existing->getId()]));
    }

    public function testTheConfiguredLimitsCountFrontmatterAndTheWholeArchive(): void
    {
        self::getContainer()->get('doctrine.dbal.directory_connection')->executeStatement('UPDATE storage_policy SET max_note_bytes = 20, max_import_bytes = 100 WHERE id = 1');
        $this->upload($this->archive(['One.md' => 'small', 'Two.md' => "---\ntitle: Two\n---\nbody"]));
        self::assertSame(201, $this->httpStatus());
        self::assertSame(1, $this->jsonResponse()['created']);
        self::assertStringContainsString('including frontmatter', $this->jsonResponse()['skipped'][0]['reason']);
        $this->upload($this->archive(['.hidden/a.bin' => str_repeat('x', 101)]));
        self::assertSame(400, $this->httpStatus());
    }

    public function testStreamingKeepsSharedTagsAndLaterWikiLinks(): void
    {
        $this->upload($this->archive([
            'First.md' => "---\ntags: [shared, first]\n---\n[[Second]]",
            'Second.md' => "---\ntags: [shared, second]\n---\nSecond body",
        ]));
        self::assertSame(201, $this->httpStatus());
        self::assertSame(2, $this->jsonResponse()['created']);
        $this->in($this->kb->a);
        self::assertSame(3, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM tags'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM note_links WHERE to_note_id IS NOT NULL'));
    }

    public function testAConfirmedImportDoesNotRetainEveryBody(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'import-memory-');
        $source = tempnam(sys_get_temp_dir(), 'import-source-');
        $this->temporary[] = $path;
        $this->temporary[] = $source;
        file_put_contents($source, str_repeat('x', 2097152));
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
        for ($i = 0; $i < 24; ++$i) {
            $zip->addFile($source, 'Note '.$i.'.md');
        }
        self::assertTrue($zip->close());
        unset($zip);
        $before = memory_get_usage(true);
        memory_reset_peak_usage();
        $this->upload(new UploadedFile($path, 'memory.zip', 'application/zip', test: true));
        $growth = memory_get_peak_usage(true) - $before;
        self::assertSame(201, $this->httpStatus());
        self::assertSame(24, $this->jsonResponse()['created']);
        self::assertLessThan(24 * 1048576, $growth, '48 MiB of expanded bodies must not accumulate in the preview or in Doctrine');
    }
}
