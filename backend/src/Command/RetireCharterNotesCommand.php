<?php

declare(strict_types=1);

namespace App\Command;

use App\Directory\Account;
use App\Entity\Note;
use App\Service\CurationCharter;
use App\Service\NoteLimbo;
use App\Service\SkillLibrary;
use App\Storage\EveryVault;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Retire the charter notes the shipped curation skill replaces.
 *
 * Retirement is `NoteLimbo::retire()` and nothing else: it moves embeddings,
 * links, revisions, proposals and flags in one transaction.
 */
#[AsCommand(name: 'app:retire-charter-notes', description: 'Retire knowledge-base copies of the curation charter, now served by the desk')]
class RetireCharterNotesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NoteLimbo $limbo,
        private readonly EveryVault $everyVault,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be retired and change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $retired = 0;
        $this->everyVault->each(function (Account $account) use ($dryRun, $output, &$retired): void {
            $retired += $this->retireIn($account, $dryRun, $output);
        });

        $output->writeln($retired === 0 ? 'No charter notes found.' : sprintf('%d charter note(s) %s.', $retired, $dryRun ? 'would be retired' : 'retired'));

        return Command::SUCCESS;
    }

    private function retireIn(Account $account, bool $dryRun, OutputInterface $output): int
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT n.id, n.title, n.body_md
             FROM notes n
             JOIN note_tag nt ON nt.note_id = n.id
             JOIN tags t ON t.id = nt.tag_id
             WHERE t.name = 'skill' AND n.status = :status
             ORDER BY n.id",
            ['status' => Note::STATUS_VERIFIED],
        );

        $retired = 0;
        foreach ($rows as $row) {
            if (SkillLibrary::slugify((string) $row['title']) !== CurationCharter::SLUG) {
                continue;
            }
            // The title is not enough. `SkillLibrary` is non-destructive about a
            // collision — it suffixes the note to `memex-curation-2` and keeps
            // serving it — so a command that retired on the slug alone would
            // destroy a note the runtime was happy to keep: anyone whose own
            // `skill` note happens to be called "Memex Curation" loses it.
            // Every copy ever taken from the catalogue opens with this line.
            if (!str_contains((string) $row['body_md'], CurationCharter::CHARTER_MARKER)) {
                $output->writeln(sprintf(
                    'leaving note %d "%s" (%s) — titled like the charter, but its body is not one',
                    (int) $row['id'],
                    (string) $row['title'],
                    $account->getHandle(),
                ));
                continue;
            }
            $note = $this->em->getRepository(Note::class)->find((int) $row['id']);
            if ($note === null) {
                continue;
            }
            $output->writeln(sprintf(
                '%s note %d "%s" (%s)',
                $dryRun ? 'would retire' : 'retiring',
                (int) $row['id'],
                (string) $row['title'],
                $account->getHandle(),
            ));
            if (!$dryRun) {
                $this->limbo->retire(
                    $note,
                    'memex',
                    'The curation charter ships with memex now, and stays current with it. This copy is your knowledge base\'s own and is restorable for 30 days.',
                );
            }
            ++$retired;
        }

        return $retired;
    }
}
