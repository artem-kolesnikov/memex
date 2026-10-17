<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Tests\Support\Tenant;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * An Obsidian note's properties survive import, come out of an export where
 * Obsidian shows them, and go into another account as they were. An export
 * from before properties existed brings none of its memex lines in as one.
 */
final class PropertiesRoundTripTest extends ApiTestCase
{
    private const OBSIDIAN = <<<'MD'
        ---
        title: Kitchen remodel
        tags: [home]
        status: active
        due: 2026-11-01
        rating: 4.5
        published: true
        authors:
          - Ann
          - Bo
        Due date: 2026-12-01T09:30:00Z
        cssclasses: [wide]
        reviewed:
        source: https://example.com/article
        created: 2025-03-04
        ---

        Cabinets first.
        MD;

    private const OLD_EXPORT = <<<'MD'
        ---
        title: "Garden plan"
        tags: ["memex"]
        source: manual
        status: verified
        created: 2025-03-04T05:06:07+00:00
        updated: 2026-09-01T10:00:00+00:00
        ---

        Tomatoes.
        MD;

    private const EXPECTED = [
        'status' => 'active',
        'due' => '2026-11-01',
        'rating' => 4.5,
        'published' => true,
        'authors' => ['Ann', 'Bo'],
        'Due date' => '2026-12-01T09:30:00Z',
        'cssclasses' => ['wide'],
        'reviewed' => null,
        'source' => 'https://example.com/article',
    ];

    public function testPropertiesComeInGoOutAndComeInAgain(): void
    {
        $this->import($this->kb->a, ['Kitchen remodel.md' => self::OBSIDIAN, 'Garden plan.md' => self::OLD_EXPORT]);

        self::assertSame(self::EXPECTED, $this->properties($this->kb->a, 'Kitchen remodel'), 'every key but the note\'s own fields, dates as written');
        self::assertSame([], $this->properties($this->kb->a, 'Garden plan'), 'an old export\'s source and status lines are memex\'s, not properties');

        $exported = $this->exported($this->kb->a);
        $kitchen = $exported['Kitchen remodel.md'];
        self::assertStringContainsString("\nstatus: \"active\"\n", $kitchen);
        self::assertStringContainsString("\nDue date: \"2026-12-01T09:30:00Z\"\n", $kitchen);
        self::assertStringContainsString("\nsource: \"https://example.com/article\"\n", $kitchen);
        self::assertStringNotContainsString("\nstatus: verified\n", $exported['Garden plan.md'], 'memex writes no status line of its own');
        self::assertStringNotContainsString("\nsource: upload\n", $exported['Garden plan.md']);

        $this->import($this->kb->b, $exported);
        self::assertSame(self::EXPECTED, $this->properties($this->kb->b, 'Kitchen remodel'), 'and arrives as it left');
        self::assertSame([], $this->properties($this->kb->b, 'Garden plan'));
    }

    /** @param array<string, string> $files */
    private function import(Tenant $tenant, array $files): void
    {
        $path = tempnam(sys_get_temp_dir(), 'memex-vault-').'.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $this->loginAs($tenant);
        $this->client->request('POST', '/api/import', files: ['archive' => new UploadedFile($path, 'vault.zip', 'application/zip', test: true)]);
        self::assertSame(201, $this->httpStatus(), $this->body());
    }

    /** @return array<string, mixed> */
    private function properties(Tenant $tenant, string $title): array
    {
        $this->in($tenant);
        $stored = $this->em->getConnection()->fetchOne('SELECT properties FROM notes WHERE title = ?', [$title]);

        return \is_string($stored) ? json_decode($stored, true, 512, JSON_THROW_ON_ERROR) : [];
    }

    /** @return array<string, string> path => content, for the two notes */
    private function exported(Tenant $tenant): array
    {
        $this->loginAs($tenant);
        $this->client->request('GET', '/api/export/all');
        self::assertSame(200, $this->httpStatus());
        $path = tempnam(sys_get_temp_dir(), 'memex-export-');
        file_put_contents($path, $this->body());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $files = [];
        foreach (['Kitchen remodel.md', 'Garden plan.md'] as $name) {
            $content = $zip->getFromName($name);
            self::assertIsString($content, $name.' is in the export');
            $files[$name] = $content;
        }
        $zip->close();
        unlink($path);

        return $files;
    }
}
