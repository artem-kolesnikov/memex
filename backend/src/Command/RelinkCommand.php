<?php

declare(strict_types=1);

namespace App\Command;

use App\Directory\Account;
use App\Entity\Note;
use App\Service\NoteEnricher;
use App\Storage\EveryVault;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Full link rebuild: re-parses every note's body and re-resolves every target
 * under the current rules, in every vault whose account is not suspended.
 * Unlike the relink-unresolved catch-up (NULLs only), this also UN-resolves
 * links a since-fixed matcher bug resolved wrongly — run it once after any
 * change to WikiLinkParser or the resolution SQL.
 */
#[AsCommand(name: 'app:relink', description: 'Rebuild all wiki-links from note bodies under current resolution rules')]
class RelinkCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NoteEnricher $enricher,
        private readonly EveryVault $vaults,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = 0;
        $this->vaults->each(function (Account $account) use ($output, &$errors): void {
            $output->writeln($account->getEmail().':');
            $errors += $this->relink($output);
        });

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return int how many notes failed */
    private function relink(OutputInterface $output): int
    {
        $conn = $this->em->getConnection();
        $before = $conn->fetchAllKeyValue(
            'SELECT from_note_id || :sep || raw_target, to_note_id FROM note_links',
            ['sep' => "\u{1F}"]
        );

        $ids = $conn->fetchFirstColumn('SELECT id FROM notes ORDER BY id ASC');
        $repo = $this->em->getRepository(Note::class);
        $errors = 0;
        foreach ($ids as $id) {
            $note = $repo->find($id);
            if ($note === null) {
                continue;
            }
            try {
                $this->enricher->syncLinks($note);
            } catch (\Throwable $e) {
                ++$errors;
                $output->writeln("Note {$id}: ERROR ".$e->getMessage());
            }
        }

        $after = $conn->fetchAllKeyValue(
            'SELECT from_note_id || :sep || raw_target, to_note_id FROM note_links',
            ['sep' => "\u{1F}"]
        );

        foreach ($after as $key => $to) {
            $was = $before[$key] ?? null;
            if (!array_key_exists($key, $before)) {
                [$from, $raw] = explode("\u{1F}", (string) $key, 2);
                $output->writeln("ADDED   note {$from} [[{$raw}]] -> ".($to ?? 'unresolved'));
            } elseif ((string) $was !== (string) $to) {
                [$from, $raw] = explode("\u{1F}", (string) $key, 2);
                $output->writeln("CHANGED note {$from} [[{$raw}]]: ".($was ?? 'unresolved').' -> '.($to ?? 'unresolved'));
            }
        }
        foreach (array_diff_key($before, $after) as $key => $was) {
            [$from, $raw] = explode("\u{1F}", (string) $key, 2);
            $output->writeln("REMOVED note {$from} [[{$raw}]] (was ".($was ?? 'unresolved').')');
        }

        $resolved = count(array_filter($after, static fn ($v) => $v !== null));
        $output->writeln(sprintf('Notes: %d, links: %d resolved / %d total, errors: %d', count($ids), $resolved, count($after), $errors));

        return $errors;
    }
}
