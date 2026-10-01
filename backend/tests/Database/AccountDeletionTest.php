<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Directory\Account;
use App\Entity\AiCredential;
use App\Entity\Note;
use App\Entity\ProviderSpend;
use App\Entity\SearchPreset;
use App\Entity\Tag;
use App\Service\CurationFlags;
use App\Service\NoteLimbo;
use App\Service\NoteWriter;
use App\Service\TagAdmin;
use App\Storage\DataDir;
use App\Tests\Support\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Leaving memex.
 *
 * Until 2026-08-21 there was no way out at all: a person could sign in, fill a
 * knowledge base, and then need the operator to run SQL on the box. What
 * replaced it was a 30-day closing period, and on 2026-08-26 the operator
 * struck that out — deleting is now one act with nothing left afterwards, and
 * the way back is the export sitting directly above the button.
 *
 * The properties worth asserting are the ones a wrong implementation would get
 * wrong quietly:
 *
 *  - a connected assistant must not be able to delete the account it reads;
 *  - the deletion must take the whole account and NOT ONE ROW of anybody
 *    else\'s, which is the tenancy question asked at its most destructive;
 *  - nothing of the person may survive on the invite that admitted them;
 *  - the refusal must hold, because the deletion could not be undone.
 */
class AccountDeletionTest extends ApiTestCase
{
    private function account(int $id): ?Account
    {
        $this->directoryEm()->clear();

        return $this->directoryEm()->getRepository(Account::class)->find($id);
    }

    private function directoryEm(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine.orm.directory_entity_manager');
    }

    private function directory(): Connection
    {
        return static::getContainer()->get('doctrine.dbal.directory_connection');
    }

