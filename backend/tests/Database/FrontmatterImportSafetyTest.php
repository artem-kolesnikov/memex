<?php

declare(strict_types=1);

namespace App\Tests\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FrontmatterImportSafetyTest extends ApiTestCase
{
    /** @var string[] */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            unlink($path);
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusedMetadata(): iterable
    {
        yield 'aliases' => ["base: &base {x: y}\ncopy: {<<: *base}", 'aliases'];
        yield 'envelope' => ['title: '.str_repeat('x', 65537), '65536'];
        yield 'depth' => ['x: '.str_repeat('[', 200).'0'.str_repeat(']', 200), '128'];
    }

    private function uploadFile(string $body): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'metadata-upload-');
        $this->paths[] = $path;
        file_put_contents($path, "---\n".$body."\n---\ntext");

        return new UploadedFile($path, 'Unsafe.md', 'text/markdown', test: true);
    }

    #[DataProvider('refusedMetadata')]
    public function testAZipFailsBeforeTheDuplicateBackfillOrAnyCreate(string $metadata, string $reason): void
    {
        $note = $this->kb->a->note('Existing', 'Keep this body');
        $path = tempnam(sys_get_temp_dir(), 'metadata-zip-');
        $this->paths[] = $path;
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
        $zip->addFromString('Existing.md', 'duplicate');
        $zip->addFromString('Unsafe.md', "---\n".$metadata."\n---\ntext");
        self::assertTrue($zip->close());
        $this->loginAs($this->kb->a);
        $this->client->request('POST', '/api/import', files: ['archive' => new UploadedFile($path, 'vault.zip', 'application/zip', test: true)]);
        self::assertSame(400, $this->httpStatus());
        self::assertStringContainsString('Unsafe.md', $this->jsonResponse()['error']);
        self::assertStringContainsString($reason, $this->jsonResponse()['error']);
        $this->in($this->kb->a);
        self::assertNull($this->em->getConnection()->fetchOne('SELECT import_path FROM notes WHERE id = ?', [$note->getId()]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM notes'));
    }

    #[DataProvider('refusedMetadata')]
    public function testAnUploadNamesTheRejectedFileAndCreatesNothing(string $metadata, string $reason): void
    {
        $this->loginAs($this->kb->a);
        $this->client->request('POST', '/api/upload', files: ['files' => [$this->uploadFile($metadata)]]);
        self::assertSame(400, $this->httpStatus());
        self::assertSame([], $this->jsonResponse()['created']);
        self::assertSame('Unsafe.md', $this->jsonResponse()['errors'][0]['file']);
        self::assertStringContainsString($reason, $this->jsonResponse()['errors'][0]['error']);
        $this->in($this->kb->a);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM notes'));
    }
}
