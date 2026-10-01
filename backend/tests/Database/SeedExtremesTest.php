<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Command\SeedExtremesCommand;
use App\Entity\ApiToken;
use App\Entity\Note;
use App\Service\VaultExporter;
use App\Tests\Support\KbFixture;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:seed-extremes` writes the rows a real knowledge base does not contain.
 *
 * Two properties are worth a test and the rest is not. It must **refuse to run
 * in prod**, because it writes deliberately broken content and the box serves
 * the operator's own vault. And it must actually write **the maxima** — a title
 * at the column's limit, a token name at its limit — because the whole reason it
 * exists is that fixtures somebody chooses by hand are easy, and a fixture at
 * 400 characters instead of 500 would be a fixture chosen by hand again.
 *
 * The history: on 2026-08-28 stacked review rows were verified against an inbox
 * seeded by hand where everything was reasonable, and passed. A 500-character
 * title — legal, accepted by both write paths — put a link at x = 6489 in a
 * 390px viewport and scrolled the document 6114px.
 */
class SeedExtremesTest extends DatabaseTestCase
{
    private CommandTester $command;
    private KbFixture $kb;

    protected function setUp(): void
    {
        parent::setUp();

        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $this->command = new CommandTester($application->find('app:seed-extremes'));

        $this->kb = new KbFixture(self::getContainer());
    }

    private function seed(): void
    {
        $this->leave();
        $this->command->execute([]);
        $this->kb->tenant->enter();
    }

    public function testItWritesTitlesAndTokenNamesAtTheColumnMaximumRatherThanNear(): void
    {
        $this->seed();
        self::assertSame(Command::SUCCESS, $this->command->getStatusCode());

        $longest = 0;
        foreach ($this->fixtures() as $note) {
            $longest = max($longest, mb_strlen($note->getTitle()));
        }
        // 500 is what NoteController and McpController accept. A fixture at 499
        // would pass a "long title" test and prove nothing about the boundary.
        self::assertSame(500, $longest, 'the longest fixture title must sit exactly at the column maximum');

        $names = array_map(
            static fn (ApiToken $t): int => mb_strlen($t->getName()),
            $this->em->getRepository(ApiToken::class)->findAll(),
        );
        self::assertContains(80, $names, 'a token whose display name is at its maximum must exist');
    }

    public function testTheAgentWrittenFixtureLandsPendingSoTheInboxIsNotEmpty(): void
    {
        $this->seed();

        $pending = array_filter($this->fixtures(), static fn (Note $note): bool => $note->getStatus() === Note::STATUS_PENDING);

        // An inbox with no rows measures clean at every width — which is exactly
        // how /inbox passed a per-route sweep on 2026-08-28 while hiding its
        // Approve button off the side of the screen. The fixture exists to stop
        // that route being verified empty ever again.
        self::assertNotEmpty($pending, 'at least one fixture must be awaiting review');
    }

    public function testRunningItTwiceDoesNotWriteTheFixturesTwice(): void
    {
        $this->seed();
        $after_first = \count($this->fixtures());

        // Asserted against a NUMBER, not against itself. The first version
        // compared the two counts and nothing else, so a command that wrote
        // nothing at all on either run and printed "already here" passed it —
        // 0 === 0 (Codex, 2026-08-28). That is the oldest shape in this
        // project's catalogue of tests that cannot fail: the expected value was
        // the type's empty one.
        self::assertSame(6, $after_first, 'the first run must actually write the fixtures');

        $this->seed();
        $after_second = \count($this->fixtures());

        // The sync script runs this every time, and a fixture set that grows on
        // each run is one nobody can measure against twice.
        self::assertSame(6, $after_second, 'the second run must add nothing');
        self::assertStringContainsString('already here', $this->command->getDisplay());
    }

    /** Exported, the fixtures sit in one folder, each file named by its note's title. */
    public function testAnExportNamesEachFixtureByItsTitle(): void
    {
        $this->seed();

        $exporter = self::getContainer()->get(VaultExporter::class);
        $fixtures = $this->fixtures();
        self::assertCount(6, $fixtures);
        foreach ($fixtures as $note) {
            self::assertSame('seed-extremes/'.$exporter->filename($note), $exporter->archivePath($note));
        }
    }

    /** @return list<Note> */
    private function fixtures(): array
    {
        return $this->em->createQuery('SELECT n FROM App\Entity\Note n WHERE n.importPath LIKE :folder')
            ->setParameter('folder', 'seed-extremes/%')
            ->getResult();
    }

    /**
     * The refusal, asked of environment names rather than of a production box.
     *
     * `refuses()` is static and pure precisely so this test can exist. The
     * directory and the vaults are files in the data directory of the machine
     * running the command, so the only real account lives on the box, and the
     * box runs `prod`.
     */
    public function testItRefusesAnythingThatIsNotALocalDevOrTestDatabase(): void
    {
        self::assertNotNull(SeedExtremesCommand::refuses('prod'));
        // Any other label is somebody else's environment, not a local one.
        self::assertNotNull(SeedExtremesCommand::refuses('staging'));
        self::assertNotNull(SeedExtremesCommand::refuses('Dev'));
        // Nothing at all is not a reason to proceed.
        self::assertNotNull(SeedExtremesCommand::refuses(''));

        // And the two it must allow, or the fixtures cannot be written at all.
        self::assertNull(SeedExtremesCommand::refuses('dev'));
        self::assertNull(SeedExtremesCommand::refuses('test'));
    }
}
