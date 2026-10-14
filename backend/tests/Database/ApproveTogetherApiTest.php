<?php

declare(strict_types=1);

namespace App\Tests\Database;

/**
 * A card of several connections' changes to one note, decided at once
 * (operator, 2026-10-14), and the amendment paths Codex found losing work.
 */
final class ApproveTogetherApiTest extends ApiTestCase
{
    private const BODY = "Status: on track. Stage 1 is merged.\n\n- Mail goes through Postmark.\n";

    private string $second = 'mxt_agent_second_b';

    private function file(string $bearer, int $note, array $arguments): int
    {
        $this->request('PUT', '/api/notes/'.$note, $bearer, $arguments);
        self::assertSame(202, $this->httpStatus(), $this->body());

        return (int) $this->jsonResponse()['proposal']['id'];
    }

    /** @return array{id: int, expected_revision: int, expected_version: int} */
    private function seen(int $proposal): array
    {
        $this->sessionRequest('GET', '/api/proposals/'.$proposal);
        $card = $this->jsonResponse();

        return ['id' => $proposal, 'expected_revision' => $card['revision'], 'expected_version' => $card['note']['version']];
    }

    /** @return array<int, array<string, mixed>> */
    private function history(int $note): array
    {
        $this->in($this->kb->b);

        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT body_md, replaced_by, operation FROM note_revisions WHERE note_id = :id ORDER BY id',
            ['id' => $note],
        );
    }

    private function tagsOf(int $note): array
    {
        $this->sessionRequest('GET', '/api/notes/'.$note);
        $tags = array_map(static fn (array $tag): string => $tag['name'], $this->jsonResponse()['tags']);
        sort($tags);

        return $tags;
    }

    public function testAllChangesAndTheOwnersWordsLandTogetherEachCreditedToItsAuthor(): void
    {
        $note = (int) $this->kb->b->note('MM3 record', self::BODY)->getId();
        $this->kb->b->connection('Orchestrator', $this->second);
        $a = $this->file($this->kb->b->agentBearer, $note, ['patch' => [['find' => 'Mail goes through Postmark.', 'replace' => 'Mail goes through Postmark, from hello@.']]]);
        $b = $this->file($this->second, $note, ['patch' => [['find' => 'Status: on track.', 'replace' => 'Status: blocked.']]]);

        $this->loginAs($this->kb->b);
        $this->sessionRequest('POST', '/api/proposals/approve-together', [
            'items' => [$this->seen($a), $this->seen($b)],
            'body_md' => "Status: blocked until CI is green. Stage 1 is merged.\n\n- Mail goes through Postmark, from hello@.\n",
        ]);

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame("Status: blocked until CI is green. Stage 1 is merged.\n\n- Mail goes through Postmark, from hello@.\n", $this->jsonResponse()['note']['body_md']);
        self::assertSame(['proposal', 'proposal', 'amendment'], array_column($this->history($note), 'operation'));
        self::assertSame('human', $this->history($note)[2]['replaced_by']);
    }

    public function testAnAmendmentThatChangesNothingStillApprovesEverything(): void
    {
        $note = (int) $this->kb->b->note('MM3 record', self::BODY)->getId();
        $this->kb->b->connection('Orchestrator', $this->second);
        $a = $this->file($this->kb->b->agentBearer, $note, ['patch' => [['find' => 'Mail goes through Postmark.', 'replace' => 'Mail goes through Postmark, from hello@.']]]);
        $b = $this->file($this->second, $note, ['patch' => [['find' => 'Status: on track.', 'replace' => 'Status: blocked.']]]);

        $this->loginAs($this->kb->b);
        $this->sessionRequest('POST', '/api/proposals/approve-together', ['items' => [$this->seen($a), $this->seen($b)], 'title' => 'MM3 record ']);

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame(['proposal', 'proposal'], array_column($this->history($note), 'operation'));
    }

    public function testNothingIsAppliedWhenOneChangeCannotBe(): void
    {
        $note = (int) $this->kb->b->note('MM3 record', self::BODY)->getId();
        $this->kb->b->connection('Orchestrator', $this->second);
        $a = $this->file($this->kb->b->agentBearer, $note, ['patch' => [['find' => 'Mail goes through Postmark.', 'replace' => 'Mail goes through Postmark, from hello@.']]]);
        $b = $this->file($this->second, $note, ['patch' => [['find' => 'Status: on track.', 'replace' => 'Status: blocked.']]]);
        $this->loginAs($this->kb->b);
        $items = [$this->seen($a), $this->seen($b)];
        // The owner rewrites the sentence the second change holds, after loading the card.
        $this->sessionRequest('PUT', '/api/notes/'.$note, ['body_md' => str_replace('Status: on track.', 'Status: paused.', self::BODY)]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $items = [$this->seen($a), $this->seen($b)];

        $this->sessionRequest('POST', '/api/proposals/approve-together', ['items' => $items]);

        self::assertSame(409, $this->httpStatus(), $this->body());
        $this->sessionRequest('GET', '/api/notes/'.$note);
        self::assertStringContainsString('Mail goes through Postmark.', $this->jsonResponse()['body_md'], 'The change that did fit was not applied alone');
        self::assertSame(2, $this->jsonResponse()['pending_proposals']);
    }

    public function testRevisingOnlyTheBodyKeepsATagSomebodyElseAddedMeanwhile(): void
    {
        $note = (int) $this->kb->b->note('MM3 record', self::BODY, ['mm3'])->getId();
        $proposal = $this->file($this->kb->b->agentBearer, $note, ['tags' => ['mm3', 'design']]);
        $this->loginAs($this->kb->b);
        $this->sessionRequest('PUT', '/api/notes/'.$note, ['tags' => ['mm3', 'auth']]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $this->file($this->kb->b->agentBearer, $note, ['patch' => [['find' => 'Status: on track.', 'replace' => 'Status: blocked.']]]);

        $this->sessionRequest('POST', '/api/proposals/'.$proposal.'/approve', array_diff_key($this->seen($proposal), ['id' => 0]));

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame(['auth', 'design', 'mm3'], $this->tagsOf($note));
    }

    public function testTheOwnerRemovingATagInAmendIsHonoured(): void
    {
        $note = (int) $this->kb->b->note('MM3 record', self::BODY, ['mm3'])->getId();
        $proposal = $this->file($this->kb->b->agentBearer, $note, ['tags' => ['mm3', 'design']]);
        $this->loginAs($this->kb->b);
        $this->sessionRequest('PUT', '/api/notes/'.$note, ['tags' => ['mm3', 'auth']]);

        // The card shows mm3, auth, design; the owner removes auth.
        $this->sessionRequest('POST', '/api/proposals/'.$proposal.'/approve', array_diff_key($this->seen($proposal), ['id' => 0]) + ['tags' => ['mm3', 'design']]);

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame(['design', 'mm3'], $this->tagsOf($note));
    }

    public function testRescuingAChangeThatNoLongerFitsKeepsItsTags(): void
    {
        $note = (int) $this->kb->b->note('MM3 record', self::BODY, ['mm3'])->getId();
        $proposal = $this->file($this->kb->b->agentBearer, $note, ['tags' => ['mm3', 'design'], 'patch' => [['find' => 'Status: on track.', 'replace' => 'Status: blocked.']]]);
        $this->loginAs($this->kb->b);
        $this->sessionRequest('PUT', '/api/notes/'.$note, ['body_md' => str_replace('Status: on track.', 'Status: paused.', self::BODY)]);

        $this->sessionRequest('POST', '/api/proposals/'.$proposal.'/approve', array_diff_key($this->seen($proposal), ['id' => 0]) + [
            'body_md' => str_replace('Status: on track.', 'Status: blocked, then paused.', self::BODY),
        ]);

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame(['design', 'mm3'], $this->tagsOf($note), "The assistant's tag went in although its sentence was rewritten");
        $this->sessionRequest('GET', '/api/notes/'.$note);
        self::assertStringContainsString('Status: blocked, then paused.', $this->jsonResponse()['body_md']);
    }
}
