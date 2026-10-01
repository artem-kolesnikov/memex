<?php

declare(strict_types=1);

namespace App\Command;

use App\Directory\Account;
use App\Service\VaultExporter;
use App\Storage\EveryVault;
use App\Storage\VaultScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Write a whole vault to a zip of markdown files, from the box itself.
 *
 * The nightly backup's route to the same archive `GET /api/export/all` serves.
 * It runs **on the machine the vaults are on**, so making an authenticated
 * HTTPS round trip through the CDN to read a local file would buy nothing and
 * cost a live bearer token sitting in `/etc/memex-backup.env` forever, to be
 * rotated, leaked or forgotten. A remote client uses the endpoint because it
 * has no other way in; this exists because the server does.
 *
 * Both go through `VaultExporter`, so the file the backup writes is the file
 * the product exports — by construction rather than by intention.
 *
 * Usage (see deploy/backup/memex-backup.sh):
 *
 *     sudo -u www-data php bin/console app:export-vault /var/backups/memex/vault.zip --account=someone@example.com
 *     sudo -u www-data php bin/console app:export-vault /var/backups/memex/stage --all-vaults
 *
 * **`--all-vaults` is why the backup survives an invite (M-16).** Without it,
 * this command refuses the moment a second account exists — deliberately,
 * because a backup that silently exported whichever vault came first would be
 * one person's notes standing in for everyone's. The backup script calls this
 * under `set -euo pipefail`, so it needs a way to comply: one archive per
 * vault, named by its handle, no archive holding two.
 */
#[AsCommand(
    name: 'app:export-vault',
    description: 'Write every note in a vault to a zip of markdown files',
)]
class ExportVaultCommand extends Command
{
    public function __construct(
        private readonly VaultExporter $exporter,
        private readonly VaultScope $scope,
        private readonly EveryVault $everyVault,
        private readonly EntityManagerInterface $directoryEntityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'Where to write the .zip — a DIRECTORY with --all-vaults')
            ->addOption(
                'account',
                null,
                InputOption::VALUE_REQUIRED,
                'Email or handle of the account whose vault to export. Optional while there is exactly one account.'
            )
            ->addOption(
                'all-vaults',
                null,
                InputOption::VALUE_NONE,
                'Export every vault, one zip each, into the directory given as path'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('path');

        if ((bool) $input->getOption('all-vaults')) {
            if ($input->getOption('account') !== null) {
                $io->error('--account and --all-vaults contradict each other — pass one.');

                return Command::FAILURE;
            }

            return $this->exportEveryVault($io, $path);
        }

        $wanted = $input->getOption('account');
        if ($wanted === null) {
            $accounts = $this->directoryEntityManager->getRepository(Account::class)->findAll();
            if (count($accounts) !== 1) {
                $io->error(sprintf(
                    'There are %d accounts — pass --account=EMAIL|HANDLE to say whose vault to export, or --all-vaults.',
                    count($accounts)
                ));

                return Command::FAILURE;
            }
            $account = $accounts[0];
        } else {
            $account = $this->account((string) $wanted);
            if ($account === null) {
                $io->error('No account has the email or handle '.$wanted.'.');

                return Command::FAILURE;
            }
        }

        $count = $this->scope->run($account->vault(), fn (): int => $this->exporter->writeArchive($path));
        if ($count === 0) {
            // Non-zero, so `set -e` in the backup script stops rather than
            // uploading an empty archive over a good one. An empty vault is
            // indistinguishable from a broken query from out here, and the
            // safe reading of the two is the alarming one.
            $io->error('No notes exported — refusing to call an empty archive a backup.');

            return Command::FAILURE;
        }

        // Machine-readable on the last line, because the backup script cross-
        // checks this against what the archive itself says it contains.
        $io->writeln((string) $count);

        return Command::SUCCESS;
    }

    private function account(string $emailOrHandle): ?Account
    {
        $accounts = $this->directoryEntityManager->getRepository(Account::class);

        return $accounts->findOneBy(['email' => $emailOrHandle]) ?? $accounts->findOneBy(['handle' => $emailOrHandle]);
    }

    /**
     * One archive per vault, into `$dir`.
     *
     * Two decisions here, and both are about what "empty" means.
     *
     * **A vault with no notes is skipped, not fatal.** Somebody invited
     * yesterday who has not written anything yet is a normal state of the
     * world, and aborting the nightly backup over it would re-create M-16 in a
     * new shape — this time triggered by a stranger's inactivity rather than
     * their existence.
     *
     * **A box with no notes at all is still fatal**, for the reason the
     * single-vault path gives: from out here an empty result and a broken query
     * look identical, and the safe reading is the alarming one.
     *
     * Output is one `<count>\t<path>` line per archive written, so the caller
     * can cross-check each zip against what the exporter says it holds.
     */
    private function exportEveryVault(SymfonyStyle $io, string $dir): int
    {
        if (!is_dir($dir)) {
            $io->error('--all-vaults writes one zip per vault, so `path` must be an existing directory: '.$dir);

            return Command::FAILURE;
        }

        $total = 0;
        $written = [];
        $empty = [];
        $this->everyVault->each(function (Account $account) use ($dir, &$total, &$written, &$empty): void {
            $file = rtrim($dir, '/').'/vault-'.$account->getHandle().'.zip';
            $count = $this->exporter->writeArchive($file);
            if ($count === 0) {
                // Nothing to upload, and nothing to worry about. Remove the
                // empty zip so the caller's "one file per archive line" holds.
                @unlink($file);
                $empty[] = $account->getHandle();

                return;
            }
            $written[] = $count."\t".$file;
            $total += $count;
        }, includeSuspended: true);

        if ($total === 0) {
            $io->error('No notes exported from any vault — refusing to call that a backup.');

            return Command::FAILURE;
        }

        foreach ($empty as $handle) {
            // stderr, so it cannot be mistaken for an archive line by a caller
            // reading stdout.
            $io->getErrorStyle()->writeln('Vault '.$handle.' has no notes — no archive written.');
        }
        foreach ($written as $line) {
            $io->writeln($line);
        }

        return Command::SUCCESS;
    }
}
