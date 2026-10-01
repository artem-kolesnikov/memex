<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;
use App\Service\NoteLimbo;
use App\Tests\Support\TwoTeamFixture;

/**
 * Note numbers are per vault and never reused. A note's id in its vault is its
 * number: the key the referencing tables point at and the identity every URL
 * and every payload carries.
 */
final class NoteNumberingTest extends DatabaseTestCase
{
    private NoteLimbo $limbo;
    private TwoTeamFixture $kbs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limbo = self::getContainer()->get(NoteLimbo::class);
        $this->kbs = new TwoTeamFixture(self::getContainer());
    }

    public function testAFreshTeamStartsAtOne(): void
    {
        self::assertSame(1, $this->kbs->a->note('First')->getId());
        self::assertSame(2, $this->kbs->a->note('Second')->getId());
        self::assertSame(3, $this->kbs->a->note('Third')->getId());
    }

    public function testTwoTeamsBothHoldANoteOne(): void
    {
        $a = $this->kbs->a->note('A first');
        $b = $this->kbs->b->note('B first');

        self::assertSame(1, $a->getId());
        self::assertSame(1, $b->getId());

        $this->kbs->a->enter();
        self::assertSame('A first', $this->noteNumbered(1)->getTitle());
        $this->kbs->b->enter();
        self::assertSame('B first', $this->noteNumbered(1)->getTitle());
    }

    public function testNumbersAreNotReusedAfterADelete(): void
    {
        $this->kbs->a->note('One');
        $two = $this->kbs->a->note('Two');

        $this->limbo->retire($two, Note::ACTOR_HUMAN, 'Gone.');

        self::assertSame(3, $this->kbs->a->note('Three')->getId(), 'MAX(id)+1 would hand out 2 again');
    }

    public function testRestoreReturnsANoteAtItsOriginalNumber(): void
    {
        $this->kbs->a->note('One');
        $two = $this->kbs->a->note('Two');
        $id = (int) $two->getId();

        $this->limbo->retire($two, Note::ACTOR_HUMAN, 'Gone.');
        $this->kbs->a->note('Three');
        $this->limbo->restore($id);

        self::assertSame('Two', $this->noteNumbered(2)->getTitle());
        self::assertSame('Three', $this->noteNumbered(3)->getTitle());
    }

    public function testRetirementCarriesTheNumberIntoLimbo(): void
    {
        $this->kbs->a->note('One');
        $two = $this->kbs->a->note('Two');

        $this->limbo->retire($two, Note::ACTOR_HUMAN, 'Gone.');

        self::assertSame('Two', $this->em->getConnection()->fetchOne(
            'SELECT title FROM deleted_notes WHERE id = :id',
            ['id' => 2]
        ));
    }

    public function testEveryTeamGetsADistinctPermanentHandle(): void
    {
        $a = $this->kbs->a->account()->getHandle();
        $b = $this->kbs->b->account()->getHandle();

        self::assertMatchesRegularExpression('/^[a-z2-9]{12}$/', $a);
        self::assertNotSame($a, $b);
    }

    public function testTheCounterIsPerTeamAndNotGlobal(): void
    {
        $this->kbs->a->note('A one');
        $this->kbs->a->note('A two');
        $this->kbs->b->note('B one');

        self::assertSame(3, $this->kbs->a->note('A three')->getId());
        self::assertSame(2, $this->kbs->b->note('B two')->getId());
    }
}
