<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\MemexWriting;
use App\Service\Personalization;
use App\Service\ShippedSkills;
use App\Tests\Support\Tenant;

/**
 * Settings › Personalization: the presets for memex-writing, stored as the
 * choices that differ from the defaults, changed only from a browser, refused
 * when a tab is stale, and delivered to every connection — the skill itself,
 * the pointers at connect time and in `propose`, and a notice once on the next
 * tool result after a change.
 */
final class PersonalizationTest extends ApiTestCase
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

        return $result['structuredContent'] ?? json_decode((string) $result['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);
    }

    private function proposeDescription(string $bearer): string
    {
        $this->request('POST', '/mcp', $bearer, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $tools = array_column($this->jsonResponse()['result']['tools'], 'description', 'name');

        return (string) $tools['propose'];
    }

    private function stored(Tenant $tenant): mixed
    {
        $this->in($tenant);

        return $this->em->getConnection()->fetchOne('SELECT personalization FROM settings');
    }

    /** @return array<string, mixed> */
    private function patch(array $body): array
    {
        $this->sessionRequest('PATCH', '/api/personalization', $body);
        self::assertSame(200, $this->httpStatus(), $this->body());

        return $this->jsonResponse();
    }

    public function testAKnowledgeBaseStartsOnTheDefaults(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/personalization');
        self::assertSame(200, $this->httpStatus(), $this->body());
        $view = $this->jsonResponse();

        self::assertSame(Personalization::defaults(), $view['settings']);
        self::assertTrue($view['settings']['writing']);
        self::assertSame(['one_subject', 'summary', 'mixed', 'reasons'], [$view['settings']['scope'], $view['settings']['opening'], $view['settings']['format'], $view['settings']['reasoning']]);
        self::assertSame(['scope', 'opening', 'format', 'reasoning'], array_column($view['options'], 'axis'));
        self::assertSame(MemexWriting::text(Personalization::defaults()), $view['skill']['text']);
        self::assertStringContainsString('no profile note yet', $view['profile_paragraph']);
        self::assertNull($this->stored($this->kb->a), 'nothing chosen, nothing stored');
    }

    public function testOnlyChoicesThatDifferAreStoredAndNullPutsOneBack(): void
    {
        $this->loginAs($this->kb->a);
        $view = $this->patch(['reasoning' => 'rationale', 'reasoning_sources' => true, 'scope' => 'one_subject']);
        self::assertSame(['reasoning' => 'rationale', 'reasoning_sources' => true], json_decode((string) $this->stored($this->kb->a), true));
        self::assertSame('rationale', $view['settings']['reasoning']);

        $this->patch(['reasoning' => null, 'reasoning_sources' => false]);
        self::assertNull($this->stored($this->kb->a), 'every default chosen back is one row: NULL');
    }

    public function testAnythingOutsideTheVocabularyIsRefused(): void
    {
        $this->loginAs($this->kb->a);
        foreach ([
            ['scope' => 'everything'],
            ['scope' => true],
            ['scope_short' => 'yes'],
            ['writing' => 1],
            ['writing_wins' => false],
            ['tone' => 'friendly'],
            ['language' => 'fr'],
            ['save_policy' => ['decisions' => 'asked']],
            [],
        ] as $body) {
            $this->sessionRequest('PATCH', '/api/personalization', $body);
            self::assertSame(400, $this->httpStatus(), json_encode($body).' was accepted: '.$this->body());
        }
        self::assertNull($this->stored($this->kb->a));
    }

    public function testKeepItShortDoesNotGoWithAWholeTopic(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PATCH', '/api/personalization', ['scope' => 'whole_topic', 'scope_short' => true]);
        self::assertSame(400, $this->httpStatus());
        self::assertSame('"scope_short" does not go with scope "whole_topic"', $this->jsonResponse()['error']);

        $this->patch(['scope_short' => true]);
        $this->sessionRequest('PATCH', '/api/personalization', ['scope' => 'whole_topic']);
        self::assertSame(400, $this->httpStatus(), 'choosing the topic under a short note is the same conflict');

        $view = $this->patch(['scope' => 'whole_topic', 'scope_short' => false]);
        self::assertSame('whole_topic', $view['settings']['scope']);
        self::assertSame(['whole_topic'], $view['options'][0]['add_ons'][0]['conflicts_with']);
    }

    public function testKeysFromTheRejectedCutsAreIgnoredAndDroppedOnTheNextWrite(): void
    {
        $this->kb->a->settings()->setPersonalization(['protected_tags' => ['finance'], 'profile_corrections' => false, 'format' => 'prose']);
        $this->em->flush();

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/personalization');
        $settings = $this->jsonResponse()['settings'];
        self::assertArrayNotHasKey('protected_tags', $settings);
        self::assertSame('prose', $settings['format']);

        $this->patch(['opening' => 'answer']);
        self::assertSame(['opening' => 'answer', 'format' => 'prose'], json_decode((string) $this->stored($this->kb->a), true));
    }

    public function testAStaleTabIsRefusedRatherThanOverwritingAnother(): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/personalization');
        $seen = $this->jsonResponse()['revision'];

        $this->patch(['format' => 'prose', 'expected_revision' => $seen]);

        $this->sessionRequest('PATCH', '/api/personalization', ['format' => 'bullets', 'expected_revision' => $seen]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('prose', $this->jsonResponse()['current']['settings']['format']);
    }

    public function testNoConnectionReadsOrChangesThem(): void
    {
        foreach ([$this->kb->a->agentBearer, $this->kb->a->curatorBearer] as $bearer) {
            $this->request('GET', '/api/personalization', $bearer);
            self::assertSame(403, $this->httpStatus());
            $this->request('PATCH', '/api/personalization', $bearer, ['writing' => false]);
            self::assertSame(403, $this->httpStatus(), 'a connection must never adjust its own instructions');
        }
        self::assertNull($this->stored($this->kb->a));
    }

    public function testThePresetsReachTheSkillEveryConnectionLoads(): void
    {
        $this->loginAs($this->kb->a);
        $this->patch(['reasoning' => 'bare', 'format_minimal' => true]);

        $skill = (string) $this->call('get_skill', $this->kb->a->agentBearer, ['slug' => ShippedSkills::WRITING])['instructions'];
        self::assertStringContainsString('Record the fact, outcome or decision itself', $skill);
        self::assertStringContainsString('Use no bold or italics', $skill);
        self::assertStringNotContainsString('Bold at most a few words', $skill, 'minimal markup takes the bold line out rather than contradicting it');
        self::assertStringNotContainsString('the options considered', $skill, 'conclusions only reshapes the decision shape too');
        self::assertStringContainsString('where the two differ, follow this skill', $skill);
        self::assertStringNotContainsString('Put the conclusion first', $skill);
    }

    public function testOneKnowledgeBasesChoicesAreNotAnothers(): void
    {
        $this->loginAs($this->kb->a);
        $this->patch(['scope' => 'one_idea', 'writing' => true]);

        self::assertNull($this->stored($this->kb->b));
        $a = (string) $this->call('get_skill', $this->kb->a->agentBearer, ['slug' => ShippedSkills::WRITING])['instructions'];
        $b = (string) $this->call('get_skill', $this->kb->b->agentBearer, ['slug' => ShippedSkills::WRITING])['instructions'];
        self::assertStringContainsString('Keep each note to one idea', $a);
        self::assertStringNotContainsString('Keep each note to one idea', $b);
    }

    public function testWhileOnEveryPointerNamesTheSkill(): void
    {
        $text = $this->instructions($this->kb->a->agentBearer);
        self::assertStringContainsString('**Writing notes.**', $text);
        self::assertStringContainsString('get_skill("memex-writing")', $text);
        self::assertStringContainsString(MemexWriting::PROPOSE_SENTENCE, $this->proposeDescription($this->kb->a->agentBearer));
        self::assertContains(ShippedSkills::WRITING, array_column($this->call('list_skills', $this->kb->a->agentBearer)['skills'], 'slug'));
        self::assertStringContainsString('If `memex-writing` is in your skill list', (string) $this->call('get_skill', $this->kb->a->agentBearer, ['slug' => ShippedSkills::RECALL])['instructions']);
    }

    public function testOffRemovesTheSkillAndEveryPointerAtIt(): void
    {
        $this->loginAs($this->kb->a);
        $view = $this->patch(['writing' => false]);
        self::assertSame([], $view['skill']['loaded_by']);
        self::assertNotSame('', $view['skill']['text'], 'the page still shows what it would say');

        $text = $this->instructions($this->kb->a->agentBearer);
        self::assertStringNotContainsString('memex-writing', $text);
        self::assertStringNotContainsString("\n\n\n", $text, 'no hole where the paragraph was');
        self::assertStringNotContainsString('memex-writing', $this->proposeDescription($this->kb->a->agentBearer));
        self::assertNotContains(ShippedSkills::WRITING, array_column($this->call('list_skills', $this->kb->a->agentBearer)['skills'], 'slug'));

        $refused = $this->call('get_skill', $this->kb->a->agentBearer, ['slug' => ShippedSkills::WRITING]);
        self::assertArrayNotHasKey('instructions', $refused);

        $this->sessionRequest('GET', '/api/skills');
        $row = array_column($this->jsonResponse()['skills'], null, 'slug')[ShippedSkills::WRITING];
        self::assertSame('switched_off', $row['status'], 'the Skills page says it is off instead of hiding it');

        $this->kb->a->note('memex — writing', 'Mine.', ['skill']);
        self::assertNotContains(ShippedSkills::WRITING, array_column($this->call('list_skills', $this->kb->a->agentBearer)['skills'], 'slug'),
            'the slug stays reserved while the skill is off, so no note answers to it');
    }

    /**
     * A note given the slug before memex reserved it keeps its record, so the
     * reservation alone does not stop it answering to the built-in name.
     */
    public function testANoteHoldingTheSlugFromBeforeTheReservationIsNeverServedUnderIt(): void
    {
        $note = $this->kb->a->note('House style', 'Mine.', ['skill']);
        $this->call('list_skills', $this->kb->a->agentBearer);
        $this->in($this->kb->a);
        $this->em->getConnection()->executeStatement(
            'UPDATE skill_settings SET slug = :s WHERE note_id = :n',
            ['s' => ShippedSkills::WRITING, 'n' => $note->getId()],
        );

        $served = $this->call('list_skills', $this->kb->a->agentBearer)['skills'];
        self::assertCount(1, array_keys(array_column($served, 'slug'), ShippedSkills::WRITING), 'two skills answer to memex-writing');
        self::assertStringContainsString('# Every note', (string) $this->call('get_skill', $this->kb->a->agentBearer, ['slug' => ShippedSkills::WRITING])['instructions']);

        $this->loginAs($this->kb->a);
        $this->patch(['writing' => false]);
        self::assertNotContains(ShippedSkills::WRITING, array_column($this->call('list_skills', $this->kb->a->agentBearer)['skills'], 'slug'));
        self::assertArrayNotHasKey('instructions', $this->call('get_skill', $this->kb->a->agentBearer, ['slug' => ShippedSkills::WRITING]),
            'with memex-writing off, the owner\'s note answered to the built-in name');
    }

    public function testAConnectionOpenedBeforeAChangeIsToldOnce(): void
    {
        $this->instructions($this->kb->a->agentBearer);
        self::assertArrayNotHasKey('personalization_changed', $this->call('list_tags', $this->kb->a->agentBearer));

        $this->loginAs($this->kb->a);
        $this->patch(['opening' => 'answer']);

        $first = $this->call('list_tags', $this->kb->a->agentBearer);
        self::assertStringContainsString('Load get_skill("memex-writing") again', $first['personalization_changed'] ?? '');
        self::assertArrayNotHasKey('personalization_changed', $this->call('list_tags', $this->kb->a->agentBearer),
            'told once, not on every result');
        self::assertStringContainsString('memex-writing', $this->call('list_tags', $this->kb->a->curatorBearer)['personalization_changed'] ?? '',
            'and each connection is told for itself');

        $this->loginAs($this->kb->a);
        $this->patch(['writing' => false]);
        self::assertStringContainsString('switched memex-writing off', $this->call('list_tags', $this->kb->a->agentBearer)['personalization_changed'] ?? '');
    }

    public function testThePageNamesTheConnectionsThatLoadedTheCurrentText(): void
    {
        $this->call('get_skill', $this->kb->a->agentBearer, ['slug' => ShippedSkills::WRITING]);
        $this->call('get_skill', $this->kb->b->agentBearer, ['slug' => ShippedSkills::WRITING]);

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/personalization');
        $loaded = $this->jsonResponse()['skill']['loaded_by'];
        self::assertCount(1, $loaded, 'another knowledge base\'s connection is not named');

        $view = $this->patch(['format' => 'bullets']);
        self::assertSame([], $view['skill']['loaded_by'], 'an older text is not the current one');
    }
}
