<?php

declare(strict_types=1);

namespace App\Command;

use App\Directory\Account;
use App\Entity\ApiToken;
use App\Entity\Note;
use App\Service\BearerTokens;
use App\Service\NoteWriter;
use App\Storage\VaultScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The rows a real knowledge base does not contain and the schema permits.
 *
 * The operator's own vault, imported from his export, is the right fixture for
 * almost everything and is missing exactly one thing: **nothing in it is at
 * the limits.** Every title he writes is a sentence. Every connection he names
 * is called something like "Claude · research".
 *
 * That gap is not theoretical. On 2026-08-28 stacked review rows were verified
 * against a hand-made inbox where everything was reasonable, and passed; Codex
 * asked what the SCHEMA permits instead, and a 500-character title — a legal
 * note, accepted by `NoteController` and `McpController` alike — put a link at
 * x = 6489 in a 390px viewport and took the document 6114px sideways. A copy of
 * the real vault would have measured just as clean as the hand-made one.
 *
 * So these are written from the COLUMN LENGTHS rather than from imagination,
 * and every one of them is a legal row that a stranger could create on their
 * first day. They are titled so nobody mistakes them for the operator's own.
 *
 * Dev only, and it says so at the top of `execute()` rather than trusting the
 * caller: this writes junk into the first account's vault.
 */
#[AsCommand(name: 'app:seed-extremes', description: 'Add the legal-but-pathological rows a real vault never produces (dev only)')]
class SeedExtremesCommand extends Command
{
    /** The folder every note this command writes is filed under, so a sweep can tell them apart and an export names them by title. */
    private const FOLDER = 'seed-extremes';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntityManagerInterface $directoryEntityManager,
        private readonly VaultScope $scope,
        private readonly BearerTokens $bearerTokens,
        private readonly NoteWriter $noteWriter,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    /**
     * Whether this process may write junk, and the reason if not.
     *
     * The directory and the vaults are files in this machine's data
     * directory, not a server a dev process can be pointed at: the only place
     * a real account lives is the box, and the box runs `prod`.
     *
     * Static and pure so it can be tested against production-shaped values
     * without a production box to point at.
     */
    public static function refuses(string $environment): ?string
    {
        if (!\in_array($environment, ['dev', 'test'], true)) {
            return sprintf('the environment is "%s"; this writes only in dev or test', $environment);
        }

        return null;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $refusal = self::refuses($this->environment);
        if ($refusal !== null) {
            $output->writeln('<error>app:seed-extremes writes deliberately broken content and refuses: '.$refusal.'.</error>');

            return Command::FAILURE;
        }

        $account = $this->directoryEntityManager->getRepository(Account::class)->findOneBy([], ['id' => 'ASC']);
        if ($account === null) {
            $output->writeln('<error>No account in this directory — sign in once first.</error>');

            return Command::FAILURE;
        }

        return $this->scope->run($account->vault(), fn (): int => $this->seed($account, $output));
    }

    private function seed(Account $account, OutputInterface $output): int
    {
        // Already there: the point of the fixtures is that they are stable
        // enough to measure against twice.
        $existing = $this->em->getConnection()->fetchOne('SELECT 1 FROM notes WHERE import_path LIKE ?', [self::FOLDER.'/%']);
        if ($existing !== false) {
            $output->writeln('The extremes are already here; nothing to do.');

            return Command::SUCCESS;
        }

        // An agent-role token, so what it writes lands PENDING and the review
        // inbox has something in it. An inbox with no rows measures clean at
        // every width, which is how /inbox passed a sweep on 2026-08-28 while
        // hiding its Approve button off the side of the screen.
        //
        // 80 characters, the column's maximum, with no space in it. The
        // "Proposed by" cell prints this unwrapped.
        [$agent] = $this->bearerTokens->issue($account, str_repeat('W', 80));

        // One transaction around all six notes. Without it, an interrupt after
        // the first flush left those rows committed — and the folder check
        // above then reported "already here" for ever, so the missing five were
        // never written (Codex, 2026-08-28). A fixture set that is silently
        // five-sixths present is worse than one that is absent, because
        // absence is visible.
        $written = $this->em->getConnection()->transactional(fn (): int => $this->writeFixtures($agent));

        $output->writeln(sprintf('%d fixtures written, filed under `%s/`.', $written, self::FOLDER));

        return Command::SUCCESS;
    }

