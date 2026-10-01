<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Storage\BoundVault;
use App\Storage\VaultFiles;
use App\Tests\Support\Vaults;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Doctrine's schema tools must never reach the tables only raw SQL manages.
 *
 * The schema is the SQL under `schema/`, applied when a file opens. Some of
 * its tables have no entity — full text, vectors, limbo, skill settings and
 * grants in a vault; sessions, OAuth, bearer hashes, the storage and
 * registration policies and the watch tables in the directory — and `schema_filter` in
 * doctrine.yaml is what keeps them out
 * of every schema-tool diff. A table that falls out of the filter is one
 * `doctrine:schema:update` proposes to drop.
 *
 * This drives the console command rather than SchemaTool, because the command
 * is what somebody would type. Whether the entities and the SQL agree is
 * `Tests\Storage\SchemaMappingTest`'s question, not this one.
 */
final class SchemaToolSafetyTest extends DatabaseTestCase
{
    private const RAW = [
        'vault' => ['notes_fts', 'note_embeddings', 'note_embedding_vectors', 'note_embedding_chunks', 'note_embedding_chunk_vectors', 'deleted_notes', 'skill_settings', 'skill_grants'],
        'directory' => ['sessions', 'user_sessions', 'oauth_clients', 'oauth_codes', 'bearer_tokens', 'storage_policy'],
    ];

    /** @return string[] */
    private function updateSchemaStatements(string $em): array
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        $status = $application->run(
            new ArrayInput(['command' => 'doctrine:schema:update', '--dump-sql' => true, '--em' => $em]),
            $output
        );
        $text = $output->fetch();
        self::assertSame(0, $status, 'schema:update --dump-sql did not run: '.$text);

        // The command may separate statements with a LITERAL backslash-n, not
        // a newline, so splitting on "\n" alone would return one giant line.
        $lines = preg_split('/(\\\\n|\n)/', $text) ?: [];

        return array_values(array_filter(
            array_map('trim', $lines),
            static fn (string $line): bool => str_ends_with($line, ';')
        ));
    }

    public function testTheRawSqlManagedTablesAreInvisibleToSchemaTools(): void
    {
        // The subtler failure than a DROP: a filter change that makes Doctrine
        // start MANAGING one of these. No destruction today, and every diff
        // from then on owns a table only raw SQL should write.
        $container = self::getContainer();
        Vaults::enter($container, new BoundVault($container->get(VaultFiles::class)->create(), 'schema'));
        $connections = [
            'vault' => $this->em->getConnection(),
            'directory' => $container->get('doctrine.dbal.directory_connection'),
        ];

        foreach (self::RAW as $em => $tables) {
            $present = $connections[$em]->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table'");
            $sql = implode("\n", $this->updateSchemaStatements($em));

            foreach ($tables as $raw) {
                self::assertContains($raw, $present, "Precondition: a fresh $em holds $raw, so its absence below is the filter's doing");
                self::assertDoesNotMatchRegularExpression(
                    '/\b'.$raw.'\w*\b/',
                    $sql,
                    "$raw is raw-SQL-managed and must not appear in a schema-tool diff of the $em"
                );
            }
        }
    }
}
