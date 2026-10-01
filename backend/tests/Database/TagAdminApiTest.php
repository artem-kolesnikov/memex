<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Tag;
use App\Tests\Support\Tenant;

/**
 * Who may reshape a vocabulary, and who may only read one.
 *
 * Removing a tag rewrites every note carrying it, outside the review gate,
 * with no limbo to restore from. So the gate that holds an agent's every
 * ordinary write has to hold here too, or it is reachable by going round it:
 * an assistant that cannot edit one note without a person reading the edit
 * could otherwise strip a word from ninety of them. The same reasoning closed
 * import to bearer tokens (audit L-1, 2026-08-22).
 */
final class TagAdminApiTest extends ApiTestCase
{
    public function testAnAgentTokenMayReadTheVocabularyAndNotChangeIt(): void
    {
        $this->kb->a->note('One', 'Body.', ['inbox']);
        $tagId = $this->tagId($this->kb->a, 'inbox');

        $this->request('GET', '/api/tags', $this->kb->a->agentBearer);
        self::assertSame(200, $this->httpStatus(), 'Choosing words needs the vocabulary');

        $this->request('DELETE', '/api/tags/'.$tagId, $this->kb->a->agentBearer);
        self::assertSame(403, $this->httpStatus());
        self::assertNotNull($this->tag($this->kb->a, 'inbox'), 'And the tag is still there');
    }

    /** A curator writes unattended, and this is still not one of its powers. */
    public function testACuratorTokenCannotChangeTheVocabularyEither(): void
    {
        $this->kb->a->note('One', 'Body.', ['inbox']);

        $this->request('DELETE', '/api/tags/'.$this->tagId($this->kb->a, 'inbox'), $this->kb->a->curatorBearer);
        self::assertSame(403, $this->httpStatus());
        self::assertNotNull($this->tag($this->kb->a, 'inbox'));
    }

    public function testTheOwnerRemovesATagAndIsToldHowManyNotesChanged(): void
    {
        $this->kb->a->note('One', 'Body.', ['inbox']);
        $this->kb->a->note('Two', 'Body.', ['inbox']);
        $tagId = $this->tagId($this->kb->a, 'inbox');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('DELETE', '/api/tags/'.$tagId);

        self::assertSame(200, $this->httpStatus());
        self::assertSame(2, $this->jsonResponse()['notes_changed']);
        self::assertNull($this->tag($this->kb->a, 'inbox'));
    }

    public function testTheOwnerMergesOneTagIntoAnother(): void
    {
        $this->kb->a->note('One', 'Body.', ['project']);
        $from = $this->tagId($this->kb->a, 'project');
        $this->kb->a->note('Two', 'Body.', ['projects']);
        $into = $this->tagId($this->kb->a, 'projects');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('DELETE', '/api/tags/'.$from.'?merge_into='.$into);

        self::assertSame(200, $this->httpStatus());
        $answer = $this->jsonResponse();
        self::assertSame('project', $answer['removed']);
        self::assertSame('projects', $answer['merged_into']);
        self::assertSame(1, $answer['notes_changed']);
    }

    public function testAnotherTeamsTagIsNotFound(): void
    {
        $this->kb->b->note('Theirs', 'Body.', ['private-word']);
        $tagId = $this->tagId($this->kb->b, 'private-word');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('DELETE', '/api/tags/'.$tagId);

        self::assertSame(404, $this->httpStatus());
        self::assertStringNotContainsString('private-word', $this->body());
        self::assertNotNull($this->tag($this->kb->b, 'private-word'), 'And it is still on their notes');
    }

    public function testMergingIntoAnotherTeamsTagIsRefused(): void
    {
        $this->kb->a->note('Mine', 'Body.', ['mine']);
        $mine = $this->tagId($this->kb->a, 'mine');
        // Tag ids restart per vault: B's tag is its second, so A holds no tag by that id.
        $this->kb->b->note('Filler', 'Body.', ['filler']);
        $this->kb->b->note('Theirs', 'Body.', ['theirs']);
        $theirs = $this->tagId($this->kb->b, 'theirs');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('DELETE', '/api/tags/'.$mine.'?merge_into='.$theirs);

        self::assertSame(400, $this->httpStatus());
        self::assertStringNotContainsString('theirs', $this->body());
        self::assertNotNull($this->tag($this->kb->a, 'mine'));
        self::assertNotNull($this->tag($this->kb->b, 'theirs'));
    }

