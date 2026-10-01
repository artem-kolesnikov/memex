<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Tests\Support\ResolvesNoteNumbers;
use App\Tests\Support\MockMlResponder;
use App\Tests\Support\TestData;
use App\Tests\Support\Vaults;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A booted kernel over real SQLite files, for the paths that cannot be tested
 * without them.
 *
 * Why this exists: applying a proposal, a changeset, a merge or a retirement
 * is where this backend does its irreversible-looking work, and every one of
 * them needs a database, so until now all four were verified only by the
 * operator clicking them in prod. That gap cost real data once —
 * `ReviewVerdicts::approveChangeset()` was declared `: int` while
 * `NoteWriter::applyChangeset()` returns an array, so three of the operator's
 * notes were rewritten and the request then 500'd over work that had already
 * committed. A green type-check says the code compiles, not that it works.
 *
 * Two properties this harness guarantees:
 *
 *  - **It cannot spend money.** config/services_test.yaml binds both HTTP
 *    clients to a MockHttpClient, which opens no socket. OpenAI and the rest are
 *    unreachable from a test run by construction.
 *  - **No test sees another's rows.** Every test gets a fresh data directory
 *    ({@see TestData}): its own directory file and its own vault files.
 *
 * Test code works in one vault at a time and names it ({@see Tenant::enter()},
 * {@see leave()} before a job that walks every vault).
 */
abstract class DatabaseTestCase extends KernelTestCase
{
    use ResolvesNoteNumbers;

    protected EntityManagerInterface $em;
    protected MockMlResponder $ml;

    protected function reviewSnapshot(\App\Entity\EditProposal $proposal): array
    {
        $snapshot = [
            'expected_revision' => $proposal->getRevision(),
            'expected_version' => $proposal->getNote()->getVersion(),
        ];
        if ($proposal->getMergeIntoNote() !== null) {
            $snapshot['expected_merge_version'] = $proposal->getMergeIntoNote()->getVersion();
        }
        return $snapshot;
    }

    protected function setUp(): void
    {
        parent::setUp();
        TestData::fresh();
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->ml = $container->get(MockMlResponder::class);
    }

    protected function leave(): void
    {
        Vaults::leave(self::getContainer());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TestData::discard();
    }
}
