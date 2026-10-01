<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\ShippedSkills;
use App\Service\SkillLibrary;

final class SkillImportTest extends ApiTestCase
{
    private function upload(array $files): void
    {
        $uploaded = [];
        foreach ($files as $name => $content) {
            $path = tempnam(sys_get_temp_dir(), 'imp');
            file_put_contents($path, $content);
            $uploaded[] = new \Symfony\Component\HttpFoundation\File\UploadedFile($path, $name, null, null, true);
        }
        $this->client->request('POST', '/api/skills/import', files: ['files' => $uploaded]);
    }

    private function zipOf(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return (string) file_get_contents($path);
    }

    private function ownSlugs(): array
    {
        $this->in($this->kb->a);
        $all = self::getContainer()->get(SkillLibrary::class)->all();

        return array_values(array_column(array_filter($all, static fn ($s) => $s['id'] !== null), 'slug'));
    }

    public function testASkillMdLandsVerifiedUnderItsName(): void
    {
        $this->loginAs($this->kb->a);
        $this->upload(['SKILL.md' => "---\nname: weekly-review\ndescription: Run the weekly review of open notes and file what changed.\n---\n# Weekly review\n\nSteps.\n"]);
        self::assertSame(201, $this->httpStatus());
        $body = $this->jsonResponse();
        self::assertSame('weekly-review', $body['created'][0]['slug']);
        self::assertSame('served', $body['created'][0]['status']);
        self::assertSame(['weekly-review'], $this->ownSlugs());
        $row = $this->em->getConnection()->fetchAssociative('SELECT status, summary, source FROM notes WHERE title = :t', ['t' => 'Weekly review']);
        self::assertSame('verified', $row['status']);
        self::assertSame('upload', $row['source']);
        self::assertStringStartsWith('Run the weekly review', $row['summary']);
    }

    public function testAZipOfFoldersLandsEachAndReportsWhatItIgnored(): void
    {
        $this->loginAs($this->kb->a);
        $this->upload(['skills.zip' => $this->zipOf([
            'one/SKILL.md' => "---\nname: one\ndescription: First skill for testing the zip import path here.\n---\nOne.\n",
            'one/scripts/run.sh' => "echo hi\n",
            'two/SKILL.md' => "---\nname: two\ndescription: Second skill for testing the zip import path here.\n---\nTwo.\n",
            'two/references/REF.md' => "ref\n",
        ])]);
        self::assertSame(201, $this->httpStatus());
        $body = $this->jsonResponse();
        self::assertEqualsCanonicalizing(['one', 'two'], array_column($body['created'], 'slug'));
        self::assertEqualsCanonicalizing(['one/scripts/run.sh', 'two/references/REF.md'], $body['ignored']);
    }

    public function testAZipWithSkillMdAtItsRootLandsUnderItsFrontmatterName(): void
    {
        $this->loginAs($this->kb->a);
        $this->upload(['skills.zip' => $this->zipOf([
            'SKILL.md' => "---\nname: root-skill\ndescription: A skill whose SKILL.md sits at the root of the zip.\n---\nRoot.\n",
        ])]);
        self::assertSame(201, $this->httpStatus());
        $body = $this->jsonResponse();
        self::assertSame([], $body['errors']);
        self::assertSame('root-skill', $body['created'][0]['slug']);
    }

    public function testAZipWithSkillMdAtItsRootAndNoFrontmatterTakesTheZipFilename(): void
    {
        $this->loginAs($this->kb->a);
        $this->upload(['weekly.zip' => $this->zipOf([
            'SKILL.md' => "Check the tag.\n",
        ])]);
        self::assertSame(201, $this->httpStatus());
        $body = $this->jsonResponse();
        self::assertSame([], $body['errors']);
        self::assertSame('weekly', $body['created'][0]['slug']);
    }

    public function testACollisionLandsNothingForThatFile(): void
    {
        $this->kb->a->note('House style', 'Existing.', ['skill']);
        $this->loginAs($this->kb->a);
        $this->upload([
            'a.md' => "---\nname: house-style\ndescription: Collides with the note that is already there.\n---\nNew.\n",
            'b.md' => "---\nname: ".ShippedSkills::RECALL."\ndescription: Collides with a shipped skill by its slug.\n---\nNew.\n",
            'c.md' => "---\nname: fresh\ndescription: This one is fine and should land beside the errors.\n---\nOk.\n",
        ]);
        self::assertSame(201, $this->httpStatus());
        $body = $this->jsonResponse();
        self::assertSame(['fresh'], array_column($body['created'], 'slug'));
        self::assertCount(2, $body['errors']);
        $this->in($this->kb->a);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM notes WHERE title = 'House style'"));
    }

    public function testAFileWithoutFrontmatterTakesItsFilename(): void
    {
        $this->loginAs($this->kb->a);
        $this->upload(['Release checklist.md' => "Check the tag.\n"]);
        self::assertSame(201, $this->httpStatus());
        self::assertSame('release-checklist', $this->jsonResponse()['created'][0]['slug']);
    }

    public function testAnOversizedZipEntryIsRefusedWithoutBeingMaterialized(): void
    {
        $this->loginAs($this->kb->a);
        self::getContainer()->get('doctrine.dbal.directory_connection')->executeStatement('UPDATE storage_policy SET max_import_bytes = 100 WHERE id = 1');
        $this->upload(['skills.zip' => $this->zipOf([
            'one/SKILL.md' => "---\nname: one\ndescription: Oversized entry for the import ceiling test here.\n---\n".str_repeat('x', 200),
        ])]);
        self::assertSame(400, $this->httpStatus());
        $body = $this->jsonResponse();
        self::assertSame([], $body['created']);
        self::assertSame('skills.zip', $body['errors'][0]['file']);
        self::assertStringContainsString('exceeds', $body['errors'][0]['error']);
        $this->in($this->kb->a);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM notes'));
    }

    public function testABearerTokenIsRefused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, "Body.\n");
        $this->client->request('POST', '/api/skills/import',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->kb->a->agentBearer],
            files: ['files' => [new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'x.md', null, null, true)]]);
        self::assertSame(403, $this->httpStatus());
    }
    public function testInvalidNamesAndMalformedYamlAreReportedWithoutCreatingNotes(): void
    {
        $this->loginAs($this->kb->a);
        $this->upload([
            'bad-name.md' => "---\nname: Bad Name\n---\nSteps.",
            'bad-yaml.md' => "---\nname: [broken\n---\nSteps.",
            'valid.md' => "---\nname: valid\n---\nSteps.",
        ]);
        self::assertSame(201, $this->httpStatus());
        $body = $this->jsonResponse();
        self::assertSame(['valid'], array_column($body['created'], 'slug'));
        self::assertSame(['bad-name.md', 'bad-yaml.md'], array_column($body['errors'], 'file'));
        self::assertSame(['valid'], $this->ownSlugs());
    }

    public function testPlainFilesCannotBypassTheImportCeilingUsingFrontmatter(): void
    {
        $this->loginAs($this->kb->a);
        self::getContainer()->get('doctrine.dbal.directory_connection')->executeStatement('UPDATE storage_policy SET max_import_bytes = 100 WHERE id = 1');
        $this->upload(['large.md' => "---\nname: too-large\ndescription: ".str_repeat('x', 150)."\n---\nSmall body."]);
        self::assertSame(400, $this->httpStatus());
        self::assertSame([], $this->jsonResponse()['created']);
        self::assertSame('large.md', $this->jsonResponse()['errors'][0]['file']);
        self::assertSame([], $this->ownSlugs());
    }

}
