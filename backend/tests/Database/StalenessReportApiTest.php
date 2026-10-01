<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\EditProposal;

/**
 * The staleness report as a REST client meets it.
 *
 * `PUT /api/notes/{id}` accepts a comment-only edit exactly as MCP does, and
 * for a while answered it with the wording written for an ordinary held edit —
 * "the note is unchanged until the operator approves the proposal" — while the
 * `type` in the same payload said `report`. Nothing proposes changing the note,
 * so there is nothing for an approval to apply, and a caller told otherwise
 * re-sends the edit it thinks was deferred.
 */
final class StalenessReportApiTest extends ApiTestCase
{
    public function testACommentOnlyEditIsAnsweredAsAReport(): void
    {
        $note = $this->kb->a->note('The runbook', 'Deploys go through Jenkins.');

        $this->request('PUT', '/api/notes/'.$note->getId(), $this->kb->a->agentBearer, [
            'comment' => 'Jenkins was retired last quarter.',
        ]);

        self::assertSame(202, $this->httpStatus(), $this->body());
        $answer = $this->jsonResponse();
        self::assertSame(EditProposal::TYPE_REPORT, $answer['proposal']['type']);
        self::assertFalse($answer['applied']);
        self::assertStringContainsString('Report filed', $answer['review']);
        self::assertStringNotContainsString('until the operator approves', $answer['review']);
    }

    public function testAnOrdinaryHeldEditKeepsItsOwnWording(): void
    {
        $note = $this->kb->a->note('The runbook', 'Deploys go through Jenkins.');

        $this->request('PUT', '/api/notes/'.$note->getId(), $this->kb->a->agentBearer, [
            'body_md' => 'Deploys go through GitHub Actions.',
            'comment' => 'Jenkins was retired last quarter.',
        ]);

        self::assertSame(202, $this->httpStatus(), $this->body());
        $answer = $this->jsonResponse();
        self::assertSame(EditProposal::TYPE_EDIT, $answer['proposal']['type']);
        self::assertStringContainsString('held for review', $answer['review']);
        self::assertStringNotContainsString('Report filed', $answer['review']);
    }
}
