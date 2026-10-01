<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;
use App\Service\NoteLimbo;
use App\Service\ShippedSkills;
use App\Service\SkillPackage;

/**
 * Downloading a served skill.
 *
 * The settings pane offers one button for two unlike things: a shipped skill,
 * which has no note behind it, and a note of the user's own. Both render
 * through {@see \App\Service\SkillPackage}, so a download that silently
 * answered for only one kind would look like a working button.
 */
final class SkillExportTest extends ApiTestCase
{
    public function testAShippedSkillDownloadsAsMarkdown(): void
    {
        $this->loginAs($this->kb->a);
        $this->client->request('GET', '/api/skills/served/'.ShippedSkills::RECALL.'/export');

        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString(
            'attachment',
            (string) $this->client->getResponse()->headers->get('Content-Disposition')
        );
        self::assertStringContainsString('Read before you answer', $this->body(),
            'a shipped skill has no note, so the note export cannot reach it');
        self::assertStringStartsWith("---\nname: ", $this->body());
        self::assertSame(ShippedSkills::RECALL, (new SkillPackage())->parse($this->body(), 'fallback')['name']);
    }

    public function testASkillNoteDownloadsThroughTheNoteExport(): void
    {
        $this->skillNote('Team A house style', 'Always cite the source note.');
        $this->loginAs($this->kb->a);
        $this->client->request('GET', '/api/skills/served/team-a-house-style/export');

        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('Always cite the source note.', $this->body());
        self::assertStringStartsWith("---\nname: ", $this->body());
        self::assertSame('team-a-house-style', (new SkillPackage())->parse($this->body(), 'fallback')['name']);
    }

    public function testAnotherTenantCannotDownloadIt(): void
    {
        $this->skillNote('Team A house style', 'Always cite the source note.');
        $this->loginAs($this->kb->b);
        $this->client->request('GET', '/api/skills/served/team-a-house-style/export');

        self::assertSame(404, $this->httpStatus());
        self::assertStringNotContainsString('Always cite the source note.', $this->body());
    }

    /**
     * Deleting a skill note takes its download with it.
     *
     * What this does NOT reach is the guard in the controller for a note
     * retired BETWEEN the served lookup and the load: `retire()` removes the
     * row, so the lookup already answers null here and the earlier guard fires.
     * That window is inside one request and has no seam a test can drive; the
     * guard is there because the served list carries the BODY, so falling
     * through on a missing note would serve deleted text with a 200.
     */
    public function testARetiredSkillIsNoLongerDownloadable(): void
    {
        $note = $this->skillNote('Team A house style', 'Always cite the source note.');
        self::getContainer()->get(NoteLimbo::class)->retire($note, 'owner');

        $this->loginAs($this->kb->a);
        $this->client->request('GET', '/api/skills/served/team-a-house-style/export');

        self::assertSame(404, $this->httpStatus());
        self::assertStringNotContainsString('Always cite the source note.', $this->body());
    }

    /** The other vault's served list does not hold the slug at all. */
    public function testTheOtherTenantIsNotEvenServedTheSlug(): void
    {
        $this->skillNote('Team A house style', 'Always cite the source note.');

        $this->in($this->kb->b);
        $slugs = array_column(
            self::getContainer()->get(\App\Service\SkillLibrary::class)->all(),
            'slug'
        );

        self::assertNotContains('team-a-house-style', $slugs);
    }

    private function skillNote(string $title, string $body): Note
    {
        $this->in($this->kb->a);
        $note = new Note(
            null,
            $title,
            $body,
            'web',
            null,
            'verified',
        );
        $this->em->persist($note);
        $this->em->flush();

        $conn = $this->em->getConnection();
        $tagId = $conn->fetchOne('SELECT id FROM tags WHERE name = :n', ['n' => 'skill']);
        if ($tagId === false) {
            $conn->executeStatement('INSERT INTO tags (name) VALUES (:n)', ['n' => 'skill']);
            $tagId = $conn->lastInsertId();
        }
        $conn->executeStatement('INSERT INTO note_tag (note_id, tag_id) VALUES (:n, :t)', [
            'n' => $note->getId(), 't' => (int) $tagId,
        ]);

        return $note;
    }
}