    private function writeFixtures(ApiToken $agent): int
    {
        $written = 0;
        foreach ($this->fixtures() as [$token, $title, $body, $tags]) {
            $this->noteWriter->create(
                $token === 'agent' ? $agent : null,
                $title,
                $body,
                $token === 'agent' ? Note::SOURCE_AGENT : Note::SOURCE_MANUAL,
                null,
                $tags,
                // NO EMBEDDING AT ALL, and the reason is worth the line.
                //
                // The first version asked for `EmbeddingSpend::OwnerInitiated`,
                // and `EmbeddingSpendTest` failed: it counts every caller of the
                // spend-exempt case and there were three, each one reasoned
                // about in the enum's own docblock. A tripwire doing exactly its
                // job — and the honest answer was not to make the list four
                // long but to notice these fixtures do not want a vector.
                //
                // They exist for LAYOUT. A 1536-dimension embedding of 500 W's
                // means nothing, and asking for one raises a spend question that
                // should not exist. The visible cost, stated so nobody reports
                // it as a bug: these six notes do not come back from
                // meaning-based search locally. `app:embed` will pick them up if
                // anyone ever wants them to.
                enrich: null,
                importPath: self::FOLDER.'/'.$title,
            );
            ++$written;
        }

        return $written;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: list<string>}>
     */
    private function fixtures(): array
    {
        $url = 'https://example.com/a/very/long/path/that/never/breaks/anywhere/at/all/because/it/has/no/hyphens/or/slashes?query='
            .str_repeat('a', 42).'&more='.str_repeat('b', 24);

        return [
            // The one that found the defect. 500 characters is the maximum both
            // write paths accept, and nothing requires a space among them.
            ['agent', str_repeat('W', 500),
                "A pending note whose title is 500 unbroken characters — the maximum the schema permits.\n\n"
                ."It is pending because an agent-role token wrote it, which also gives the review inbox a row.",
                ['testing']],

            // A pasted page routinely contains one of these, and it is the
            // second thing that dragged the article view sideways.
            ['human', 'A bare URL with nowhere to break',
                "Source: $url\n\nRendered markdown puts that in an `<a>`, which is a CONTROL as far as a layout guard is concerned.",
                ['testing', 'reference']],

            // Code must NOT be broken to fit — it scrolls instead. The
            // counter-case, so a fix for the two above cannot be bought by
            // making everything breakable.
            ['human', 'A code block that must scroll rather than wrap',
                "```bash\nssh -i ~/.secrets/key.pem ubuntu@example.test 'sudo -u postgres pg_dump --data-only --no-owner memex | gzip > /tmp/backup.sql.gz'\n```\n\n"
                ."A wrapped command is not the command that was written.",
                ['testing', 'infra']],

            // Every column at once, for the row that has to hold all of them.
            ['human', 'A note carrying more tags than a row can show',
                'This exists so the tag row has to decide what to do when it cannot show them all.',
                ['testing', 'reference', 'infra', 'projects', 'memex', 'automation', 'ai', 'search', 'export', 'mobile', 'canon', 'status']],

            // The empty end of the range, which is just as unrepresented in a
            // vault somebody has curated: no tags, no summary, no body to speak
            // of. What a stranger's first note looks like.
            ['human', 'Short', 'Tiny.', []],

            // A table and a long line of prose, which is what a wide-content
            // rule has to survive without a scroller of its own.
            ['human', 'A table wider than a phone',
                "| column one | column two | column three | column four |\n"
                ."|---|---|---|---|\n"
                ."| a value | another value | a third value here | and a fourth |\n"
                ."| x | y | z | w |\n",
                ['testing']],
        ];
    }
}
