<?php

declare(strict_types=1);

namespace App\Standalone\Command;

use App\Standalone\FirstRun;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:setup-code', description: 'Print the code that creates this memex\'s account, until it exists')]
final class SetupCodeCommand extends Command
{
    public function __construct(private readonly FirstRun $firstRun)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->firstRun->owner() !== null) {
            $output->writeln('This memex has its account. A forgotten password is replaced with app:reset-password.');

            return Command::SUCCESS;
        }

        $output->writeln('Setup code: '.$this->firstRun->code());
        $until = $this->firstRun->openUntil();
        if ($until === null) {
            $output->writeln('Open memex in a browser and enter it to create your account.');
        } else {
            $minutes = max(1, (int) ceil(($until->getTimestamp() - time()) / 60));
            $output->writeln(sprintf('Open memex in a browser to create your account. For the next %d minute%s it needs no code; after that it asks for this one.', $minutes, $minutes === 1 ? '' : 's'));
        }

        return Command::SUCCESS;
    }
}
