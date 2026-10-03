<?php

declare(strict_types=1);

namespace App\Command;

use App\Directory\Account;
use App\Service\BearerTokens;
use App\Storage\VaultScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:create-token', description: 'Create an API bearer token for an account (printed once)')]
class CreateTokenCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $directoryEntityManager,
        private readonly VaultScope $scope,
        private readonly BearerTokens $tokens,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('account', InputArgument::REQUIRED, 'The account\'s email or handle');
        $this->addArgument('name', InputArgument::REQUIRED, 'Token name (which agent/client)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = (string) $input->getArgument('account');
        $accounts = $this->directoryEntityManager->getRepository(Account::class);
        $account = $accounts->findOneBy(['email' => $key]) ?? $accounts->findOneBy(['handle' => $key]);
        if ($account === null) {
            $output->writeln('<error>Account not found</error>');

            return Command::FAILURE;
        }

        [, $plaintext] = $this->scope->run(
            $account->vault(),
            fn (): array => $this->tokens->issue($account, (string) $input->getArgument('name'), keep: true),
        );

        $output->writeln('Token (Settings › Assistants copies it again later):');
        $output->writeln($plaintext);

        return Command::SUCCESS;
    }
}
