<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\ShippedSkills;
use App\Service\SkillLibrary;
use App\Service\SkillServing;
use App\Service\SkillSlugTaken;

final class SkillServingTest extends ApiTestCase
{
    private function library(): SkillLibrary
    {
        return self::getContainer()->get(SkillLibrary::class);
    }

    private function serving(): SkillServing
    {
        return self::getContainer()->get(SkillServing::class);
    }

    private function ownSlugs($token = null, ?string $surface = null): array
    {
        $this->in($this->kb->a);
        $own = array_filter($this->library()->all($token, $surface), static fn ($s) => $s['id'] !== null);

        return array_values(array_column($own, 'slug'));
    }

    public function testAnExistingSkillNoteKeepsItsSlugWhenRenamed(): void
    {
        $note = $this->kb->a->note('House style', 'Cite the source.', ['skill']);
        self::assertSame(['house-style'], $this->ownSlugs());

        $this->em->getConnection()->executeStatement('UPDATE notes SET title = :t WHERE id = :id', ['t' => 'Our style guide', 'id' => $note->getId()]);
        self::assertSame(['house-style'], $this->ownSlugs(), 'a rename never moves a slug an assistant may have learned');
    }

    public function testTwoNotesThatSlugAlikeAreSuffixedOnceAndStayThatWay(): void
    {
        $first = $this->kb->a->note('House style', 'One.', ['skill']);
        $second = $this->kb->a->note('House style', 'Two.', ['skill']);
        $slugs = $this->ownSlugs();
        self::assertEqualsCanonicalizing(['house-style', 'house-style-2'], $slugs);
        $before = $this->serving()->settings();
        $this->em->getConnection()->executeStatement('UPDATE notes SET updated_at = :later WHERE id = :id', ['later' => gmdate('Y-m-d H:i:s', time() + 3600), 'id' => $first->getId()]);
        self::assertSame($before, $this->serving()->settings());
    }

    public function testAPausedSkillIsServedNowhereAndCannotBeFoundBySlug(): void
    {
        $note = $this->kb->a->note('House style', 'Cite the source.', ['skill']);
        $this->serving()->update($note->getId(), ['enabled' => false]);

        self::assertSame([], $this->ownSlugs());
        self::assertNull($this->library()->find('house-style'));
        $page = array_values(array_filter($this->library()->page(), static fn ($r) => $r['slug'] === 'house-style'));
        self::assertSame('paused', $page[0]['status']);
    }

    public function testSurfacesFollowTheTwoSwitches(): void
    {
        $note = $this->kb->a->note('House style', 'Cite the source.', ['skill']);
        $this->serving()->update($note->getId(), ['command' => false]);
        self::assertSame(['house-style'], $this->ownSlugs(null, 'auto'));
        self::assertSame([], $this->ownSlugs(null, 'command'));

        $this->serving()->update($note->getId(), ['command' => true, 'auto' => false]);
        self::assertSame([], $this->ownSlugs(null, 'auto'));
        self::assertSame(['house-style'], $this->ownSlugs(null, 'command'));
    }

    public function testAGrantNarrowsTheListToNamedConnections(): void
    {
        $note = $this->kb->a->note('Codex only', 'For Codex.', ['skill']);
        $this->serving()->update($note->getId(), ['grants' => [$this->kb->a->curatorTokenId]]);

        self::assertSame(['codex-only'], $this->ownSlugs($this->kb->a->curatorToken()));
        self::assertSame([], $this->ownSlugs($this->kb->a->agentToken()));
        self::assertSame(['codex-only'], $this->ownSlugs(null), 'the owner\'s own view is ungated');
    }

    public function testARevokedConnectionsGrantIsIgnoredAndEmptyGrantsMeanEveryone(): void
    {
        $note = $this->kb->a->note('Codex only', 'For Codex.', ['skill']);
        $this->serving()->update($note->getId(), ['grants' => [$this->kb->a->curatorTokenId]]);
        $this->em->getConnection()->executeStatement('UPDATE api_tokens SET revoked_at = :now WHERE id = :id', ['now' => gmdate('Y-m-d H:i:s'), 'id' => $this->kb->a->curatorTokenId]);
        $this->em->clear();

        self::assertSame(['codex-only'], $this->ownSlugs($this->kb->a->agentToken()), 'with no live grant left, the skill is everyone\'s again');
    }

