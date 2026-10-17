<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\NoteRevisions;

/**
 * What the owner approves together is recorded at one moment, however long
 * the approvals take, so History and `edited_with` credit every assistant in
 * it. Stamped one by one, two approvals either side of a second credited only
 * the later one.
 */
final class ApprovedTogetherTest extends ApiTestCase
{
    public function testSnapshotsTakenTogetherShareOneMomentAndOnlyThose(): void
    {
        $this->in($this->kb->a);
        $note = $this->kb->a->note('Plan', 'Status: on track.');
        $revisions = static::getContainer()->get(NoteRevisions::class);

        [$first, $second] = $revisions->atOneMoment(static function () use ($revisions, $note): array {
            $first = $revisions->snapshot($note, 'human');
            usleep(1_100_000);

            return [$first, $revisions->snapshot($note, 'human')];
        });
        $after = $revisions->snapshot($note, 'human');

        self::assertEquals($first->getReplacedAt(), $second->getReplacedAt(), 'Two approvals a second apart were recorded at different moments');
        self::assertGreaterThan($first->getReplacedAt(), $after->getReplacedAt(), 'The moment outlived the approvals it was for');
    }
}
