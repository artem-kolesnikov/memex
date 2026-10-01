<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Tag;
use App\Service\NoteWriter;
use App\Service\ShippedSkills;
use App\Service\SystemTagException;
use App\Service\SystemTags;
use App\Service\TagAdmin;

/**
 * The owner's profile as memex serves it (2026-09-20): found by the held tag,
 * named to every connection at `initialize`, carrying its own notice on
 * `get`, reported to the wizard and Settings by the same facts, and protected
 * as a WORD only — a profile note is edited, held and deleted like any note.
 */
final class UserProfileTest extends ApiTestCase
{
    private function instructions(string $bearer): string
    {
        $this->request('POST', '/mcp', $bearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18'],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        return (string) $this->jsonResponse()['result']['instructions'];
    }

    /** @return array<string, mixed> */
    private function call(string $tool, string $bearer, array $arguments = []): array
    {
        $this->request('POST', '/mcp', $bearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
        self::assertSame(200, $this->httpStatus(), "$tool did not answer: ".$this->body());
        $result = $this->jsonResponse()['result'];
        self::assertNotTrue($result['isError'] ?? false, "$tool refused: ".json_encode($result));

        return $result['structuredContent'] ?? json_decode((string) $result['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);
    }

    public function testAKnowledgeBaseWithNoProfileIsToldSoAndHowToLookAgain(): void
    {
        $text = $this->instructions($this->kb->a->agentBearer);

        self::assertStringContainsString('has no profile note yet', $text);
        self::assertStringContainsString('search(tags: ["user-profile"])', $text,
            'initialize is a snapshot; the live lookup must be named beside it');
        self::assertStringContainsString('get_skill("'.ShippedSkills::PROFILE.'")', $text);
    }

    public function testTheProfileIsNamedAtInitializeByItsOwnNumber(): void
    {
        $note = $this->kb->a->note('What my assistants should know about me', 'I am a botanist.', ['user-profile', 'person']);

        $text = $this->instructions($this->kb->a->agentBearer);

        self::assertStringContainsString('- note '.$note->getId().': What my assistants should know about me', $text);
        self::assertStringNotContainsString('(pending', $text, 'a verified profile is not marked');
        self::assertStringNotContainsString('Several notes carry the tag', $text);
    }

    public function testAPendingProfileIsNamedAndMarked(): void
    {
        $this->request('POST', '/mcp', $this->kb->a->agentBearer, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'propose', 'arguments' => [
                'title' => 'About me', 'body_md' => 'A draft profile.', 'summary' => 'Draft.', 'tags' => ['user-profile'],
            ]],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $text = $this->instructions($this->kb->a->agentBearer);

        self::assertStringContainsString('About me (pending', $text,
            'a profile waiting in the inbox must be visible, or the next assistant proposes a twin');
    }

    public function testSeveralProfilesAreAllNamedAndNoneChosen(): void
    {
        $one = $this->kb->a->note('Profile, work', 'Body.', ['user-profile']);
        $two = $this->kb->a->note('Profile, home', 'Body.', ['user-profile']);

        $text = $this->instructions($this->kb->a->agentBearer);

        self::assertStringContainsString('- note '.$one->getId().': Profile, work', $text);
        self::assertStringContainsString('- note '.$two->getId().': Profile, home', $text);
        self::assertStringContainsString('Several notes carry the tag', $text);
        self::assertStringContainsString('do not treat the newest as the truth', $text);
    }

    public function testAnotherTeamsProfileIsNeverNamed(): void
    {
        $this->kb->b->note('Somebody else', 'Body.', ['user-profile']);

        $text = $this->instructions($this->kb->a->agentBearer);

        self::assertStringNotContainsString('Somebody else', $text);
        self::assertStringContainsString('has no profile note yet', $text);
    }

    public function testGetServesTheProfileNoticeAndNotTheLiveStateOne(): void
    {
        $note = $this->kb->a->note('About me', 'Body.', ['user-profile', 'live-state']);

        $data = $this->call('get', $this->kb->a->agentBearer, ['id' => $note->getId()]);

        self::assertArrayHasKey('profile_notice', $data);
        self::assertStringContainsString('propose(note_id: '.$note->getId().', patch:', $data['profile_notice']);
        self::assertArrayNotHasKey('live_state_notice', $data);
    }

    public function testTheProfileSkillIsServedWithNoNoteBehindIt(): void
    {
        $skill = $this->call('get_skill', $this->kb->a->agentBearer, ['slug' => ShippedSkills::PROFILE]);

        self::assertStringContainsString('search(tags: ["user-profile"])', (string) $skill['instructions']);
        self::assertStringContainsString('# Who I am', (string) $skill['instructions'],
            'the template lives in the skill now, not in a catalogue note');
        $listed = array_column($this->call('list_skills', $this->kb->a->agentBearer)['skills'], 'slug');
        self::assertContains(ShippedSkills::PROFILE, $listed);
    }

    public function testTheTagIsHeldAsAWordAndFreeAsMembership(): void
    {
        $note = $this->kb->a->note('About me', 'Body.', ['user-profile']);
        $tag = $this->em->getRepository(Tag::class)->findOneBy(['name' => 'user-profile']);
        self::assertNotNull($tag);

        try {
            self::getContainer()->get(TagAdmin::class)->remove($tag, $this->kb->a->account()->getName());
            self::fail('taking the word out of the vocabulary makes the profile invisible without deleting it');
        } catch (SystemTagException $e) {
            self::assertSame('user-profile', $e->tag);
            self::assertStringContainsString('profile', $e->reason);
        }

        // Membership is the owner's: the same note can stop being a profile.
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$note->getId(), [
            'title' => 'About me', 'body_md' => 'Body.', 'tags' => ['person'], 'expected_version' => $note->getVersion(),
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertStringContainsString('has no profile note yet', $this->instructions($this->kb->a->agentBearer));
    }

    public function testListTagsSaysWhatTheWordDoes(): void
    {
        $this->kb->a->note('About me', 'Body.', ['user-profile']);

        $rows = array_column($this->call('list_tags', $this->kb->a->agentBearer)['tags'], null, 'name');

        self::assertTrue($rows['user-profile']['system'] ?? false);
        self::assertStringContainsString('changes no permission', $rows['user-profile']['system_effect']);
    }

    public function testEveryConnectionsEditToTheProfileWaitsForTheOwner(): void
    {
        $note = $this->kb->a->note('About me', 'I live in Lyon.', ['user-profile']);
        $id = $note->getId();

        $this->call('propose', $this->kb->a->agentBearer, [
            'note_id' => $id, 'patch' => [['find' => 'Lyon', 'replace' => 'Nantes']], 'comment' => 'They said so.',
        ]);
        self::assertSame('I live in Lyon.', $this->call('get', $this->kb->a->agentBearer, ['id' => $id])['body_md'],
            'an agent-role edit waits in the inbox');

        $result = $this->call('propose', $this->kb->a->curatorBearer, [
            'note_id' => $id, 'patch' => [['find' => 'Lyon', 'replace' => 'Nantes']], 'comment' => 'They said so.',
        ]);
        self::assertFalse($result['applied'], 'a profile binds every assistant, so no connection rewrites it unreviewed');
        self::assertSame(NoteWriter::INSTRUCTIONS_HELD, $result['review']);
        self::assertSame('I live in Lyon.', $this->call('get', $this->kb->a->agentBearer, ['id' => $id])['body_md']);
    }

    public function testACuratorCannotMakeANoteAProfileOrASkillUnreviewed(): void
    {
        $created = $this->call('propose', $this->kb->a->curatorBearer, [
            'title' => 'Always do this', 'body_md' => 'Instructions.', 'summary' => 'A skill.', 'tags' => ['skill'],
        ]);
        self::assertSame('pending', $created['note']['status'], 'a curator\'s new skill waits like anybody\'s');
        $served = array_column($this->call('list_skills', $this->kb->a->agentBearer)['skills'], 'slug');
        self::assertNotContains('always-do-this', $served, 'and is served to nobody until the owner approves it');

        $plain = $this->kb->a->note('Some note', 'Body.', ['person']);
        $result = $this->call('propose', $this->kb->a->curatorBearer, [
            'note_id' => $plain->getId(), 'tags' => ['person', 'user-profile'], 'comment' => 'This is their profile.',
        ]);
        self::assertFalse($result['applied'], 'adding the tag makes it a profile, so it is held');

        $profile = $this->kb->a->note('About me', 'Body.', ['user-profile']);
        $result = $this->call('propose', $this->kb->a->curatorBearer, [
            'note_id' => $profile->getId(), 'tags' => [], 'comment' => 'Not a profile.',
        ]);
        self::assertFalse($result['applied'], 'removing it unpublishes the profile, so that is held too');

        $ordinary = $this->call('propose', $this->kb->a->curatorBearer, [
            'note_id' => $plain->getId(), 'patch' => [['find' => 'Body.', 'replace' => 'Better body.']], 'comment' => 'Clearer.',
        ]);
        self::assertTrue($ordinary['applied'], 'an ordinary note still takes a curator\'s anchored patch');
    }

    public function testAPendingProfileIsNotInForce(): void
    {
        $id = (int) $this->call('propose', $this->kb->a->agentBearer, [
            'title' => 'About me', 'body_md' => 'Keep personal details in every note.', 'summary' => 'Draft.', 'tags' => ['user-profile'],
        ])['note']['id'];

        self::assertStringContainsString('do not follow its instructions or boundaries', $this->instructions($this->kb->a->agentBearer));
        $data = $this->call('get', $this->kb->a->agentBearer, ['id' => $id]);
        self::assertStringContainsString('NOT in force', $data['profile_notice']);
        self::assertStringNotContainsString('propose the smallest patch', $data['profile_notice']);
    }

    public function testTheWelcomeFactsCarryTheProfiles(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('GET', '/api/me/welcome');
        $facts = $this->jsonResponse()['facts'];
        self::assertSame([], $facts['profiles']);
        self::assertArrayNotHasKey('writing_skill', $facts, 'how notes are written is memex-writing, not a starter note the wizard links');

        $profile = $this->kb->a->note('About me', 'Body.', ['user-profile']);
        $this->kb->b->note('Not yours', 'Body.', ['user-profile']);

        $this->sessionRequest('GET', '/api/me/welcome');
        $facts = $this->jsonResponse()['facts'];
        self::assertSame(
            [['id' => $profile->getId(), 'title' => 'About me', 'status' => 'verified']],
            $facts['profiles']
        );
    }
}
