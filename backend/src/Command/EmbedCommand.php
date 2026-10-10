<?php

declare(strict_types=1);

namespace App\Command;

use App\Directory\Account;
use App\Entity\Note;
use App\Service\EmbeddingSpace;
use App\Service\EmbeddingSpend;
use App\Service\NoteEnricher;
use App\Service\NoteNeighbours;
use App\Storage\EveryVault;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Embedding sweep (mm2's embed-timer pattern): safety net behind capture-time
 * embedding — finds notes with no embedding or a stale one (text changed since
 * embedding) and re-embeds. Run by memex-embed.timer every 15 minutes, across
 * every vault whose account is not suspended, at most `count` notes in all.
 * A vault on OpenAI's model with no key to buy with is passed over, said in
 * one line. Exits non-zero on partial failure so systemctl status surfaces it.
 */
#[AsCommand(name: 'app:embed', description: 'Embed notes missing or with stale embeddings')]
class EmbedCommand extends Command
{
    /**
     * A note the sweep owes an embedding: none at all, one older than the
     * note, or a chunk older than the note — which is how the chunk backfill
     * of 2026-09-09 asks to be revisited without touching the note's own
     * timestamp (the search canary probes only notes whose vector is current).
     */
    public const STALE_SQL = 'ne.note_id IS NULL OR ne.embedded_at < n.updated_at
               OR EXISTS (SELECT 1 FROM note_embedding_chunks c WHERE c.note_id = n.id AND c.embedded_at < n.updated_at)';

    /**
     * Distances each vault's neighbour lists may measure per run, about half
     * a minute; a vault that needs more gets it over the next runs.
     */
    private const SETTLE_MEASUREMENTS = 3_000_000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NoteEnricher $enricher,
        private readonly EveryVault $vaults,
        private readonly NoteNeighbours $neighbours,
        private readonly EmbeddingSpace $space,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('count', InputArgument::OPTIONAL, 'Max notes to process', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $budget = max(1, (int) $input->getArgument('count'));
        $processed = 0;
        $errors = 0;
        $attempted = 0;

        $this->vaults->each(function (Account $account) use ($output, &$budget, &$processed, &$errors, &$attempted): void {
            if ($this->space->followLimits()) {
                $output->writeln(sprintf('%s: now embeds with %s; every note again', $account->getEmail(), $this->space->model()->value));
            }
            if ($budget > 0) {
                $this->embed($account, $output, $budget, $processed, $errors, $attempted);
            }
            $stale = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM note_neighbours_stale');
            if ($stale > 0) {
                $left = $this->neighbours->settle(self::SETTLE_MEASUREMENTS);
                $output->writeln(sprintf('%s: settled the neighbours of %d notes, %d left', $account->getEmail(), $stale - $left, $left));
            }
        });

        if ($attempted === 0) {
            $output->writeln('Nothing to embed');

            return Command::SUCCESS;
        }
        $output->writeln("Processed: {$processed}, errors: {$errors}");

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function embed(Account $account, OutputInterface $output, int &$budget, int &$processed, int &$errors, int &$attempted): void
    {
        if (!$this->space->canEmbed()) {
            $output->writeln(sprintf('%s: %s has no key to embed with; skipped', $account->getEmail(), $this->space->model()->value));

            return;
        }
        $ids = $this->em->getConnection()->fetchFirstColumn(
            'SELECT n.id FROM notes n
             LEFT JOIN note_embeddings ne ON ne.note_id = n.id
             WHERE '.self::STALE_SQL.'
             ORDER BY n.id ASC
             LIMIT :count',
            ['count' => $budget],
            ['count' => ParameterType::INTEGER]
        );
        if ($ids === []) {
            return;
        }
        $budget -= count($ids);
        $attempted += count($ids);

        $output->writeln(sprintf('%s: embedding %d notes...', $account->getEmail(), count($ids)));
        $repo = $this->em->getRepository(Note::class);
        foreach ($ids as $id) {
            $note = $repo->find($id);
            if ($note === null) {
                continue;
            }
            try {
                // NOT metered: the backfill sweep is the operator's own catch-up
                // pass, bounded by its batch size rather than by an hourly
                // window. A limiter here would make it stall at the limit and
                // silently under-cover the backlog it exists to clear.
                if ($this->enricher->storeEmbedding($note, EmbeddingSpend::OwnerInitiated)) {
                    ++$processed;
                    $output->writeln("Note {$id}: OK");
                } else {
                    ++$errors;
                    $output->writeln("Note {$id}: FAILED");
                }
            } catch (\Throwable $e) {
                ++$errors;
                $output->writeln("Note {$id}: ERROR ".$e->getMessage());
            }
        }
    }
}
