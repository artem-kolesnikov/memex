<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Controller\ExportController;
use App\Entity\{Note, Tag};
use App\Service\{FrontmatterParser, HybridSearch, VaultExporter};
use Doctrine\DBAL\Connection;
use Doctrine\ORM\{EntityManagerInterface, EntityRepository};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ExportPortabilityTest extends TestCase
{
    public static function archiveShapes(): iterable
    {
        yield 'all notes' => [false];
        yield 'selected notes' => [true];
    }

    #[DataProvider('archiveShapes')]
    public function testEveryCollidingNoteSurvivesAnActualZip(bool $selection): void
    {
        [$em, $notes] = $this->fixture(['foo', 'foo-3', 'foo', str_repeat('x', 81).'a', str_repeat('x', 81).'b', '中文', '日本語']);
        $exporter = new VaultExporter($em);
        $controller = new ExportController($em, $this->createMock(HybridSearch::class), $exporter);
        $response = $selection
            ? $controller->exportSet(new Request(['ids' => implode(',', array_keys($notes))]))
            : $controller->exportAll(new Request());
        self::assertSame(200, $response->getStatusCode());
        $path = tempnam(sys_get_temp_dir(), 'export-readback-');
        try {
            file_put_contents($path, $response->getContent());
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            self::assertSame(count($notes), $zip->numFiles);
            $actual = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $parsed = (new FrontmatterParser())->parse($zip->getFromIndex($i), 'fallback');
                $actual[] = hash('sha256', $parsed['body']);
            }
            $zip->close();
            $expected = array_map(static fn (Note $note) => hash('sha256', $note->getBodyMd()), array_values($notes));
            sort($actual);
            sort($expected);
            self::assertSame($expected, $actual);
        } finally {
            unlink($path);
        }
    }

    public function testTagsRemainExactYamlStrings(): void
    {
        [$em, $notes] = $this->fixture(['tag test']);
        $note = $notes[1];
        $tags = ['true', 'false', 'null', '123', 'a,b', 'x: y', '[brackets]', 'quote"here', 'タグ', 'a\\b', '#comment'];
        foreach ($tags as $tag) {
            $note->addTag(new Tag($tag));
        }
        $parsed = $this->readback($em);
        sort($tags);
        sort($parsed['tags']);
        self::assertSame($tags, $parsed['tags']);
    }

    public static function bodies(): iterable
    {
        foreach (["    indented code\n", "\tcode\n", "\n\nleading blank lines", "```php\n echo 1;\n```", "no final newline", "trailing\n\n", ''] as $body) {
            yield [$body];
        }
    }

    #[DataProvider('bodies')]
    public function testBodyBytesRoundTrip(string $body): void
    {
        [$em] = $this->fixture(['body test'], $body);
        $parsed = $this->readback($em);
        self::assertSame($body, $parsed['body']);
    }

    public function testDatesRoundTrip(): void
    {
        [$em, $notes] = $this->fixture(['dated']);
        $notes[1]->carryDates(new \DateTimeImmutable('2022-01-02 03:04:05+02:00'), new \DateTimeImmutable('2024-05-06 07:08:09Z'));

        $parsed = $this->readback($em);

        self::assertEquals($notes[1]->getCreatedAt(), $parsed['created']);
        self::assertEquals($notes[1]->getUpdatedAt(), $parsed['updated']);
    }

    public static function fileNames(): iterable
    {
        yield 'a plain title' => ['The Family Packing List', 'The Family Packing List.md'];
        yield 'a colon' => ['memex — one database per account: design and work plan', 'memex — one database per account design and work plan.md'];
        yield 'path and wildcard characters' => ['a/b\\c: d?', 'a b c d.md'];
        yield 'link syntax' => ['C# notes [draft] ^1 | x', 'C notes draft 1 x.md'];
        yield 'a leading and a trailing dot' => ['.hidden note.', 'hidden note.md'];
        yield 'a Windows device name' => ['CON', 'CON-1.md'];
        yield 'nothing left' => ['???', 'note-1.md'];
        yield 'non-Latin' => ['日本語のノート', '日本語のノート.md'];
        yield 'a double space kept' => ['Keep  double  space', 'Keep  double  space.md'];
    }

    #[DataProvider('fileNames')]
    public function testAFileIsNamedByItsTitle(string $title, string $expected): void
    {
        [$em] = $this->fixture([$title]);

        self::assertSame([$expected], $this->entryNames($em));
    }

    public function testALongTitleFitsAFileName(): void
    {
        [$em] = $this->fixture([str_repeat('Ж', 400)]);

        [$name] = $this->entryNames($em);
        self::assertLessThanOrEqual(255, strlen($name));
        self::assertTrue(mb_check_encoding($name, 'UTF-8'));
    }

    public function testTitlesThatDifferOnlyInCaseGetTwoFiles(): void
    {
        [$em] = $this->fixture(['foo', 'Foo']);

        self::assertSame(['foo.md', 'Foo-2.md'], $this->entryNames($em));
    }

    public function testAnImportedNoteGoesBackToItsPath(): void
    {
        [$em] = $this->fixture(['Gamma: the third', 'Written here', 'Escapes'], null, [1 => 'Projects/Gamma', 3 => '../etc/./a:b']);

        self::assertSame(['Projects/Gamma.md', 'Written here.md', 'etc/a b.md'], $this->entryNames($em));
    }

    /** A file and a folder cannot share a name once the archive is unpacked. */
    public function testAFileNamedLikeAFolderTakesItsNumber(): void
    {
        [$first] = $this->fixture(['Projects', 'Child'], null, [2 => 'projects.md/Child']);
        [$second] = $this->fixture(['Child', 'Projects'], null, [1 => 'Projects.md/Child']);

        self::assertSame(['Projects-1.md', 'projects.md/Child.md'], $this->entryNames($first));
        self::assertSame(['Projects.md/Child.md', 'Projects-2.md'], $this->entryNames($second));
    }

    public function testFailedArchiveFinalizationIsReported(): void
    {
        [$em, $notes] = $this->fixture(['close failure']);
        $directory = sys_get_temp_dir().'/export-close-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $path = $directory.'/archive.zip';
        $input = (static function () use ($notes, $directory): \Generator {
            yield $notes[1];
            rmdir($directory);
        })();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not finish export archive');
        (new VaultExporter($em))->writeNotes($input, $path);
    }

    public function testInterruptedExportDoesNotLeaveAPartialArchive(): void
    {
        [$em, $notes] = $this->fixture(['interrupted export']);
        $path = tempnam(sys_get_temp_dir(), 'export-interrupted-');
        $input = (static function () use ($notes): \Generator {
            yield $notes[1];
            throw new \RuntimeException('Synthetic note read failure');
        })();
        try {
            (new VaultExporter($em))->writeNotes($input, $path);
            self::fail('Expected the note read failure');
        } catch (\RuntimeException $error) {
            self::assertSame('Synthetic note read failure', $error->getMessage());
            self::assertFileDoesNotExist($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testEmptyArchiveReportsZeroWithoutLeavingAFile(): void
    {
        [$em] = $this->fixture([]);
        $path = tempnam(sys_get_temp_dir(), 'export-empty-');
        self::assertSame(0, (new VaultExporter($em))->writeArchive($path));
        self::assertFileDoesNotExist($path);
    }

    public function testExternalFrontmatterWithoutBlankSeparatorPreservesIndentation(): void
    {
        $parsed = (new FrontmatterParser())->parse("---\ntitle: Example\n---\n    code", 'fallback');
        self::assertSame('    code', $parsed['body']);
    }

    public static function exportFailures(): iterable
    {
        yield 'write failed' => [false];
        yield 'archive missing' => [true];
    }

    #[DataProvider('exportFailures')]
    public function testControllerReportsFailureAndCleansTemporaryFile(bool $missingArchive): void
    {
        [$em] = $this->fixture([]);
        $path = null;
        $exporter = $this->createMock(VaultExporter::class);
        $exporter->method('writeArchive')->willReturnCallback(static function (string $output) use (&$path, $missingArchive): int {
            $path = $output;
            if ($missingArchive) {
                unlink($output);

                return 1;
            }
            throw new \RuntimeException('Synthetic archive write failure');
        });
        $controller = new ExportController($em, $this->createMock(HybridSearch::class), $exporter);
        try {
            $controller->exportAll(new Request());
            self::fail('Export failure must not produce an HTTP 200');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString($missingArchive ? 'Could not read export archive' : 'Synthetic archive write failure', $error->getMessage());
            self::assertNotNull($path);
            self::assertFileDoesNotExist($path);
        }
    }

    public function testArchiveOpenFailureIsExplicit(): void
    {
        [$em] = $this->fixture([]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not open');
        (new VaultExporter($em))->writeNotes([], sys_get_temp_dir());
    }

    public function testMalformedFrontmatterStillRemainsInBody(): void
    {
        $content = "---\ntags: [broken\n---\n    code";
        self::assertSame($content, (new FrontmatterParser())->parse($content, 'fallback')['body']);
    }

    /** @return list<string> */
    private function entryNames(EntityManagerInterface $em): array
    {
        $path = tempnam(sys_get_temp_dir(), 'export-names-');
        try {
            (new VaultExporter($em))->writeArchive($path);
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            $names = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $names[] = (string) $zip->getNameIndex($i);
            }
            $zip->close();

            return $names;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function readback(EntityManagerInterface $em): array
    {
        $path = tempnam(sys_get_temp_dir(), 'export-roundtrip-');
        try {
            self::assertSame(1, (new VaultExporter($em))->writeArchive($path));
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            self::assertSame(1, $zip->numFiles);
            $content = $zip->getFromIndex(0);
            $zip->close();

            return (new FrontmatterParser())->parse($content, 'fallback');
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /** @param array<int, string> $paths import paths by note id */
    private function fixture(array $titles, ?string $body = null, array $paths = []): array
    {
        $notes = [];
        foreach ($titles as $i => $title) {
            $note = new Note(null, $title, $body ?? 'body '.($i + 1), 'manual', null, Note::STATUS_VERIFIED);
            $note->setImportPath($paths[$i + 1] ?? null);
            (new \ReflectionProperty(Note::class, 'id'))->setValue($note, $i + 1);
            $notes[$i + 1] = $note;
        }
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn(array_keys($notes));
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturnCallback(static fn ($id) => $notes[$id] ?? null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getRepository')->willReturn($repo);

        return [$em, $notes];
    }
}
