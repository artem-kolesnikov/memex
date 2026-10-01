<?php

declare(strict_types=1);

namespace App\Command;

use App\Directory\Account;
use App\Service\NoteLimbo;
use App\Storage\EveryVault;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The end of limbo: drop the content of notes retired more than
 * NoteLimbo::LIMBO_DAYS ago, in every vault but a suspended account's, whose
 * content is kept whole until it is resumed. The tombstone row — title, path,
 * reason, who and when — is permanent and is NOT touched here; only the body
 * and summary go.
 *
 * One vault failing does not stop the others; the run still exits non-zero,
 * so the unit's failure hook says so.
 *
 * Run daily by memex-purge.timer. Doing nothing is the normal outcome.
 */
#[AsCommand(name: 'app:purge-limbo', description: 'Purge content of notes whose limbo period has expired')]
class PurgeLimboCommand extends Command
{
    public function __construct(
        private readonly NoteLimbo $limbo,
        private readonly EveryVault $vaults,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be purged and change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $count = 0;
        $failed = 0;

        $this->vaults->each(function (Account $account) use ($dryRun, $output, &$count, &$failed): void {
            try {
                $count += $dryRun ? $this->limbo->expiredCount() : $this->limbo->purgeExpired();
            } catch (\Throwable $e) {
                ++$failed;
                $output->writeln(sprintf('<error>Vault %s: %s</error>', $account->getHandle(), $e->getMessage()));
            }
        });

        if ($dryRun) {
            $output->writeln(sprintf('%d note(s) past their %d-day limbo would be purged.', $count, NoteLimbo::LIMBO_DAYS));
        } else {
            $output->writeln($count === 0
                ? 'Nothing past its limbo period.'
                : sprintf('Purged content of %d retired note(s); tombstones kept.', $count));
        }

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
