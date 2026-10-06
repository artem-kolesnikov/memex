<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\CuratorLogEntry;
use App\Entity\EditProposal;
use App\Entity\Note;
use App\Service\NoteLimbo;
use App\Service\NoteWriter;
use App\Tests\Support\Tenant;
use App\Tests\Support\TwoTeamFixture;

/**
 * Proposals and journal rows are numbered per vault, the way notes are: a new
 * account's first held edit is #1, not whatever the installation's global
 * sequence had reached (operator, 2026-09-22 — a fresh account opened on
 * proposal #1438).
 */
final class RecordNumberingTest extends DatabaseTestCase
{
    private NoteWriter $writer;
    private TwoTeamFixture $kbs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = self::getContainer()->get(NoteWriter::class);
        $this->kbs = new TwoTeamFixture(self::getContainer());
    }

    private function propose(Tenant $t, Note $note, string $body): EditProposal
    {
        return $this->writer->propose($note, $t->agentToken(), null, $body, null);
    }

    private function log(Tenant $t, string $description): CuratorLogEntry
    {
        $t->enter();
        $entry = new CuratorLogEntry('operator', CuratorLogEntry::ACTION_REJECTED, $description);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    public function testAFreshTeamsProposalsStartAtOne(): void
    {
        $one = $this->kbs->a->note('One');
        $two = $this->kbs->a->note('Two');

        $first = $this->propose($this->kbs->a, $one, 'Changed.');
        $second = $this->propose($this->kbs->a, $two, 'Changed.');

        self::assertSame(1, $first->getId());
        self::assertSame(2, $second->getId());
    }

    public function testTwoTeamsBothHoldProposalOne(): void
    {
        $a = $this->propose($this->kbs->a, $this->kbs->a->note('A'), 'Changed.');
        $b = $this->propose($this->kbs->b, $this->kbs->b->note('B'), 'Changed.');

        self::assertSame(1, $a->getId());
        self::assertSame(1, $b->getId());

        $this->kbs->a->enter();
        self::assertSame('A', $this->findProposalNumbered(1)?->getNote()?->getTitle());
        $this->kbs->b->enter();
        self::assertSame('B', $this->findProposalNumbered(1)?->getNote()?->getTitle());
    }

    public function testAProposalNumberIsNotReusedOnceItsDraftIsGone(): void
    {
        $doomed = $this->kbs->a->note('Doomed');
        $this->propose($this->kbs->a, $doomed, 'Changed.');
        self::getContainer()->get(NoteLimbo::class)->retire($doomed, Note::ACTOR_HUMAN, 'Gone.');
        self::assertNull($this->findProposalNumbered(1), 'Precondition: the draft went with its note');

        $next = $this->propose($this->kbs->a, $this->kbs->a->note('Next'), 'Changed.');

        self::assertSame(2, $next->getId(), 'MAX(id)+1 would hand out 1 again');
    }

    public function testAFreshTeamsJournalStartsAtOne(): void
    {
        $first = $this->log($this->kbs->a, 'First.');
        $second = $this->log($this->kbs->a, 'Second.');
        $other = $this->log($this->kbs->b, 'Elsewhere.');

        self::assertSame(1, $first->getId());
        self::assertSame(2, $second->getId());
        self::assertSame(1, $other->getId());
    }

    public function testTheCountersArePerTeam(): void
    {
        $this->log($this->kbs->a, 'One.');
        $this->log($this->kbs->a, 'Two.');
        $this->propose($this->kbs->b, $this->kbs->b->note('B'), 'Changed.');

        self::assertSame(3, $this->log($this->kbs->a, 'Three.')->getId());
        self::assertSame(1, $this->propose($this->kbs->a, $this->kbs->a->note('A'), 'Changed.')->getId());
        // B's note and its held edit are B's first two rows; A's writes are
        // not, however many there were.
        self::assertSame(3, $this->log($this->kbs->b, 'B one.')->getId());
        self::assertSame(2, $this->propose($this->kbs->b, $this->kbs->b->note('B two'), 'Changed.')->getId());
    }
}
