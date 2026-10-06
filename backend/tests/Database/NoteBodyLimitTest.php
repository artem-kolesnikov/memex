<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\EditProposal;
use App\Entity\Note;
use App\Entity\VaultSettings;
use App\Service\EmbeddingSpend;
use App\Service\EnrichmentSettings;
use App\Service\NoteLimbo;
use App\Service\NoteWriter;
use App\Service\StorageLimitExceeded;
use App\Service\StorageLimits;
use App\Tests\Support\KbFixture;
use Doctrine\DBAL\Exception\DriverException;

final class NoteBodyLimitTest extends DatabaseTestCase
{
    private function writer(): NoteWriter
    {
        return self::getContainer()->get(NoteWriter::class);
    }

    private function limit(int $bytes): void
    {
        self::getContainer()->get(StorageLimits::class)->update(['max_note_bytes' => $bytes]);
    }

    private function create(KbFixture $kb, string $body): Note
    {
        return $this->writer()->create(null, 'Note', $body, Note::SOURCE_MANUAL, null, [], enrich: null)['note'];
    }

    private function refused(callable $work): void
    {
        try {
            $work();
            self::fail('Oversized growth was accepted');
        } catch (StorageLimitExceeded $error) {
            self::assertSame('note_bytes', $error->payload()['dimension']);
            self::assertGreaterThan($error->payload()['limit'], $error->payload()['observed']);
        }
    }

    private function sqlRefused(callable $work): void
    {
        try {
            $this->em->getConnection()->transactional($work);
            self::fail('Raw write bypassed body admission');
        } catch (DriverException $error) {
            self::assertStringContainsString('MEMEX_BODY_LIMIT', $error->getMessage());
            $typed = StorageLimitExceeded::fromThrowable(new \RuntimeException('Wrapped', 0, $error));
            self::assertNotNull($typed);
            self::assertSame('note_bytes', $typed->dimension);
            self::assertGreaterThan($typed->limit, $typed->observed);
        }
    }

