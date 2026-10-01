<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Tests\Support\TwoTeamFixture;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:export-vault --all-vaults` — the invite cliff (M-16), as a test.
 *
 * The defect was not in this command. It refused to guess which vault to export
 * once a second one existed, which is right. The defect was that
 * `deploy/backup/memex-backup.sh` called it with no way to comply, under
 * `set -euo pipefail`, BEFORE its upload step: from the first accepted invite
 * neither the pg_dump nor the vault reached the bucket, and nothing said so.
 *
 * So what is asserted here is the ability to comply, and the two shapes of
 * "empty" that a nightly backup has to tell apart — because getting the second
 * one wrong re-creates the same outage, triggered by a stranger's inactivity
 * instead of their existence.
 *
 * The bash half cannot be tested from here. What can be, and is, is that the
 * command it calls answers correctly for every case that script can meet.
 */
class BackupExportTest extends DatabaseTestCase
{
    private CommandTester $command;
    private TwoTeamFixture $kb;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $this->command = new CommandTester($application->find('app:export-vault'));

        $this->kb = new TwoTeamFixture(self::getContainer());

        $this->dir = sys_get_temp_dir().'/memex-export-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * The refusal that was right all along, still in place. A backup script
     * that could be handed one tenant's archive labelled as the vault is the
     * failure this guards, and `--all-vaults` must not have softened it.
     */
    public function testWithoutAllTeamsTwoTeamsStillRefuseToBeGuessedBetween(): void
    {
        $this->kb->a->note('A one');
        $this->kb->b->note('B one');
        $this->leave();

        self::assertSame(1, $this->command->execute(['path' => $this->dir.'/vault.zip']));
        self::assertStringContainsString('2 accounts', $this->command->getDisplay());
        self::assertFileDoesNotExist($this->dir.'/vault.zip');
    }

    /**
     * The fix: one archive per vault, and no archive holding two. The second
     * half is the tenancy assertion — an archive that swept up both vaults
     * would also be "a backup that survived the invite".
     */
    public function testAllTeamsWritesOneArchivePerTeamAndNoneHoldsAnother(): void
    {
        $this->kb->a->note('Only in A');
        $this->kb->a->note('Also only in A');
        $this->kb->b->note('Only in B');
        $this->leave();

        self::assertSame(0, $this->command->execute(['path' => $this->dir, '--all-vaults' => true]));

        $archives = $this->archiveLines();
        self::assertCount(2, $archives, 'Expected one archive per vault');

        $a = $this->dir.'/vault-'.$this->kb->a->handle().'.zip';
        $b = $this->dir.'/vault-'.$this->kb->b->handle().'.zip';
        self::assertSame(2, $archives[$a] ?? null);
        self::assertSame(1, $archives[$b] ?? null);

        $inA = $this->titlesIn($a);
        $inB = $this->titlesIn($b);
        self::assertSame(['Also only in A', 'Only in A'], $inA);
        self::assertSame(['Only in B'], $inB);
    }

    /**
     * The re-creation of M-16 that a careless fix would have shipped: somebody
     * invited yesterday who has written nothing is a normal state of the world,
     * not a broken backup. Aborting here would take the operator's own vault
     * offsite copy down with it — the exact outage, from the other direction.
     */
    public function testATeamWithNoNotesIsSkippedRatherThanFatal(): void
    {
        $this->kb->a->note('A has notes');
        // B's vault exists and is empty.
        $this->leave();

        self::assertSame(0, $this->command->execute(['path' => $this->dir, '--all-vaults' => true]));

        $archives = $this->archiveLines();
        self::assertCount(1, $archives);
        self::assertArrayHasKey($this->dir.'/vault-'.$this->kb->a->handle().'.zip', $archives);
        self::assertFileDoesNotExist(
            $this->dir.'/vault-'.$this->kb->b->handle().'.zip',
            'An empty vault left an empty archive behind for the caller to upload'
        );
    }

    /**
     * And the alarming empty, which stays fatal: from out here a box with no
     * notes at all and a broken query look identical, and the safe reading of
     * the two is the one that stops the run.
     */
    public function testABoxWithNoNotesAtAllStillFails(): void
    {
        $this->leave();

        self::assertSame(1, $this->command->execute(['path' => $this->dir, '--all-vaults' => true]));
        self::assertStringContainsString('No notes exported', $this->command->getDisplay());
    }

    /** `path` means something different with --all-vaults, and says so. */
    public function testAllTeamsNeedsADirectory(): void
    {
        $this->kb->a->note('A one');
        $this->leave();

        self::assertSame(1, $this->command->execute([
            'path' => $this->dir.'/not-a-directory.zip',
            '--all-vaults' => true,
        ]));
        self::assertStringContainsString('one zip per vault', $this->command->getDisplay());
    }

    /** Two ways of saying which vault, passed together, is a mistake worth naming. */
    public function testTeamAndAllTeamsContradictEachOther(): void
    {
        $this->leave();

        self::assertSame(1, $this->command->execute([
            'path' => $this->dir,
            '--account' => $this->kb->a->email,
            '--all-vaults' => true,
        ]));
        self::assertStringContainsString('contradict', $this->command->getDisplay());
    }

    /**
     * The machine-readable half of the contract: `<count>\t<path>` per archive,
     * which is what the backup script cross-checks each zip against.
     *
     * @return array<string, int> path => count
     */
    private function archiveLines(): array
    {
        $lines = [];
        foreach (explode("\n", $this->command->getDisplay()) as $line) {
            if (preg_match('/^(\d+)\t(.+)$/', trim($line, "\r"), $m) === 1) {
                $lines[$m[2]] = (int) $m[1];
            }
        }

        return $lines;
    }

    /** @return string[] the note titles an archive holds, sorted */
    private function titlesIn(string $path): array
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path) === true, 'Could not open '.$path);

        $titles = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $content = (string) $zip->getFromIndex($i);
            if (preg_match('/^title: (.+)$/m', $content, $m) === 1) {
                $titles[] = json_decode(trim($m[1]));
            }
        }
        $zip->close();
        sort($titles);

        return $titles;
    }
}
