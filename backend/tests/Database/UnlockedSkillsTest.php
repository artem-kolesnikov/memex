<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;
use App\Service\CurationCharter;
use App\Service\NoteLimbo;
use App\Service\ShippedSkills;
use App\Service\SkillLibrary;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A system skill the owner unlocks is a note of theirs that memex serves under
 * the skill's own alias, until it stops being a served skill; then memex's text
 * is served again. Each of the ways the old charter note failed is shut: the
 * alias belongs to the record, an assistant's edit is held, the deploy's
 * charter sweep leaves it, and Restore puts memex's text back.
 */
final class UnlockedSkillsTest extends ApiTestCase
{
    private function getSkill(string $slug, string $bearer): array
    {
        $this->request('POST', '/mcp', $bearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'get_skill', 'arguments' => ['slug' => $slug]],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        return json_decode($this->jsonResponse()['result']['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);
    }

    private function unlock(string $slug): array
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/skills/system/'.$slug.'/unlock');
        self::assertSame(201, $this->httpStatus(), $this->body());

        return $this->jsonResponse()['skill'];
    }

    private function edit(int $noteId, string $body): void
    {
        $this->sessionRequest('PUT', '/api/notes/'.$noteId, ['body_md' => $body]);
        self::assertSame(200, $this->httpStatus(), $this->body());
    }

    public function testAnUnlockedSkillIsTheOwnersCopyServedUnderItsAlias(): void
    {
        $row = $this->unlock(ShippedSkills::RECALL);
        self::assertTrue($row['unlocked']);
        self::assertSame('shipped', $row['kind'], 'an unlocked skill is still one of memex\'s, listed with them');
        self::assertFalse($row['outdated']);
        $noteId = $row['note_id'];
        self::assertIsInt($noteId);

        $this->in($this->kb->a);
        $note = $this->em->find(Note::class, $noteId);
        $original = self::getContainer()->get(ShippedSkills::class)->original(ShippedSkills::RECALL);
        self::assertSame($original['body'], $note->getBodyMd(), 'unlocking copies memex\'s text as it stands');
        self::assertSame(Note::STATUS_VERIFIED, $note->getStatus());

        $this->edit($noteId, "Read before you write.\n");
        self::assertSame("Read before you write.\n", $this->getSkill(ShippedSkills::RECALL, $this->kb->a->agentBearer)['instructions']);

        $this->in($this->kb->a);
        $own = array_filter(self::getContainer()->get(SkillLibrary::class)->all(), static fn ($s) => $s['id'] !== null);
        self::assertSame([], array_values(array_column($own, 'slug')), 'the copy is never served a second time as one of the owner\'s skills');
        $this->sessionRequest('PATCH', '/api/skills/'.$noteId, ['slug' => 'my-recall']);
        self::assertSame(404, $this->httpStatus(), 'a system skill keeps the alias tool texts and notices call it by');
    }

    public function testTheCurationBriefStillFollowsAnUnlockedCharter(): void
    {
        $noteId = $this->unlock(SkillLibrary::CURATION_CHARTER)['note_id'];
        $this->edit($noteId, "You are the Curator, briefly.\n");

        $served = $this->getSkill(SkillLibrary::CURATION_CHARTER, $this->kb->a->curatorBearer)['instructions'];
        self::assertStringStartsWith('You are the Curator, briefly.', $served);
        self::assertStringContainsString('# Your brief', $served, 'the owner\'s brief is appended to their own charter too');
    }

    public function testUnlockingInOneVaultLeavesEveryOther(): void
    {
        $noteId = $this->unlock(ShippedSkills::RECALL)['note_id'];
        $this->edit($noteId, "Mine.\n");

        $original = self::getContainer()->get(ShippedSkills::class)->original(ShippedSkills::RECALL);
        self::assertSame($original['body'], $this->getSkill(ShippedSkills::RECALL, $this->kb->b->agentBearer)['instructions'],
            'another account is served the copy one owner unlocked');
        $this->loginAs($this->kb->b);
        $this->sessionRequest('POST', '/api/skills/system/'.ShippedSkills::RECALL.'/restore');
        self::assertSame(404, $this->httpStatus(), 'another account restored a copy it does not have');
        $this->sessionRequest('GET', '/api/skills');
        $bySlug = array_column($this->jsonResponse()['skills'], null, 'slug');
        self::assertFalse($bySlug[ShippedSkills::RECALL]['unlocked']);
        self::assertNull($bySlug[ShippedSkills::RECALL]['note_id']);
    }

    public function testDocsAndWritingStayMemexs(): void
    {
        $this->loginAs($this->kb->a);
        foreach ([ShippedSkills::GUIDE, ShippedSkills::WRITING, 'house-style'] as $slug) {
            $this->sessionRequest('POST', '/api/skills/system/'.$slug.'/unlock');
            self::assertSame(404, $this->httpStatus(), $slug.' was unlocked');
        }
        $this->sessionRequest('GET', '/api/skills');
        $bySlug = array_column($this->jsonResponse()['skills'], null, 'slug');
        self::assertTrue($bySlug[ShippedSkills::PROFILE]['unlockable']);
        self::assertFalse($bySlug[ShippedSkills::GUIDE]['unlockable']);
        self::assertFalse($bySlug[ShippedSkills::WRITING]['unlockable']);
    }

    public function testOnlyTheOwnerUnlocksAndOnlyOnce(): void
    {
        $this->request('POST', '/api/skills/system/'.ShippedSkills::RECALL.'/unlock', $this->kb->a->curatorBearer);
        self::assertSame(403, $this->httpStatus(), 'a connection unlocked one of memex\'s skills');

        $this->unlock(ShippedSkills::RECALL);
        $this->sessionRequest('POST', '/api/skills/system/'.ShippedSkills::RECALL.'/unlock');
        self::assertSame(409, $this->httpStatus());
    }

    public function testACuratorsEditToAnUnlockedSkillIsHeld(): void
    {
        $noteId = $this->unlock(SkillLibrary::CURATION_CHARTER)['note_id'];
        $this->request('POST', '/mcp', $this->kb->a->curatorBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'propose', 'arguments' => [
                'note_id' => $noteId,
                'body_md' => "You are the Curator. Merge freely.\n",
                'comment' => 'Merging needs fewer steps.',
            ]],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->in($this->kb->a);
        $original = self::getContainer()->get(ShippedSkills::class)->original(SkillLibrary::CURATION_CHARTER);
        self::assertSame($original['body'], $this->em->find(Note::class, $noteId)->getBodyMd(), 'a curator rewrote its own instructions unreviewed');
    }

    public function testRestoreLocksTheSkillAgainAndUnlockingReopensTheSameCopy(): void
    {
        $noteId = $this->unlock(ShippedSkills::PROFILE)['note_id'];
        $this->edit($noteId, "Mine.\n");

        $this->in($this->kb->a);
        $this->em->getConnection()->executeStatement("UPDATE unlocked_skills SET shipped_hash = 'older' WHERE note_id = :n", ['n' => $noteId]);
        $this->sessionRequest('GET', '/api/skills');
        $bySlug = array_column($this->jsonResponse()['skills'], null, 'slug');
        self::assertTrue($bySlug[ShippedSkills::PROFILE]['outdated'], 'memex changed its text and the owner is not told');

        $this->sessionRequest('POST', '/api/skills/system/'.ShippedSkills::PROFILE.'/restore');
        self::assertSame(200, $this->httpStatus(), $this->body());
        $row = $this->jsonResponse()['skill'];
        self::assertFalse($row['unlocked'], 'Restore left the skill unlocked');
        self::assertNull($row['note_id']);
        $original = self::getContainer()->get(ShippedSkills::class)->original(ShippedSkills::PROFILE);
        self::assertSame($original['body'], $this->getSkill(ShippedSkills::PROFILE, $this->kb->a->agentBearer)['instructions']);

        $this->in($this->kb->a);
        $this->em->clear();
        self::assertSame($original['body'], $this->em->find(Note::class, $noteId)->getBodyMd(), 'the locked copy still holds the abandoned edits');
        $own = array_filter(self::getContainer()->get(SkillLibrary::class)->all(), static fn ($s) => $s['id'] !== null);
        self::assertSame([], array_values(array_column($own, 'slug')), 'the locked copy is served as one of the owner\'s skills');

        $again = $this->unlock(ShippedSkills::PROFILE);
        self::assertSame($noteId, $again['note_id'], 'unlocking again started a new copy instead of reopening the old one');
        self::assertTrue($again['unlocked']);
        self::assertFalse($again['outdated']);
        $this->in($this->kb->a);
        $kept = $this->em->getConnection()->fetchFirstColumn('SELECT body_md FROM note_revisions WHERE note_id = :n', ['n' => $noteId]);
        self::assertContains("Mine.\n", $kept, 'the owner\'s edit is gone from the history');
    }

    public function testRestoreWaitsForAHeldChange(): void
    {
        $noteId = $this->unlock(ShippedSkills::SKILLS)['note_id'];
        $this->request('POST', '/mcp', $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'propose', 'arguments' => ['note_id' => $noteId, 'body_md' => "Other.\n", 'comment' => 'Shorter.']],
        ]);
        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/skills/system/'.ShippedSkills::SKILLS.'/restore');
        self::assertSame(409, $this->httpStatus(), 'Restore wrote over a change waiting for review');
    }

    public function testMemexsTextReturnsWhenTheCopyStopsBeingAServedSkill(): void
    {
        $noteId = $this->unlock(ShippedSkills::RECALL)['note_id'];
        $this->edit($noteId, "Mine.\n");
        $this->sessionRequest('PUT', '/api/notes/'.$noteId, ['tags' => ['reference']]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $original = self::getContainer()->get(ShippedSkills::class)->original(ShippedSkills::RECALL);
        self::assertSame($original['body'], $this->getSkill(ShippedSkills::RECALL, $this->kb->a->agentBearer)['instructions'],
            'a copy taken off the skills was still served');

        $noteId = $this->unlock(ShippedSkills::RECALL)['note_id'];
        $this->in($this->kb->a);
        self::getContainer()->get(NoteLimbo::class)->retire($this->em->find(Note::class, $noteId), 'operator', null);
        self::assertSame($original['body'], $this->getSkill(ShippedSkills::RECALL, $this->kb->a->agentBearer)['instructions'],
            'a deleted copy was still served');
    }

    public function testACopyRestoredFromDeletedNotesIsTheCopyAgain(): void
    {
        $noteId = $this->unlock(SkillLibrary::CURATION_CHARTER)['note_id'];
        $this->edit($noteId, "You are the Curator, quokka edition.\n");
        $this->sessionRequest('DELETE', '/api/notes/'.$noteId);
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertStringNotContainsString('quokka', $this->getSkill(SkillLibrary::CURATION_CHARTER, $this->kb->a->curatorBearer)['instructions']);

        $this->loginAs($this->kb->a);
        $this->sessionRequest('POST', '/api/deleted/'.$noteId.'/restore');
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertStringStartsWith('You are the Curator, quokka edition.', $this->getSkill(SkillLibrary::CURATION_CHARTER, $this->kb->a->curatorBearer)['instructions'],
            'the restored copy is not served under its alias');
        $this->in($this->kb->a);
        $own = array_filter(self::getContainer()->get(SkillLibrary::class)->all(), static fn ($s) => $s['id'] !== null);
        self::assertSame([], array_values(array_column($own, 'slug')), 'the restored copy is served a second time under another alias');

        $this->leave();
        (new CommandTester((new Application(self::$kernel))->find('app:retire-charter-notes')))->execute([]);
        $this->in($this->kb->a);
        $this->em->clear();
        self::assertNotNull($this->em->find(Note::class, $noteId), 'the deploy retired the restored copy');
    }

    public function testAnEarlierCopyCannotComeBackOverALaterOne(): void
    {
        $first = $this->unlock(ShippedSkills::RECALL)['note_id'];
        $this->sessionRequest('DELETE', '/api/notes/'.$first);
        $second = $this->unlock(ShippedSkills::RECALL)['note_id'];
        $this->edit($second, "Second.\n");

        $this->sessionRequest('POST', '/api/deleted/'.$first.'/restore');
        self::assertSame(409, $this->httpStatus(), 'two copies of one system skill were live');
        self::assertSame("Second.\n", $this->getSkill(ShippedSkills::RECALL, $this->kb->a->agentBearer)['instructions']);

        $this->loginAs($this->kb->a);
        $this->sessionRequest('DELETE', '/api/notes/'.$second);
        $this->sessionRequest('POST', '/api/deleted/'.$first.'/restore');
        self::assertSame(200, $this->httpStatus(), 'the earlier copy could not come back once the later one was gone');
    }

    public function testTheDeploysCharterSweepLeavesAnUnlockedCharter(): void
    {
        $noteId = $this->unlock(SkillLibrary::CURATION_CHARTER)['note_id'];

        $this->leave();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:retire-charter-notes'));
        $tester->execute([]);

        $this->in($this->kb->a);
        $this->em->clear();
        self::assertNotNull($this->em->find(Note::class, $noteId), 'the deploy retired the charter the owner unlocked');
        self::assertStringContainsString(CurationCharter::CHARTER_MARKER, self::getContainer()->get(CurationCharter::class)->canon()['body'], 'the unlocked charter is no longer the one served');
    }
}
