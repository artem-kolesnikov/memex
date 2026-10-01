<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\AiCredentials;
use App\Service\MlClient;
use App\Tests\Support\KbFixture;

/**
 * The harness testing itself: the kernel boots, the schema builds a vault and
 * the directory, and the two properties the rest of the suite relies on hold.
 */
final class SmokeTest extends DatabaseTestCase
{
    public function testMigrationChainBuildsTheFullSchema(): void
    {
        new KbFixture(self::getContainer());
        $tables = $this->em->getConnection()->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name");

        // Including the ones the ORM does not manage — the full-text and
        // vector tables and their metadata exist only in the schema files.
        foreach (['notes', 'deleted_notes', 'edit_proposals', 'note_embeddings', 'note_embedding_chunks', 'notes_fts', 'note_embedding_vectors', 'note_embedding_chunk_vectors'] as $table) {
            self::assertContains($table, $tables);
        }

        $directory = self::getContainer()->get('doctrine.dbal.directory_connection')
            ->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name");
        foreach (['accounts', 'identities', 'bearer_tokens', 'oauth_clients', 'sessions'] as $table) {
            self::assertContains($table, $directory);
        }
    }

    public function testSqliteVecIsAvailable(): void
    {
        new KbFixture(self::getContainer());
        self::assertNotEmpty(
            $this->em->getConnection()->fetchOne('SELECT vec_version()'),
            'A vault needs sqlite-vec — install it with tools/sqlite-vec.sh and point sqlite3.extension_dir at it.'
        );
    }

    public function testOutboundHttpCannotReachTheRealServices(): void
    {
        // If this ever hits the network the suite has started costing money.
        // Credentials with text generation enabled, because the point of this
        // test is that the request is answered by the stub, not that the switch
        // stops it (which App\Tests\Database\AiSettingsTest asserts).
        $summary = self::getContainer()->get(MlClient::class)
            ->summarize('anything', new AiCredentials(textEnabled: true));

        self::assertSame('A summary written by the ml-processor stub.', $summary);
        self::assertSame(1, $this->ml->callCount('/summarize'));
    }

    public function testFixtureBuildsAKnowledgeBase(): void
    {
        $kb = new KbFixture(self::getContainer());

        self::assertNotNull($kb->account->getId());
        self::assertFalse($kb->agentToken->isCurator());
        self::assertTrue($kb->curatorToken->isCurator());
    }
}
