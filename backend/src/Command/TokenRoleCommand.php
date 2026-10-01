<?php

declare(strict_types=1);

namespace App\Command;

use App\Directory\Account;
use App\Entity\ApiToken;
use App\Storage\VaultScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Grant or revoke the curator role on an API token. Console counterpart of
 * PATCH /api/tokens/{id}/role, which is where the rule is written down: the
 * role belongs to the knowledge base's own owner, never to an agent.
 *
 * **This command is the one place that rule could be broken by accident, and
 * L-7 is how (fixed 2026-08-22).** It looked tokens up by NAME across the whole
 * box. Token names are chosen by their owners and are unique to nobody —
 * "claude" is what everybody's connector is called — so whoever held the shell
 * could lift a stranger's review gate while reading an output line that named
 * only a token.
 *
 * So the account comes first, by email or handle, and the name is looked up in
 * that account's vault alone; a name matching more than one live token there
 * refuses and lists them, and every line of output names the memex and the
 * person. A shell is enough authority to run this; it is not enough to make
 * "which account did I just change" a guess.
 */
#[AsCommand(name: 'app:token-role', description: 'Set an API token\'s role (agent | curator)')]
class TokenRoleCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntityManagerInterface $directoryEntityManager,
        private readonly VaultScope $scope,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('account', InputArgument::REQUIRED, 'The account\'s email or handle')
            ->addArgument('name', InputArgument::REQUIRED, 'Token name (as shown in settings / health)')
            ->addArgument('role', InputArgument::REQUIRED, 'agent | curator');
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

        return $this->scope->run(
            $account->vault(),
            fn (): int => $this->setRole($account, (string) $input->getArgument('name'), (string) $input->getArgument('role'), $output),
        );
    }

    private function setRole(Account $account, string $name, string $role, OutputInterface $output): int
    {
        $tokens = array_values(array_filter(
            $this->em->getRepository(ApiToken::class)->findBy(['name' => $name]),
            static fn (ApiToken $t) => !$t->isRevoked(),
        ));

        if ($tokens === []) {
            $output->writeln('<error>No active token named "'.$name.'" in '.$this->owner($account).'</error>');

            return Command::FAILURE;
        }

        if (count($tokens) > 1) {
            $output->writeln('<error>Token name is ambiguous — rename one in Settings first:</error>');
            foreach ($tokens as $candidate) {
                $output->writeln('  '.$this->describe($candidate, $account));
            }

            return Command::FAILURE;
        }

        try {
            $tokens[0]->setRole($role);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }
        $this->em->flush();
        $output->writeln('Role set to '.$tokens[0]->getRole().': '.$this->describe($tokens[0], $account));

        return Command::SUCCESS;
    }

    /**
     * A token, and whose it is. Granting curator lifts the review gate on
     * somebody's notes, so an output line that names only the token is an
     * output line you cannot check.
     */
    private function describe(ApiToken $token, Account $account): string
    {
        return sprintf('token %d "%s" (role %s) — %s', $token->getId(), $token->getName(), $token->getRole(), $this->owner($account));
    }

    private function owner(Account $account): string
    {
        return sprintf('memex "%s" (%s), owner %s', $account->getMemexName(), $account->getHandle(), $account->getEmail());
    }
}
