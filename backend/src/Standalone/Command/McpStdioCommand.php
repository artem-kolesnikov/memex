<?php

declare(strict_types=1);

namespace App\Standalone\Command;

use App\Directory\Account;
use App\Entity\ApiToken;
use App\Service\BearerTokens;
use App\Service\McpServer;
use App\Storage\VaultScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * MCP over stdio, for an assistant that starts its servers as local commands,
 * Claude Desktop among them: one JSON-RPC message per line in, one answer per
 * line out. It speaks as the connection whose token is in MEMEX_TOKEN, exactly
 * as that token does over HTTP, and each message is a request of its own: the
 * process outlives a change in Settings, so every service is reset between
 * two. Anything else written to stdout would break the stream, so PHP's own
 * messages go to stderr.
 */
#[AsCommand(name: 'app:mcp-stdio', description: 'Serve MCP over stdin and stdout, as the connection whose token is in MEMEX_TOKEN')]
final class McpStdioCommand extends Command
{
    public function __construct(
        private readonly McpServer $server,
        private readonly BearerTokens $tokens,
        private readonly EntityManagerInterface $em,
        private readonly EntityManagerInterface $directoryEntityManager,
        private readonly VaultScope $scope,
        #[Autowire(service: 'services_resetter')]
        private readonly ResetInterface $resetter,
        private readonly string $appBaseUrl,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('display_errors', 'stderr');
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $route = $this->tokens->route(trim((string) getenv('MEMEX_TOKEN')));
        $account = $route === null ? null : $this->directoryEntityManager->find(Account::class, $route['account_id']);
        if ($route === null || $account === null) {
            $errors->writeln('MEMEX_TOKEN holds no token of this memex. Create one in Settings › Assistants › Other.');

            return Command::FAILURE;
        }

        $stream = $input instanceof StreamableInputInterface && $input->getStream() !== null ? $input->getStream() : STDIN;
        $origin = rtrim(trim($this->appBaseUrl), '/') ?: 'http://localhost';

        $vault = $account->vault();
        while (($line = fgets($stream)) !== false) {
            if (trim($line) === '') {
                continue;
            }
            $this->resetter->reset();
            $reply = $this->scope->run($vault, function () use ($route, $line, $origin): array|false|null {
                $token = $this->em->find(ApiToken::class, $route['connection_id']);
                if ($token === null || $token->isRevoked()) {
                    return false;
                }
                $token->touch();
                $this->em->flush();

                return $this->server->reply($line, $token, $origin);
            });
            if ($reply === false) {
                $errors->writeln('This connection was revoked.');

                return Command::FAILURE;
            }
            if ($reply !== null) {
                $output->writeln(json_encode($reply, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            }
        }

        return Command::SUCCESS;
    }
}
