<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;
use App\Service\ShippedSkills;
use App\Service\SkillLibrary;
use App\Tests\Support\Tenant;

/**
 * That memex serves its own instruction sets to a knowledge base that contains
 * none of them.
 *
 * This is the property the whole change exists for. The recall instructions
 * were a note in ONE knowledge base — so they never received a shipped
 * correction, and an invited user had no copy at all. A unit test on the file
 * cannot see either failure: the file can be perfect and reach nobody.
 */
final class ShippedSkillsServedTest extends ApiTestCase
{
    private function slugsFor(Tenant $tenant): array
    {
        $this->in($tenant);
        $library = self::getContainer()->get(SkillLibrary::class);

        return array_column($library->all(), 'slug');
    }

    /** A knowledge base with nothing in it is still told how to be used. */
    public function testAnEmptyKnowledgeBaseIsServedTheShippedSkills(): void
    {
        $slugs = $this->slugsFor($this->kb->a);

        self::assertContains(ShippedSkills::RECALL, $slugs,
            'a stranger opening a new account has no skill notes at all, and this is the one '
            .'thing their assistant needs before it can use memex correctly');
        self::assertContains(SkillLibrary::CURATION_CHARTER, $slugs);
        self::assertContains(ShippedSkills::SKILLS, $slugs, 'how to write a skill for memex ships with memex');
    }

    /** And so is the other tenant, from the same files, without sharing a note. */
    public function testBothTenantsAreServedItIndependently(): void
    {
        self::assertContains(ShippedSkills::RECALL, $this->slugsFor($this->kb->a));
        self::assertContains(ShippedSkills::RECALL, $this->slugsFor($this->kb->b));
    }

    /**
     * A note cannot take a shipped name.
     *
     * The failure this prevents is not hypothetical: `slugify` derives the slug
     * from the title, so any user titling a note "memex — recall" would
     * otherwise be handed their own text under the name memex vouches for.
     */
    public function testANoteCannotShadowAShippedSkill(): void
    {
        $this->in($this->kb->a);
        $shipped = self::getContainer()->get(SkillLibrary::class)
            ->find(ShippedSkills::RECALL);
        self::assertNotNull($shipped);

        $note = new Note(
            null,
            'memex — recall',
            'My own version.',
            'web',
            null,
            'verified',
        );
        $this->em->persist($note);
        $this->em->flush();
        $this->tagVerified($note, 'skill');

        $after = self::getContainer()->get(SkillLibrary::class)
            ->find(ShippedSkills::RECALL);

        self::assertNotNull($after);
        self::assertSame($shipped['body'], $after['body'],
            'the shipped body must survive a note titled into its slug');
        self::assertStringNotContainsString('My own version.', $after['body']);

        // Non-destructive: the note is still served, under a suffixed slug.
        $slugs = $this->slugsFor($this->kb->a);
        self::assertContains(ShippedSkills::RECALL.'-2', $slugs,
            'the user\'s note is not hidden, only moved off the reserved name');
    }

    /**
     * The shipped skill is loadable by the name it is listed under.
     *
     * `list_skills` naming a slug that `get_skill` cannot resolve is the shape
     * of failure that leaves an assistant improvising, which is exactly what
     * the charter's own history shows.
     */
    public function testTheServedSkillLoadsByItsSlugOverMcp(): void
    {
        $this->request('POST', '/mcp', $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'get_skill', 'arguments' => ['slug' => ShippedSkills::RECALL]],
        ]);

        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('Read before you answer', $this->body(),
            'the body an assistant receives must be the shipped instructions');
    }

    /**
     * The other door onto the same skill.
     *
     * Clients differ: some read `get_skill`, some list MCP resources. A shipped
     * skill reachable through one and not the other is invisible to whichever
     * client picked the other, and nothing would say so.
     */
    public function testTheServedSkillLoadsAsAnMcpResource(): void
    {
        $this->request('POST', '/mcp', $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read',
            'params' => ['uri' => 'memex://skill/'.ShippedSkills::RECALL],
        ]);

        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('Read before you answer', $this->body());
    }

    private function tagVerified(Note $note, string $tag): void
    {
        $conn = $this->em->getConnection();
        $tagId = $conn->fetchOne('SELECT id FROM tags WHERE name = :n', ['n' => $tag]);
        if ($tagId === false) {
            $conn->executeStatement('INSERT INTO tags (name) VALUES (:n)', ['n' => $tag]);
            $tagId = $conn->lastInsertId();
        }
        $conn->executeStatement('INSERT INTO note_tag (note_id, tag_id) VALUES (:n, :t)', [
            'n' => $note->getId(), 't' => (int) $tagId,
        ]);
    }
}
