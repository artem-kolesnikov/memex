<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\EditProposal;
use App\Entity\Note;

/**
 * A note's public number is its id in the vault. These read the vault test
 * code has entered.
 */
trait ResolvesNoteNumbers
{
    protected function findNumbered(int $number): ?Note
    {
        return $this->em->find(Note::class, $number);
    }

    protected function noteNumbered(int $number): Note
    {
        $note = $this->findNumbered($number);
        self::assertInstanceOf(Note::class, $note, "No note numbered $number");

        return $note;
    }

    protected function findProposalNumbered(int $number): ?EditProposal
    {
        return $this->em->find(EditProposal::class, $number);
    }
}