    public function testCreateRefusesBeforeAnyWriteOrProviderCall(): void
    {
        $kb = new KbFixture(self::getContainer());
        $this->limit(10);
        $this->create($kb, str_repeat('é', 5));
        $this->refused(fn () => $this->writer()->create($kb->curatorToken, 'Too large', str_repeat('é', 5).'x', Note::SOURCE_MANUAL, null, ['new-tag'], enrich: EmbeddingSpend::Metered));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM notes'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM tags'));
        self::assertSame([], $this->ml->calls);
    }

    public function testANoteOverALoweredLimitCanBeRetitledAndShrunkButNotRegrown(): void
    {
        $kb = new KbFixture(self::getContainer());
        $note = $this->create($kb, str_repeat('x', 20));
        $this->limit(10);
        $this->writer()->update($note, 'Retitled', null, null, null);
        $this->writer()->update($note, null, str_repeat('x', 18), null, null);
        $this->refused(fn () => $this->writer()->update($note, null, str_repeat('x', 19), null, null));
        self::assertSame(18, (int) $this->em->getConnection()->fetchOne('SELECT octet_length(body_md) FROM notes WHERE id = ?', [$note->getId()]));
        $kb->refresh();
        $this->refused(fn () => $this->create($kb, str_repeat('x', 18)));
    }

    public function testRawSqlCannotBypassTheLimit(): void
    {
        $kb = new KbFixture(self::getContainer());
        $note = $this->create($kb, 'short');
        $this->limit(10);
        $db = $this->em->getConnection();
        $this->sqlRefused(fn () => $db->executeStatement('UPDATE notes SET body_md = ? WHERE id = ?', [str_repeat('x', 11), $note->getId()]));
        self::assertSame('short', $db->fetchOne('SELECT body_md FROM notes WHERE id = ?', [$note->getId()]));
    }

    public function testAStaleEntityCannotLendItsOldLargerBaseline(): void
    {
        $kb = new KbFixture(self::getContainer());
        $note = $this->create($kb, str_repeat('x', 20));
        $this->limit(10);
        $db = $this->em->getConnection();
        $db->executeStatement('UPDATE notes SET body_md = ? WHERE id = ?', ['short', $note->getId()]);
        $this->refused(fn () => $this->writer()->update($note, null, str_repeat('y', 15), null, null));
        self::assertSame('short', $db->fetchOne('SELECT body_md FROM notes WHERE id = ?', [$note->getId()]));
    }

    public function testPatchesAreCheckedComposedAndAgainAtApproval(): void
    {
        $kb = new KbFixture(self::getContainer());
        $note = $this->create($kb, 'alpha beta');
        $this->limit(20);
        $draft = $this->writer()->propose($note, $kb->agentToken, null, null, null, patch: [['find' => 'alpha', 'replace' => 'alpha more']]);
        $id = $draft->getId();
        $this->refused(fn () => $this->writer()->propose($note, $kb->agentToken, null, null, null, patch: [['find' => 'beta', 'replace' => 'beta much more']]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM edit_proposals'));
        $kb->refresh();
        $draft = $this->em->getRepository(EditProposal::class)->find($id);
        $this->limit(12);
        $this->refused(fn () => $this->writer()->applyProposal($draft));
        self::assertSame('alpha beta', $this->em->getConnection()->fetchOne('SELECT body_md FROM notes WHERE id = ?', [$note->getId()]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM edit_proposals'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM note_revisions'));
    }

    /**
     * An oversized note comes back only as itself, and bringing it back
     * consumes its tombstone, however it is brought back: the licence is
     * spent once.
     */
    public function testRestorationNeedsTheOriginalIdentityBodyAndConsumesTheTombstone(): void
    {
        $kb = new KbFixture(self::getContainer());
        $limbo = self::getContainer()->get(NoteLimbo::class);
        $viaService = $this->create($kb, str_repeat('x', 20));
        $viaSql = $this->create($kb, str_repeat('z', 20));
        [$serviceId, $sqlId] = [$viaService->getId(), $viaSql->getId()];
        $limbo->retire($viaService, 'operator');
        $limbo->retire($this->em->find(Note::class, $sqlId), 'operator');
        $this->limit(10);
        $db = $this->em->getConnection();

        self::assertSame(str_repeat('x', 20), $limbo->restore($serviceId)->getBodyMd());
        $db->transactional(fn () => $db->executeStatement(
            'INSERT INTO notes (id, created_by_token_id, title, body_md, source, status, created_at, updated_at) SELECT id, created_by_token_id, title, body_md, source, status, note_created_at, :now FROM deleted_notes WHERE id = :id',
            ['id' => $sqlId, 'now' => self::now()],
        ));

        self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM deleted_notes'), 'Both tombstones were consumed');
        foreach ([$serviceId, $sqlId] as $id) {
            $body = (string) $db->fetchOne('SELECT body_md FROM notes WHERE id = ?', [$id]);
            $db->executeStatement('DELETE FROM notes WHERE id = ?', [$id]);
            $this->sqlRefused(fn () => $db->executeStatement(
                "INSERT INTO notes (id, title, body_md, source, status, created_at, updated_at) VALUES (?, 'Again', ?, 'manual', 'verified', ?, ?)",
                [$id, $body, self::now(), self::now()],
            ));
        }
    }

    public function testDefaultLimitCountsUtf8Bytes(): void
    {
        $kb = new KbFixture(self::getContainer());
        $body = str_repeat('é', 1048576);
        $note = $this->create($kb, $body);
        self::assertSame(2097152, strlen($note->getBodyMd()));
        $this->refused(fn () => $this->writer()->update($note, null, $body.'a', null, null));
    }

    public function testAFabricatedTombstoneCannotSupplyOversizedProvenance(): void
    {
        $kb = new KbFixture(self::getContainer());
        $note = $this->create($kb, str_repeat('x', 20));
        $this->limit(10);
        $db = $this->em->getConnection();
        $id = $note->getId();
        $insert = "INSERT INTO deleted_notes (id, created_by_token_id, title, body_md, tags, source, status, last_actor, note_created_at, note_updated_at, deleted_at, deleted_by, purge_after) SELECT id, created_by_token_id, title, :body, '[]', source, status, last_actor, created_at, updated_at, :now, 'test', datetime(:now, '+1 day') FROM notes WHERE id = :id";
        $this->sqlRefused(fn () => $db->executeStatement($insert, ['body' => str_repeat('y', 20), 'id' => $id, 'now' => self::now()]));
        self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM deleted_notes'));
        $limbo = self::getContainer()->get(NoteLimbo::class);
        $limbo->retire($note, 'operator');
        foreach (['body_md' => str_repeat('x', 21), 'id' => $id + 100] as $column => $value) {
            $this->sqlRefused(fn () => $db->executeStatement('UPDATE deleted_notes SET '.$column.' = ? WHERE id = ?', [$value, $id]));
        }
        foreach ([
            ['id + 100', 'body_md'],
            ['id', ':body'],
        ] as [$restoreId, $restoreBody]) {
            $restore = 'INSERT INTO notes (id, created_by_token_id, title, body_md, source, status, created_at, updated_at) SELECT '.$restoreId.', created_by_token_id, title, '.$restoreBody.', source, status, note_created_at, :now FROM deleted_notes WHERE id = :id';
            $params = ['id' => $id, 'now' => self::now()] + ($restoreBody === ':body' ? ['body' => str_repeat('y', 20)] : []);
            $this->sqlRefused(fn () => $db->executeStatement($restore, $params));
        }
        self::assertSame(str_repeat('x', 20), $limbo->restore($id)->getBodyMd());
    }

    public function testAHeldDraftOverTheLimitMayBeRetitledOrShrunkButNotGrownOrApplied(): void
    {
        $kb = new KbFixture(self::getContainer());
        $note = $this->create($kb, 'short');
        $draft = $this->writer()->propose($note, $kb->agentToken, null, str_repeat('x', 20), null);
        $this->limit(10);
        $revised = $this->writer()->propose($note, $kb->agentToken, 'New title', null, null, comment: 'Keep this draft');
        self::assertSame($draft->getId(), $revised->getId());
        self::assertSame(str_repeat('x', 20), $revised->getProposedBodyMd());
        $this->writer()->propose($note, $kb->agentToken, null, str_repeat('x', 18), null);
        $this->refused(fn () => $this->writer()->propose($note, $kb->agentToken, null, str_repeat('x', 19), null));
        $draft = $this->em->find(EditProposal::class, $draft->getId());
        $this->refused(fn () => $this->writer()->applyProposal($draft));
        self::assertSame('short', $this->em->getConnection()->fetchOne('SELECT body_md FROM notes WHERE id = ?', [$note->getId()]));
        self::assertSame('held', $this->em->getConnection()->fetchOne('SELECT status FROM edit_proposals WHERE id = ?', [$draft->getId()]));
    }

    public function testMergeRechecksTheBodyAndANullMergeKeepsTheKeeper(): void
    {
        $kb = new KbFixture(self::getContainer());
        $absorb = $this->create($kb, 'first');
        $keeper = $this->create($kb, 'second');
        $draft = $this->writer()->proposeMerge($absorb, $keeper, $kb->agentToken, str_repeat('x', 15), null);
        $this->limit(10);
        $this->refused(fn () => $this->writer()->applyProposal($draft));
        $db = $this->em->getConnection();
        self::assertSame(2, (int) $db->fetchOne('SELECT count(*) FROM notes'));
        self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM deleted_notes'));
        self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM note_revisions'));
        $kb->refresh();
        $absorb = $this->em->find(Note::class, $absorb->getId());
        $keeper = $this->em->find(Note::class, $keeper->getId());
        $draft = $this->writer()->proposeMerge($absorb, $keeper, $kb->agentToken, null, null);
        $this->writer()->applyProposal($draft);
        self::assertSame('second', $db->fetchOne('SELECT body_md FROM notes WHERE id = ?', [$keeper->getId()]));
        self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM notes'));
    }

    public function testARefusedCreateDoesNotUnretireTheTagItNamed(): void
    {
        $kb = new KbFixture(self::getContainer());
        $db = $this->em->getConnection();
        $db->executeStatement("INSERT INTO retired_tags (name, note_count, merged_into, retired_at) VALUES ('old-tag', 0, NULL, ?)", [self::now()]);
        // The early check passes at 40 bytes; the row is refused at 10, as it
        // would be if the operator lowered the limit between the two.
        $policy = $this->createMock(\Doctrine\DBAL\Connection::class);
        $policy->method('fetchAssociative')->willReturn(['max_note_bytes' => 40, 'max_import_bytes' => 100]);
        $limits = new StorageLimits($policy);
        $writer = new NoteWriter(
            $this->em,
            self::getContainer()->get(\App\Service\NoteEnricher::class),
            self::getContainer()->get(NoteLimbo::class),
            self::getContainer()->get(\App\Service\NoteRevisions::class),
            self::getContainer()->get(\App\Service\TagAdmin::class),
            $limits,
            self::getContainer()->get(\App\Service\GrowthLimits::class),
            self::getContainer()->get(\App\Service\Journal::class),
        );
        $this->limit(10);
        $this->refused(fn () => $writer->create(null, 'Note', str_repeat('x', 40), Note::SOURCE_MANUAL, null, ['old-tag'], enrich: null));
        self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM retired_tags WHERE name = ?', ['old-tag']));
        self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM tags'));
        self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM notes'));
    }

    public function testAProposalIsCheckedAgainstTheStoredBodyNotTheLoadedOne(): void
    {
        $kb = new KbFixture(self::getContainer());
        $note = $this->create($kb, str_repeat('x', 20));
        $this->limit(10);
        $this->em->getConnection()->executeStatement('UPDATE notes SET body_md = ? WHERE id = ?', ['short', $note->getId()]);
        $this->refused(fn () => $this->writer()->propose($note, $kb->agentToken, null, str_repeat('y', 15), null));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM edit_proposals'));
    }

    public function testAReductionBetweenTheEarlyCheckAndTheWriteIsCaughtByTheDatabase(): void
    {
        $kb = new KbFixture(self::getContainer());
        $note = $this->create($kb, 'short');
        self::getContainer()->get(StorageLimits::class)->assertBody(str_repeat('x', 20), 5);
        $this->limit(10);
        $db = $this->em->getConnection();
        $this->sqlRefused(fn () => $db->executeStatement('UPDATE notes SET body_md = ? WHERE id = ?', [str_repeat('x', 20), $note->getId()]));
        self::assertSame('short', $db->fetchOne('SELECT body_md FROM notes WHERE id = ?', [$note->getId()]));
        self::assertNull(StorageLimitExceeded::fromThrowable(new \RuntimeException('MEMEX_BODY_LIMIT {"observed":20,"limit":10,"action":"fake"}')));
    }

    public function testTheLimitIgnoresTierAndOwnKey(): void
    {
        $kb = new KbFixture(self::getContainer());
        $service = self::getContainer()->get(EnrichmentSettings::class);
        $saved = $service->saveKey('openai', 'Synthetic test key', 'sk-synthetic-body-limit');
        self::assertTrue($saved['ok']);
        $kb->account->setTier('unlimited');
        self::getContainer()->get('doctrine.orm.directory_entity_manager')->flush();
        $this->em->getRepository(VaultSettings::class)->current()->setEmbedCredential($saved['credential']);
        $this->em->flush();
        $this->limit(10);
        $this->ml->calls = [];
        $this->refused(fn () => $this->writer()->create($kb->curatorToken, 'Oversized', str_repeat('x', 11), Note::SOURCE_MANUAL, null, [], enrich: EmbeddingSpend::Metered));
        self::assertSame([], $this->ml->calls);
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
