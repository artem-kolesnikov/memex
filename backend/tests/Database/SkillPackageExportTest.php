<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\ShippedSkills;
use App\Service\SkillPackage;
use App\Service\SkillServing;

final class SkillPackageExportTest extends ApiTestCase
{
    private function pendingSkillNote(string $title): \App\Entity\Note
    {
        $this->in($this->kb->a);
        $note = new \App\Entity\Note(null, $title, 'Draft body.', 'web', null, 'pending');
        $this->em->persist($note);
        $this->em->flush();
        $conn = $this->em->getConnection();
        $tagId = $conn->fetchOne('SELECT id FROM tags WHERE name = :n', ['n' => 'skill']);
        if ($tagId === false) {
            $conn->executeStatement('INSERT INTO tags (name) VALUES (:n)', ['n' => 'skill']);
            $tagId = $conn->lastInsertId();
        }
        $conn->executeStatement('INSERT INTO note_tag (note_id, tag_id) VALUES (:n, :t)', ['n' => $note->getId(), 't' => (int) $tagId]);

        return $note;
    }

    public function testAPendingNoteTitledLikeTheShippedGuideDownloadsUnderItsOwnSlugAsItself(): void
    {
        $draft = $this->pendingSkillNote('memex guide');
        $expectedSlug = ShippedSkills::GUIDE.'-'.$draft->getId();
        $this->loginAs($this->kb->a);
        $this->client->request('GET', '/api/skills/served/'.$expectedSlug.'/export');
        self::assertSame(200, $this->httpStatus());
        $parsed = (new SkillPackage())->parse($this->body(), 'x');
        self::assertSame($expectedSlug, $parsed['name']);
        self::assertStringContainsString('Draft body.', $parsed['body']);
    }

    public function testTheZipCarriesOneFolderPerServedSkill(): void
    {
        $note = $this->kb->a->note('House style', 'Cite the source.', ['skill']);
        $paused = $this->kb->a->note('Paused one', 'Nope.', ['skill']);
        self::getContainer()->get(SkillServing::class)->update($paused->getId(), ['enabled' => false]);
        $this->loginAs($this->kb->a);
        $this->client->request('GET', '/api/skills/export');
        self::assertSame(200, $this->httpStatus());

        $path = tempnam(sys_get_temp_dir(), 'skills');
        file_put_contents($path, $this->client->getResponse()->getContent());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = $zip->getNameIndex($i);
        }
        self::assertContains('house-style/SKILL.md', $names);
        self::assertContains(ShippedSkills::RECALL.'/SKILL.md', $names);
        self::assertNotContains('paused-one/SKILL.md', $names);
        $parsed = (new SkillPackage())->parse((string) $zip->getFromName('house-style/SKILL.md'), 'x');
        self::assertSame('house-style', $parsed['name']);
        self::assertStringContainsString('Cite the source.', $parsed['body']);
        $zip->close();
        unlink($path);
    }

    public function testThePerSkillDownloadIsASkillMd(): void
    {
        $this->kb->a->note('House style', 'Cite the source.', ['skill']);
        $this->loginAs($this->kb->a);
        $this->client->request('GET', '/api/skills/served/house-style/export');
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('filename="house-style.SKILL.md"', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith("---\nname: \"house-style\"\n", $this->body());
    }

    public function testAPausedSkillStillDownloadsForItsOwner(): void
    {
        $note = $this->kb->a->note('House style', 'Cite the source.', ['skill']);
        self::getContainer()->get(SkillServing::class)->update($note->getId(), ['enabled' => false]);
        $this->loginAs($this->kb->a);
        $this->client->request('GET', '/api/skills/served/house-style/export');
        self::assertSame(200, $this->httpStatus());
        self::assertStringStartsWith("---\nname: \"house-style\"\n", $this->body());
    }

    public function testAPausedSkillStaysAbsentOverABearerToken(): void
    {
        $note = $this->kb->a->note('House style', 'Cite the source.', ['skill']);
        self::getContainer()->get(SkillServing::class)->update($note->getId(), ['enabled' => false]);
        $this->request('GET', '/api/skills/served/house-style/export', $this->kb->a->agentBearer);
        self::assertSame(404, $this->httpStatus());
    }
}