    private static function stamp(string $offset = 'now'): string
    {
        return (new \DateTimeImmutable($offset, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function delete(): void
    {
        $this->sessionRequest('DELETE', '/api/me', ['confirm_email' => $this->kb->a->email]);
        self::assertSame(200, $this->httpStatus(), 'Fixture delete failed: '.$this->body());
    }

    public function testAConnectedAssistantCannotDeleteTheAccountItReads(): void
    {
        // The whole reason this route is session-only. Every assistant the
        // person connects holds a token that authenticates AS them, so without
        // the guard "may file notes" would include "may destroy the box".
        $this->request('DELETE', '/api/me', $this->kb->a->agentBearer, ['confirm_email' => $this->kb->a->email]);

        self::assertSame(403, $this->httpStatus());
        self::assertNotNull($this->account($this->kb->a->accountId));
    }

    public function testEvenACuratorTokenCannotDeleteTheAccount(): void
    {
        // Curator is the role that writes without review. It is still not a
        // person, and this is the line it does not cross.
        $this->request('DELETE', '/api/me', $this->kb->a->curatorBearer, ['confirm_email' => $this->kb->a->email]);

        self::assertSame(403, $this->httpStatus());
        self::assertNotNull($this->account($this->kb->a->accountId));
    }

    public function testDeletingNeedsTheAccountsOwnAddressTyped(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('DELETE', '/api/me', ['confirm_email' => 'someone-else@example.test']);

        self::assertSame(400, $this->httpStatus());
        self::assertNotNull($this->account($this->kb->a->accountId));
    }

    /**
     * The session does not outlive the account.
     *
     * What this asserts is the observable half: the very next request is a 401
     * rather than a 500 resolving an account row that is gone. The `sessions` row
     * holding the serialized session is deleted by the same handler and cannot
     * be asserted here, because the test env stores sessions in mock files.
     */
    public function testTheSessionEndsWithTheAccount(): void
    {
        $this->loginAs($this->kb->a);
        $this->delete();

        $this->sessionRequest('GET', '/api/me');
        self::assertSame(401, $this->httpStatus());
    }

    /**
     * The session DATA goes too, not just the registry row that pointed at it.
     *
     * `user_sessions` cascades from `accounts`, so the rows describing a person's
     * browsers disappear on their own. `sessions` is Symfony's own table, keyed
     * by PHP session id with no user column: nothing deletes the serialized
     * session of a destroyed account until garbage collection reaches it, and
     * invalidating the request's own session reaches exactly one browser. Found
     * by Codex reviewing this change.
     */
    public function testTheSerializedSessionsOfOtherBrowsersAreDestroyed(): void
    {
        $db = $this->directory();
        $sid = 'other-browser-'.bin2hex(random_bytes(8));
        $db->executeStatement(
            'INSERT INTO sessions (sess_id, sess_data, sess_lifetime, sess_time) VALUES (:sid, :data, :lifetime, :t)',
            ['sid' => $sid, 'data' => 'serialized-session-of-a-real-person', 'lifetime' => time() + 3600, 't' => time()]
        );
        $this->otherBrowser($this->kb->a, $sid);

        $this->loginAs($this->kb->a);
        $this->delete();

        self::assertFalse(
            (bool) $db->fetchOne('SELECT COUNT(*) FROM sessions WHERE sess_id = :sid', ['sid' => $sid]),
            'A destroyed account left its serialized session on the box'
        );
    }

    public function testEveryConnectedAssistantStopsWorking(): void
    {
        $this->loginAs($this->kb->a);
        $bearer = $this->kb->a->agentBearer;
        $this->delete();

        // Not a refusal on a flag — the token row is gone with everything else.
        $this->request('GET', '/api/notes', $bearer);
        self::assertSame(401, $this->httpStatus());
    }

    /**
     * Give a knowledge base rows across its vault, through the real write
     * paths, and a row in every directory table that points at its account.
     *
     * Without this the assertions below are decoration: the fixture writes
     * notes and nothing else, so most counts are zero before the deletion and
     * zero after it whatever the deletion does.
     */
    private function fillKnowledgeBase(Tenant $t): void
    {
        $container = static::getContainer();
        $writer = $container->get(NoteWriter::class);
        $limbo = $container->get(NoteLimbo::class);
        $flags = $container->get(CurationFlags::class);
        $name = $t->account()->getName();

        $note = $t->note('A note of '.$t->label.'s', 'Body.', ['keep-me']);
        // An edit leaves the note's previous state behind.
        $writer->update($note, null, 'Body, revised.', null, \App\Service\EmbeddingSpend::Metered, actor: Note::ACTOR_HUMAN);
        // Held work: a proposal.
        $writer->propose($note, $t->agentToken(), 'Retitled', null, null, 'Please.');
        // A curator's delete proposal writes the curator log as well.
        $writer->proposeDelete($note, $t->curatorToken(), 'Not this one.');
        $flags->raise($note, $name, 'Look at this again.');
        // The desk's own rows: a brief, and a record of it being served.
        $serves = $container->get(\App\Service\SkillServes::class);
        $serves->record(['slug' => \App\Service\CurationCharter::SLUG], $t->curatorToken(), \App\Entity\SkillServe::PATH_TOOL);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO skill_settings (note_id, slug) VALUES (:n, :s)',
            ['n' => $note->getId(), 's' => 'house-style'],
        );
        $this->em->getConnection()->executeStatement(
            'INSERT INTO skill_grants (note_id, token_id) VALUES (:n, :k)',
            ['n' => $note->getId(), 'k' => $t->agentTokenId],
        );
        $limbo->retire($t->note('Doomed '.$t->label), Note::ACTOR_HUMAN, 'Gone.');

        $this->em->persist(new AiCredential('openai', 'OpenAI', 'not-a-real-key', '...test'));
        $preset = new SearchPreset('Pending');
        $preset->setCriteria('', [], 'pending', null);
        $this->em->persist($preset);
        // A closed account's knowledge base is destroyed, and keeping a record
        // of what it cost is keeping a record of its activity. Both payers.
        $this->em->persist((new ProviderSpend(ProviderSpend::SURFACE_TEXT, 'summarize', 'openai', ProviderSpend::PAYER_TEAM))
            ->withModel('gpt-4o-mini')->withTokens(120, 40));
        $this->em->persist((new ProviderSpend(ProviderSpend::SURFACE_EMBEDDING, 'embed_content', 'openai', ProviderSpend::PAYER_OPERATOR))
            ->withModel('text-embedding-3-large')->withTokens(9, null));
        $this->em->flush();

        // A retired tag, made the way one is really made. Last, because
        // TagAdmin clears the identity map when it is done and anything
        // persisted after this line would be lost.
        $tagged = $t->note('Tagged '.$t->label, 'Body.', ['throwaway']);
        $tags = self::getContainer()->get(TagAdmin::class);
        $tags->remove(
            $this->em->getRepository(Tag::class)->findOneBy(['name' => 'throwaway']),
            $name,
        );
        self::assertNotNull($tagged->getId());

        // The directory's rows for the account beyond the fixture's identity
        // and bearer tokens: a browser signed in, and a code mid-exchange.
        $this->otherBrowser($t, null);
        $this->directory()->executeStatement(
            'INSERT INTO oauth_codes (code, client_id, account_id, redirect_uri, code_challenge, expires_at, created_at)
             VALUES (:code, :client, :account, :uri, :challenge, :expires, :now)',
            [
                'code' => 'mxa_'.bin2hex(random_bytes(24)), 'client' => 'client-of-'.$t->label, 'account' => $t->accountId,
                'uri' => 'https://example.test/cb', 'challenge' => 'challenge', 'expires' => self::stamp('+10 minutes'), 'now' => self::stamp(),
            ]
        );
    }

    /** A browser this account is signed in on, other than the test's own. */
    private function otherBrowser(Tenant $t, ?string $phpSessionId): void
    {
        $this->directory()->executeStatement(
            'INSERT INTO user_sessions (ref, account_id, session_key, php_session_id, created_at, last_seen_at, expires_at)
             VALUES (:ref, :account, :key, :sid, :now, :now, :expires)',
            [
                'ref' => bin2hex(random_bytes(8)), 'account' => $t->accountId, 'key' => bin2hex(random_bytes(32)),
                'sid' => $phpSessionId, 'now' => self::stamp(), 'expires' => self::stamp('+1 day'),
            ]
        );
    }

    /** @return array<string, int> */
    private function rowsPerTable(Tenant $t): array
    {
        $this->in($t);
        $db = $this->em->getConnection();
        $counts = [];
        foreach ($db->fetchFirstColumn(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name"
        ) as $table) {
            $counts[$table] = (int) $db->fetchOne('SELECT COUNT(*) FROM "'.$table.'"');
        }

        return $counts;
    }

    /** @return array<string, int> */
    private function directoryRowsOf(int $accountId): array
    {
        $db = $this->directory();
        $counts = [];
        // Read the tables out of the schema rather than listing them here: a
        // table added later with an account_id is one this deletion would have
        // to take, and a hand-written list is how it would be forgotten. The
        // core's tables only; an edition's own are its tests' to fill.
        $core = [];
        foreach (glob(\dirname(__DIR__, 2).'/schema/directory/*.sql') ?: [] as $file) {
            preg_match_all('/CREATE TABLE (\w+)/', (string) file_get_contents($file), $m);
            array_push($core, ...$m[1]);
        }
        foreach ($db->fetchFirstColumn(
            "SELECT m.name FROM sqlite_master m, pragma_table_info(m.name) c
             WHERE m.type = 'table' AND c.name = 'account_id' ORDER BY m.name"
        ) as $table) {
            if (!\in_array($table, $core, true)) {
                continue;
            }
            $counts[$table] = (int) $db->fetchOne("SELECT COUNT(*) FROM $table WHERE account_id = :account", ['account' => $accountId]);
        }

        return $counts;
    }

    public function testDeletingTakesTheWholeAccountAndNoOneElsesRow(): void
    {
        $this->fillKnowledgeBase($this->kb->a);
        $this->fillKnowledgeBase($this->kb->b);
        $vaultA = $this->kb->a->account()->getVaultKey();

        $bBefore = $this->rowsPerTable($this->kb->b);
        $before = $this->directoryRowsOf($this->kb->a->accountId);
        $bDirectoryBefore = $this->directoryRowsOf($this->kb->b->accountId);
        self::assertNotSame([], $before);
        // The assertion that keeps the one below honest.
        foreach ($before as $table => $count) {
            self::assertGreaterThan(0, $count, "$table holds nothing, so this test proves nothing about it");
        }

        $this->loginAs($this->kb->a);
        $this->delete();

        self::assertNull($this->account($this->kb->a->accountId));
        $path = static::getContainer()->get(DataDir::class)->vaultPath($vaultA);
        foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
            self::assertFileDoesNotExist($file, 'A destroyed knowledge base left its vault on the box');
        }

        foreach ($this->directoryRowsOf($this->kb->a->accountId) as $table => $count) {
            self::assertSame(0, $count, "$table still holds rows for a destroyed account");
        }

        // The other tenant is untouched, which is the same property the
        // isolation suite asserts and the one this operation could break worst.
        self::assertSame($bBefore, $this->rowsPerTable($this->kb->b), 'The deletion reached into the other knowledge base');
        self::assertSame($bDirectoryBefore, $this->directoryRowsOf($this->kb->b->accountId), 'The deletion reached into the other account');
        self::assertNotNull($this->account($this->kb->b->accountId));
    }

    /**
     * There is no way back, and that is now the whole contract.
     *
     * Asserted because the routes it replaced are exactly what a half-finished
     * revert would leave standing, and a Reopen button that 404s is worse than
     * none: it tells somebody a grace period exists.
     */
    public function testThereIsNoClosingPeriodToReopen(): void
    {
        $this->loginAs($this->kb->a);

        $this->sessionRequest('POST', '/api/me/close', ['confirm_email' => $this->kb->a->email]);
        self::assertSame(404, $this->httpStatus());

        $this->sessionRequest('POST', '/api/me/reopen');
        self::assertSame(404, $this->httpStatus());

        $this->sessionRequest('GET', '/api/me');
        self::assertArrayNotHasKey('closing', $this->jsonResponse());
    }
}