    public function testTheVocabularyIsServedWithTheNamesThatWereRejected(): void
    {
        $this->kb->a->note('One', 'Body.', ['project', 'projects']);
        $from = $this->tagId($this->kb->a, 'project');
        $into = $this->tagId($this->kb->a, 'projects');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('DELETE', '/api/tags/'.$from.'?merge_into='.$into);
        $this->sessionRequest('GET', '/api/tags');

        $answer = $this->jsonResponse();
        self::assertSame(['projects'], array_column($answer['tags'], 'name'));
        self::assertSame('project', $answer['retired'][0]['name']);
        self::assertSame('projects', $answer['retired'][0]['merged_into']);
    }

    public function testTheOwnerCannotRemoveATagMemexReads(): void
    {
        // The failure this prevents is silent: nothing afterwards would say
        // why connected assistants stopped loading their instructions.
        $this->kb->a->note('Charter', 'Body.', ['skill']);
        $tagId = $this->tagId($this->kb->a, 'skill');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('DELETE', '/api/tags/'.$tagId);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('skill', $this->jsonResponse()['system_tag']);
        self::assertNotNull($this->tag($this->kb->a, 'skill'));
        self::assertNotSame('', trim($this->jsonResponse()['error'] ?? ''), 'and it says what breaks');
    }

    public function testALockedTagCannotBeEmptiedByMergingItAwayEither(): void
    {
        // The interesting half. Blocking plain removal and leaving the merge
        // door open would be a lock that reads as one and is not: a merge
        // takes the word off every note carrying it exactly as a removal does.
        $this->kb->a->note('Runbook', 'Body.', ['live-state']);
        $from = $this->tagId($this->kb->a, 'live-state');
        $this->kb->a->note('Other', 'Body.', ['infra']);
        $into = $this->tagId($this->kb->a, 'infra');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('DELETE', '/api/tags/'.$from.'?merge_into='.$into);

        self::assertSame(409, $this->httpStatus());
        self::assertNotNull($this->tag($this->kb->a, 'live-state'));
    }

    public function testAnotherTagMayStillBeMergedINTOALockedOne(): void
    {
        // What is locked is the word, not membership. Consolidating `skills`
        // into `skill` is the tag hygiene the curator charter asks for, and
        // refusing it would leave the commonest synonym unfixable.
        $this->kb->a->note('Charter', 'Body.', ['skill']);
        $into = $this->tagId($this->kb->a, 'skill');
        $this->kb->a->note('Another', 'Body.', ['skills']);
        $from = $this->tagId($this->kb->a, 'skills');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('DELETE', '/api/tags/'.$from.'?merge_into='.$into);

        self::assertSame(200, $this->httpStatus());
        self::assertSame(1, $this->jsonResponse()['notes_changed']);
        self::assertNotNull($this->tag($this->kb->a, 'skill'));
        self::assertNull($this->tag($this->kb->a, 'skills'));
    }

    public function testTheVocabularySaysWhichWordsAreHeldAndWhy(): void
    {
        // The tag screen draws its padlock from these two fields, so a row
        // that carries the flag without the reason is a refusal with no
        // explanation attached.
        $this->kb->a->note('Charter', 'Body.', ['skill', 'inbox']);

        $this->loginAs($this->kb->a);
        $this->sessionRequest('GET', '/api/tags');

        $rows = [];
        foreach ($this->jsonResponse()['tags'] as $row) {
            $rows[$row['name']] = $row;
        }
        self::assertTrue($rows['skill']['system']);
        self::assertNotSame('', trim((string) $rows['skill']['system_reason']));
        self::assertFalse($rows['inbox']['system']);
        self::assertNull($rows['inbox']['system_reason']);
    }

    private function tag(Tenant $tenant, string $name): ?Tag
    {
        $this->in($tenant);

        return $this->em->getRepository(Tag::class)->findOneBy(['name' => $name]);
    }

    private function tagId(Tenant $tenant, string $name): int
    {
        $tag = $this->tag($tenant, $name);
        self::assertNotNull($tag, 'Expected a tag called '.$name);

        return (int) $tag->getId();
    }
}
