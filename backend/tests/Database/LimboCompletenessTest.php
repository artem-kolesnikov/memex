<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;
use App\Service\FrontmatterParser;
use App\Service\NoteLimbo;
use App\Service\NoteWriter;
use App\Service\VaultExporter;
use App\Tests\Support\KbFixture;

/**
 * The promises the deletion/export machinery makes in its own doc-comments,
 * asserted.
 *
 * Purge and export live here; the revision-restore half of the cluster is in
 * RevisionRestoreTest, which has to drive the controller over HTTP to mean
 * anything.
 *
 * These three were found the same way, which is the audit's real lesson: read
 * a guarantee stated in a comment, then go looking for the line that enforces
 * it. Purge said "nothing survives" and nulled two columns of eight. The
 * exporter was called the no-lock-in promise and dropped the description. The
 * revision snapshot captured a summary that restore never read back.
 */
final class LimboCompletenessTest extends DatabaseTestCase
{
    private NoteWriter $writer;
    private NoteLimbo $limbo;
    private VaultExporter $exporter;
    private FrontmatterParser $frontmatter;
    private KbFixture $kb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = self::getContainer()->get(NoteWriter::class);
        $this->limbo = self::getContainer()->get(NoteLimbo::class);
        $this->exporter = self::getContainer()->get(VaultExporter::class);
        $this->frontmatter = self::getContainer()->get(FrontmatterParser::class);
        $this->kb = new KbFixture(self::getContainer());
    }

    // ------------------------------------------------------- codex H-1: purge

    public function testPurgeDestroysEveryFreeTextFieldNotOnlyTheBody(): void
    {
        $result = $this->writer->create(
            null,
            'Ordinary looking title',
            'The body holds a credential.',
            Note::SOURCE_SCRAPE,
            'https://internal.example.test/secret-path?token=abc',
            ['alpha', 'beta'],
            enrich: null,
            importPath: 'private/vault/path',
            summary: 'A description that also repeats the secret.',
            applyTags: false,
        );
        $note = $result['note'];
        $id = $note->getId();
        $this->limbo->retire($note, Note::ACTOR_HUMAN, 'Contains the API key for the billing account.');

        $this->limbo->purge($id);

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT * FROM deleted_notes WHERE id = :id',
            ['id' => $id]
        );
        self::assertIsArray($row);
        foreach (['body_md', 'summary', 'summary_by', 'source_url', 'import_path', 'deleted_reason'] as $field) {
            self::assertNull($row[$field], "$field survived a purge");
        }
        self::assertSame([], json_decode((string) $row['tags'], true, 8, JSON_THROW_ON_ERROR));
    }

    public function testPurgeKeepsTheTitleSoTheTombstoneStillBlocksReimport(): void
    {
        // The deliberate exception, and the one that must not be "tidied up"
        // later: without the title the tombstone cannot match an incoming
        // archive, and the most dangerous content in the system becomes the
        // easiest to walk back in through the import screen.
        $note = $this->kb->note($this->writer, 'Ordinary looking title', 'Secret.');
        $id = $note->getId();
        $this->limbo->retire($note, Note::ACTOR_HUMAN, null);
        $this->limbo->purge($id);

        $tombstones = $this->limbo->tombstonesByTitle(['Ordinary looking title']);

        self::assertArrayHasKey('ordinary looking title', $tombstones);
        self::assertTrue($tombstones['ordinary looking title']['purged']);
    }

    public function testNothingFromAPurgedNoteIsReadableThroughTheLimboListing(): void
    {
        $result = $this->writer->create(
            null, 'Title', 'Secret body.', Note::SOURCE_SCRAPE,
            'https://example.test/secret', [],
            enrich: null,
            summary: 'Secret summary.', applyTags: false,
        );
        $id = $result['note']->getId();
        $this->limbo->retire($result['note'], Note::ACTOR_HUMAN, 'Because it holds SECRETPHRASE.');
        $this->limbo->purge($id);

        $listing = json_encode($this->limbo->list(includePurged: true), JSON_THROW_ON_ERROR);

        foreach (['Secret body', 'Secret summary', 'secret', 'SECRETPHRASE'] as $leak) {
            self::assertStringNotContainsString($leak, $listing, "purged content is readable in the limbo listing: $leak");
        }
    }

    // ------------------------------------- L-8: history across a round trip

    public function testARestoredNoteGetsItsCurationHistoryBack(): void
    {
        // Restore's own contract is that a note comes back AT ITS ORIGINAL ID,
        // because everything names notes by id. The curator log was the one
        // thing that did not reconnect — so the check that exists to stop an
        // agent re-proposing what the operator already refused answered
        // "nothing known" for exactly the notes with the most history.
        $note = $this->kb->note($this->writer, 'Contested note', 'Body.');
        $id = $note->getId();
        $proposal = $this->writer->proposeDelete($note, $this->kb->curatorToken, 'Should go.');
        self::getContainer()->get(\App\Service\ReviewVerdicts::class)
            ->rejectProposal($proposal, ['comment' => 'No — still cited elsewhere.', 'precedent' => true]);
        self::assertSame(3, $this->logRowsFor($id), 'Precondition: created, proposed, then refused');

        $this->limbo->retire($note, Note::ACTOR_HUMAN, 'Deleted anyway, by mistake.');
        self::assertSame(0, $this->logRowsFor($id), 'The FK nulls them, by design');

        $this->limbo->restore($id);

        self::assertSame(5, $this->logRowsFor($id), 'A restored note is not a note nobody has argued about: created, proposed, refused, deleted, restored');
    }

    public function testPurgingClearsTheHintSinceNothingCanRestoreThatIdAgain(): void
    {
        $note = $this->kb->note($this->writer, 'Gone for good', 'Body.');
        $id = $note->getId();
        $this->writer->proposeDelete($note, $this->kb->curatorToken, 'Should go.');
        $this->limbo->retire($note, Note::ACTOR_HUMAN, null);
        $this->limbo->purge($id);

        self::assertSame(
            0,
            (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM curator_log WHERE retired_note_id = :id',
                ['id' => $id]
            ),
            'A pointer to a note that can never come back is a dangling one'
        );
        self::assertGreaterThan(
            0,
            (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM curator_log'),
            'The rows themselves stay — they are the record that it existed'
        );
    }

    private function logRowsFor(int $noteId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM curator_log WHERE note_id = :id',
            ['id' => $noteId]
        );
    }

    // ---------------------------------------------------------- M-7: export

    public function testExportedMarkdownCarriesTheSummaryAndItsProvenance(): void
    {
        $note = $this->kb->note($this->writer, 'A note', 'Body text.', ['alpha'], summary: 'What this note is for.');

        $markdown = $this->exporter->toMarkdown($note);

        self::assertStringContainsString('summary: "What this note is for."', $markdown);
        self::assertStringContainsString('summary_by:', $markdown);
    }

    public function testAnExportedNoteImportsBackWithItsDescriptionIntact(): void
    {
        // The property the no-lock-in promise actually makes. Writing the field
        // without reading it back would have moved the loss one step later
        // rather than fixing it, so the round trip is the assertion.
        $note = $this->kb->note(
            $this->writer,
            'Round trip',
            'Body text.',
            ['alpha'],
            summary: 'A description with: a colon, "quotes", and a — dash.'
        );

        $parsed = $this->frontmatter->parse($this->exporter->toMarkdown($note), 'fallback');

        self::assertSame('Round trip', $parsed['title']);
        self::assertSame('A description with: a colon, "quotes", and a — dash.', $parsed['summary']);
        self::assertSame(Note::SUMMARY_BY_OPERATOR, $parsed['summary_by']);
        self::assertSame(['alpha'], $parsed['tags']);
        self::assertSame('Body text.', trim($parsed['body']));
    }

    public function testANoteWithNoSummaryExportsAndParsesWithoutInventingOne(): void
    {
        $note = $this->kb->note($this->writer, 'Undescribed', 'Body text.');
        $this->writer->update($note, null, null, null, \App\Service\EmbeddingSpend::Metered, summaryProvided: true, summary: null, applyTags: false);

        $parsed = $this->frontmatter->parse($this->exporter->toMarkdown($note), 'fallback');

        self::assertNull($parsed['summary']);
        self::assertNull($parsed['summary_by']);
    }
}
