<?php

declare(strict_types=1);

namespace App\Standalone\Command;

use App\Service\SessionRegistry;
use App\Standalone\FirstRun;
use App\Standalone\OwnerException;
use App\Standalone\OwnerPassword;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** memex sends no mail, so a forgotten password is replaced by whoever runs the server. */
#[AsCommand(name: 'app:reset-password', description: 'Set a new password for this memex\'s account and sign out every browser')]
final class ResetPasswordCommand extends Command
{
    public function __construct(
        private readonly FirstRun $firstRun,
        private readonly OwnerPassword $password,
        private readonly SessionRegistry $sessions,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $owner = $this->firstRun->owner();
        if ($owner === null) {
            $io->error('This memex has no account yet. Create it in a browser with app:setup-code, or with app:create-owner.');

            return Command::FAILURE;
        }
        $password = PasswordPrompt::read($input, $io);
        if ($password === null) {
            return Command::FAILURE;
        }

        try {
            $this->password->set($owner, $password);
        } catch (OwnerException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $this->sessions->endAll((int) $owner->getId());
        $io->success('Password set for '.$owner->getEmail().'. Every browser is signed out.');

        return Command::SUCCESS;
    }
}