    public function testASlugCannotTakeAShippedOrSiblingName(): void
    {
        $note = $this->kb->a->note('House style', 'Cite.', ['skill']);
        $other = $this->kb->a->note('Other', 'Other.', ['skill']);
        $this->ownSlugs();

        try {
            $this->serving()->update($note->getId(), ['slug' => ShippedSkills::RECALL]);
            self::fail('a shipped slug was taken');
        } catch (SkillSlugTaken) {
        }
        try {
            $this->serving()->update($note->getId(), ['slug' => 'other']);
            self::fail('a sibling slug was taken');
        } catch (SkillSlugTaken) {
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->serving()->update($note->getId(), ['slug' => 'House Style']);
    }

    public function testAnotherTenantCannotUpdateTheRecord(): void
    {
        $note = $this->kb->a->note('House style', 'Cite.', ['skill']);
        $this->in($this->kb->b);
        $this->expectException(\OutOfBoundsException::class);
        $this->serving()->update($note->getId(), ['enabled' => false]);
    }

    private function pendingSkillNote(string $title): \App\Entity\Note
    {
        $this->in($this->kb->a);
        $note = new \App\Entity\Note(null, $title, 'Steps.', 'web', null, 'pending');
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

    public function testAPendingSkillNoteIsOnThePageButNotServed(): void
    {
        $this->pendingSkillNote('Draft skill');
        self::assertSame([], $this->ownSlugs());
        $rows = array_filter($this->library()->page(), static fn ($r) => $r['title'] === 'Draft skill');
        self::assertSame('pending', array_values($rows)[0]['status']);
    }

    public function testAPendingNotesProvisionalSlugIsSuffixedByNoteNumberWhenAServedRowAlreadyHasIt(): void
    {
        $this->kb->a->note('House style', 'Cite the source.', ['skill']);
        $draft = $this->pendingSkillNote('House style');

        $rows = $this->library()->page();
        $served = array_values(array_filter($rows, static fn ($r) => $r['kind'] === 'note' && $r['status'] === 'served'))[0];
        $pending = array_values(array_filter($rows, static fn ($r) => $r['kind'] === 'note' && $r['status'] === 'pending'))[0];
        self::assertSame('house-style', $served['slug']);
        self::assertSame('house-style-'.$draft->getId(), $pending['slug']);
    }

    public function testTwoPendingNotesTitledAlikeGetDistinctSlugs(): void
    {
        $first = $this->pendingSkillNote('Draft skill');
        $second = $this->pendingSkillNote('Draft skill');
        $numbers = [$first->getId(), $second->getId()];

        $rows = array_values(array_filter($this->library()->page(), static fn ($r) => $r['kind'] === 'note'));
        $slugs = array_column($rows, 'slug');
        self::assertCount(2, $slugs);
        self::assertCount(2, array_unique($slugs), 'two pending notes titled alike must not share a slug');
        self::assertContains('draft-skill', $slugs, 'whichever note took the derived slug first keeps it unsuffixed');
        $suffixed = array_values(array_diff($slugs, ['draft-skill']));
        self::assertCount(1, $suffixed);
        self::assertContains($suffixed[0], ['draft-skill-'.$numbers[0], 'draft-skill-'.$numbers[1]]);
    }

    public function testAPendingNoteTitledMemexGuideDoesNotTakeTheShippedGuidesSlug(): void
    {
        $draft = $this->pendingSkillNote('memex guide');

        $rows = array_values(array_filter($this->library()->page(), static fn ($r) => $r['kind'] === 'note'));
        self::assertSame('memex-guide-'.$draft->getId(), $rows[0]['slug']);
    }

    public function testEnsureRecordsToleratesARowAlreadyInsertedByARace(): void
    {
        $note = $this->kb->a->note('House style', 'Cite the source.', ['skill']);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO skill_settings (note_id, slug) VALUES (:n, :s)',
            ['n' => $note->getId(), 's' => 'house-style'],
        );
        $before = $this->serving()->settings()[$note->getId()];
        $this->serving()->ensureRecords();
        self::assertSame($before, $this->serving()->settings()[$note->getId()]);
    }

    public function testAPatchThatFailsOnGrantsChangesNothing(): void
    {
        $foreign = $this->kb->b->connection('agent-b-3', 'mxt_agent_b_3');
        $note = $this->kb->a->note('House style', 'Cite.', ['skill']);
        $this->ownSlugs();
        try {
            $this->serving()->update($note->getId(), ['enabled' => false, 'grants' => [$foreign]]);
            self::fail('a foreign token id was accepted');
        } catch (\OutOfBoundsException) {
        }
        self::assertTrue($this->serving()->settings()[$note->getId()]['enabled'], 'nothing of a refused patch is applied');
    }
    public function testLongCollidingTitlesKeepValidSlugs(): void
    {
        $title = str_repeat('a', 61).' bc';
        $this->kb->a->note($title, 'One.', ['skill']);
        $this->kb->a->note($title, 'Two.', ['skill']);
        foreach ($this->ownSlugs() as $slug) {
            self::assertTrue(\App\Service\SkillSlug::isValid($slug), $slug);
        }
    }

    public function testPendingSuffixIsCheckedAgainstExistingNamesBeforeDownload(): void
    {
        $this->kb->a->note('House style', 'Original.', ['skill']);
        $draft = $this->pendingSkillNote('House style');
        $this->kb->a->note('House style '.$draft->getId(), 'Different skill.', ['skill']);
        $rows = $this->library()->page();
        self::assertCount(count($rows), array_unique(array_column($rows, 'slug')));
        $pending = array_values(array_filter($rows, static fn ($r) => $r['status'] === 'pending'))[0];
        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/skills/served/'.$pending['slug'].'/export');
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('Steps.', $this->client->getResponse()->getContent());
    }

    public function testPatchingOneSwitchDoesNotUndoAnInterleavedPause(): void
    {
        $note = $this->kb->a->note('House style', 'Steps.', ['skill']);
        $this->serving()->ensureRecords();
        $conn = $this->em->getConnection();
        $paused = false;
        \App\Tests\Support\SqlObservation::during($conn, function (string $sql) use ($conn, $note, &$paused): void {
            if (!$paused && str_starts_with($sql, 'UPDATE skill_settings')) {
                $paused = true;
                $conn->executeStatement('UPDATE skill_settings SET enabled = FALSE WHERE note_id = :n', ['n' => $note->getId()]);
            }
        }, fn () => $this->serving()->update($note->getId(), ['command' => false]));
        $row = $this->serving()->settings()[$note->getId()];
        self::assertTrue($paused);
        self::assertFalse($row['enabled'], 'the later partial patch must not restore a stale enabled value');
        self::assertFalse($row['command']);
    }

    public function testAllocationRetriesWhenAnotherSkillClaimsTheNameAfterTheRead(): void
    {
        $note = $this->kb->a->note('House style', 'Target.', ['skill']);
        $other = $this->kb->a->note('Other skill', 'Other.', ['skill']);
        $this->serving()->update($other->getId(), ['slug' => 'other-skill']);
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM skill_settings WHERE note_id = :n', ['n' => $note->getId()]);
        $claimed = false;
        \App\Tests\Support\SqlObservation::during($conn, function (string $sql) use ($conn, $other, &$claimed): void {
            if (!$claimed && str_starts_with($sql, 'INSERT INTO skill_settings')) {
                $claimed = true;
                $conn->executeStatement("UPDATE skill_settings SET slug = 'house-style' WHERE note_id = :n", ['n' => $other->getId()]);
            }
        }, fn () => $this->serving()->ensureRecords());
        $settings = $this->serving()->settings();
        self::assertArrayHasKey($note->getId(), $settings, 'a slug race must not leave the note serving with fallback settings');
        self::assertSame('house-style-2', $settings[$note->getId()]['slug']);
        self::assertSame('house-style', $settings[$other->getId()]['slug']);
    }

}
