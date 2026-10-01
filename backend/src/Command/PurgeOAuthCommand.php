<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Two OAuth tables that only ever grew.
 *
 * `oauth_codes` holds authorization codes that live for minutes and are
 * deleted when redeemed — but a code that is never redeemed (the user closes
 * the consent tab, the client crashes between hops) simply stayed, past its
 * expiry, forever. Nothing read it again; nothing removed it either.
 *
 * `oauth_clients` is worse in kind because registration is UNAUTHENTICATED
 * (RFC 7591, open by design so claude.ai can register itself). Every call adds
 * a row. That is now rate-limited, which bounds the rate; this bounds the
 * total.
 *
 * ## What is NOT swept, and why
 *
 * A client that has ever been used is kept regardless of age. "Used" means a
 * token was issued to it — `last_used_at`, stamped at the token exchange — and
 * deleting such a client would break a working connection to save a row of a
 * few hundred bytes. The trade is not close.
 *
 * An unused client is given a fortnight rather than a day. Registering and
 * then completing consent can honestly span a while: somebody sets up a client
 * on a laptop, gets interrupted, and finishes the connection at the weekend.
 * Fourteen days is comfortably past that and still turns unbounded growth into
 * a bounded window.
 *
 * Run daily by memex-purge.timer. Doing nothing is the normal outcome.
 */
#[AsCommand(name: 'app:purge-oauth', description: 'Delete expired authorization codes and unused client registrations')]
class PurgeOAuthCommand extends Command
{
    /**
     * How long an unredeemed registration is kept. Not the code lifetime —
     * codes are swept the moment they expire, since an expired code cannot be
     * redeemed by anyone and is therefore pure residue.
     */
    public const UNUSED_CLIENT_DAYS = 14;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.directory_connection')]
        private readonly Connection $conn,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted and change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');

        $now = new \DateTimeImmutable();
        $codesSql = 'FROM oauth_codes WHERE expires_at < :now';
        $codesParams = ['now' => $now->format('Y-m-d H:i:s')];
        // A client that has ever completed a token exchange is kept, however
        // old — `last_used_at` is stamped there and only there. NULL means the
        // registration was never redeemed.
        $clientsSql = 'FROM oauth_clients c WHERE c.last_used_at IS NULL AND c.created_at < :cutoff';
        $clientsParams = ['cutoff' => $now->modify('-'.self::UNUSED_CLIENT_DAYS.' days')->format('Y-m-d H:i:s')];

        if ($dryRun) {
            $codes = (int) $this->conn->fetchOne('SELECT COUNT(*) '.$codesSql, $codesParams);
            $clients = (int) $this->conn->fetchOne('SELECT COUNT(*) '.$clientsSql, $clientsParams);
            $output->writeln(sprintf(
                '%d expired authorization code(s) and %d unused client registration(s) older than %d days would be deleted.',
                $codes,
                $clients,
                self::UNUSED_CLIENT_DAYS
            ));

            return Command::SUCCESS;
        }

        $codes = (int) $this->conn->executeStatement('DELETE '.$codesSql, $codesParams);
        $clients = (int) $this->conn->executeStatement(
            'DELETE FROM oauth_clients WHERE id IN (SELECT c.id '.$clientsSql.')',
            $clientsParams
        );

        $output->writeln($codes === 0 && $clients === 0
            ? 'Nothing to sweep.'
            : sprintf('Deleted %d expired code(s) and %d unused registration(s).', $codes, $clients));

        return Command::SUCCESS;
    }
}
