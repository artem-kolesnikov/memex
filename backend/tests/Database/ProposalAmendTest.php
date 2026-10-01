<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;

class ProposalAmendTest extends ApiTestCase
{
    public function testTheOperatorCanApproveTheirOwnVersionOfAnEdit(): void
    {
        $note = $this->kb->a->note('Greenhouse', 'Plant tomatoes in March.');
        $proposal = $this->proposeEdit($note, 'Plant tomatoes in Marhc and peppers in May.');

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$proposal.'/approve', [
            'body_md' => 'Plant tomatoes in March and peppers in May.',
            'comment' => 'Fixed the typo before applying.',
        ]);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->in($this->kb->a);
        $fresh = $this->findNumbered($note->getId());
        self::assertSame('Plant tomatoes in March and peppers in May.', $fresh->getBodyMd(), 'The operator\'s text is what landed');
        self::assertNull($this->findProposalNumbered($proposal), 'And the proposal is spent');
    }

    public function testAnAmendmentReplacesAPatchOutright(): void
    {
        $note = $this->kb->a->note('Greenhouse', 'Plant tomatoes in March.');
        $this->request('PUT', '/api/notes/'.$note->getId(), $this->kb->a->agentBearer, [
            'patch' => [['find' => 'March', 'replace' => 'Marhc']],
        ]);
        self::assertSame(202, $this->httpStatus(), $this->body());
        $proposal = $this->jsonResponse()['proposal']['id'];

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$proposal.'/approve', ['body_md' => 'Plant tomatoes in April.']);
        self::assertSame(200, $this->httpStatus(), $this->body());

        $this->in($this->kb->a);
        self::assertSame('Plant tomatoes in April.', $this->findNumbered($note->getId())->getBodyMd());
    }

    public function testOnlyAnEditCanBeAmended(): void
    {
        $note = $this->kb->a->note('Greenhouse', 'Plant tomatoes in March.');
        $this->request('DELETE', '/api/notes/'.$note->getId(), $this->kb->a->agentBearer, ['reason' => 'Stale']);
        self::assertSame(202, $this->httpStatus(), $this->body());
        $proposal = $this->jsonResponse()['proposal']['id'];

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$proposal.'/approve', ['body_md' => 'Something else']);
        self::assertSame(400, $this->httpStatus());

        $this->in($this->kb->a);
        self::assertNotNull($this->findNumbered($note->getId()), 'The delete was not applied');
        self::assertNotNull($this->findProposalNumbered($proposal), 'And still waits');
    }

    public function testAnEmptyAmendmentIsRefused(): void
    {
        $note = $this->kb->a->note('Greenhouse', 'Plant tomatoes in March.');
        $proposal = $this->proposeEdit($note, 'Plant tomatoes in April.');

        $this->loginAs($this->kb->a);
        $this->reviewedRequest('POST', '/api/proposals/'.$proposal.'/approve', ['body_md' => '   ']);
        self::assertSame(400, $this->httpStatus());

        $this->in($this->kb->a);
        self::assertSame('Plant tomatoes in March.', $this->findNumbered($note->getId())->getBodyMd());
    }

    private function proposeEdit(Note $note, string $body): int
    {
        $this->request('PUT', '/api/notes/'.$note->getId(), $this->kb->a->agentBearer, ['body_md' => $body]);
        self::assertSame(202, $this->httpStatus(), $this->body());

        return (int) $this->jsonResponse()['proposal']['id'];
    }
}
