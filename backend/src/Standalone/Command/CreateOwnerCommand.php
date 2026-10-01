<?php

declare(strict_types=1);

namespace App\Standalone\Command;

use App\Standalone\FirstRun;
use App\Standalone\OwnerException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:create-owner', description: 'Create this memex\'s account without the browser\'s setup code')]
final class CreateOwnerCommand extends Command
{
    public function __construct(private readonly FirstRun $firstRun)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'The address you will sign in with');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->firstRun->owner() !== null) {
            $io->error('This memex already has its account.');

            return Command::FAILURE;
        }
        $password = PasswordPrompt::read($input, $io);
        if ($password === null) {
            return Command::FAILURE;
        }

        try {
            $account = $this->firstRun->createOwner((string) $input->getArgument('email'), $password);
        } catch (OwnerException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success('Created. Sign in as '.$account->getEmail().'.');

        return Command::SUCCESS;
    }
}
