<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\CuratorLogEntry;
use App\Entity\EditProposal;
use App\Entity\Note;
use App\Entity\SearchPreset;
use App\Entity\Tag;
use App\Service\NoteWriter;
use App\Service\SystemTagException;
use App\Service\TagAdmin;
use App\Tests\Support\KbFixture;

/**
 * Removing a tag from a vocabulary, and moving one into another.
 *
 * The operator asked for an (x) beside each tag (2026-08-23) and asked, in the
 * same message, whether that was a mistake. It half was, and these tests pin
 * the half that was fixed rather than argued:
 *
 *  - it is a BULK EDIT of every note carrying the word, so the count is
 *    returned and recorded rather than left for the owner to discover;
 *  - a removal that leaves no record does not hold — enrichment reads the next
 *    note, decides it looks like an `inbox` item, and offers the word back;
 *  - and what the record suppresses is SUGGESTION, not use. Nothing stops a
 *    person typing the word again, and doing so ends the suppression.
 *
 * Merge exists because it is what tag cleanup usually IS. Nobody wants to fix
 * "project" and "projects" by deleting one and re-tagging forty notes by hand.
 */
final class TagAdminTest extends DatabaseTestCase
{
    private NoteWriter $writer;
    private TagAdmin $tags;
    private KbFixture $kb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = self::getContainer()->get(NoteWriter::class);
        $this->tags = self::getContainer()->get(TagAdmin::class);
        $this->kb = new KbFixture(self::getContainer());
    }

    public function testRemovingATagTakesItOffEveryNoteAndSaysHowMany(): void
    {
        $a = $this->kb->note($this->writer, 'One', 'Body.', ['inbox', 'keep']);
        $b = $this->kb->note($this->writer, 'Two', 'Body.', ['inbox']);
        $untouched = $this->kb->note($this->writer, 'Three', 'Body.', ['keep']);
        [$aId, $bId, $cId] = [$a->getId(), $b->getId(), $untouched->getId()];

        self::assertSame(2, $this->tags->remove($this->tag('inbox'), $this->kb->account->getName()));

        self::assertNull($this->tag('inbox'), 'The word is gone from the vocabulary');
        self::assertSame(['keep'], $this->tagsOf($aId));
        self::assertSame([], $this->tagsOf($bId));
        self::assertSame(['keep'], $this->tagsOf($cId), 'A note that never had it is untouched');
    }

    public function testRemovingATagIsRecordedInTheJournalWithItsCount(): void
    {
        $this->kb->note($this->writer, 'One', 'Body.', ['inbox']);
        $this->kb->note($this->writer, 'Two', 'Body.', ['inbox']);

        $this->tags->remove($this->tag('inbox'), $this->kb->account->getName());

        $row = $this->em->getRepository(CuratorLogEntry::class)->findOneBy(
            ['action' => CuratorLogEntry::ACTION_TAG_REMOVED],
        );
        self::assertNotNull($row, 'The one irreversible bulk edit in memex has to leave a record');
        self::assertStringContainsString('inbox', $row->getDescription());
        self::assertStringContainsString('2 note(s)', $row->getDescription());
    }

    public function testTheNotesThatChangedAreMarkedAsChanged(): void
    {
        $note = $this->kb->note($this->writer, 'One', 'Body.', ['inbox']);
        $id = $note->getId();
        $before = $this->version($id);

        $this->tags->remove($this->tag('inbox'), $this->kb->account->getName());

        self::assertGreaterThan(
            $before,
            $this->version($id),
            'A note that lost a tag changed, and an editor open on it should be told',
        );
    }

    public function testMergingMovesTheNotesOntoTheOtherTag(): void
    {
        $one = $this->kb->note($this->writer, 'One', 'Body.', ['project']);
        // Already carries both, which is the ordinary state of the vocabulary
        // somebody is merging — and the row that a plain INSERT would break on.
        $both = $this->kb->note($this->writer, 'Two', 'Body.', ['project', 'projects']);
        [$oneId, $bothId] = [$one->getId(), $both->getId()];

        self::assertSame(2, $this->tags->merge($this->tag('project'), $this->tag('projects'), $this->kb->account->getName()));

        self::assertNull($this->tag('project'));
        self::assertSame(['projects'], $this->tagsOf($oneId));
        self::assertSame(['projects'], $this->tagsOf($bothId), 'No duplicate, no crash');
    }

    public function testAMergeRecordsWhereTheNotesWent(): void
    {
        $this->kb->note($this->writer, 'One', 'Body.', ['project', 'projects']);

        $this->tags->merge($this->tag('project'), $this->tag('projects'), $this->kb->account->getName());

        $retired = $this->tags->retired();
        self::assertCount(1, $retired);
        self::assertSame('project', $retired[0]['name']);
        self::assertSame(
            'projects',
            $retired[0]['merged_into'],
            'The useful half of the record: the word the owner prefers',
        );
    }

    /** The point of the record: the machine stops offering the word back. */
    public function testARetiredNameIsNotSuggestedBack(): void
    {
        $this->kb->note($this->writer, 'One', 'Body.', ['inbox']);
        $this->tags->remove($this->tag('inbox'), $this->kb->account->getName());

        self::assertSame(['inbox'], $this->tags->retiredNames());
    }

    /**
     * And the limit of it. A tag the owner is forbidden to use in their own
     * knowledge base would be an absurd thing to have built.
     */
    public function testAPersonUsingTheNameAgainEndsTheSuppression(): void
    {
        $this->kb->note($this->writer, 'One', 'Body.', ['inbox']);
        $this->tags->remove($this->tag('inbox'), $this->kb->account->getName());
        self::assertNotSame([], $this->tags->retiredNames());

        $this->kb->note($this->writer, 'Written later', 'Body.', ['inbox']);

        self::assertSame(
            [],
            $this->tags->retiredNames(),
            'The tag is demonstrably back, so saying it was rejected is no longer true',
        );
        self::assertNotNull($this->tag('inbox'));
    }

    public function testRetiringATagTwiceKeepsOneRecord(): void
    {
        $this->kb->note($this->writer, 'One', 'Body.', ['inbox']);
        $this->tags->remove($this->tag('inbox'), $this->kb->account->getName());
        $this->kb->note($this->writer, 'Two', 'Body.', ['inbox']);
        $this->tags->remove($this->tag('inbox'), $this->kb->account->getName());

        $retired = $this->tags->retired();
        self::assertCount(1, $retired, 'The record describes the last retirement, not every one');
        self::assertSame(1, $retired[0]['note_count']);
    }

    public function testATagCannotBeMergedIntoItself(): void
    {
        $this->kb->note($this->writer, 'One', 'Body.', ['inbox']);
        $tag = $this->tag('inbox');

        $this->expectException(\InvalidArgumentException::class);
        $this->tags->merge($tag, $tag, $this->kb->account->getName());
    }

    /**
     * A held proposal carries tag NAMES, resolved only when it applies. So a
     * proposal filed before a removal still says the word, and approving it is
     * a person deciding the word is wanted — which is exactly the case that
     * must not be blocked.
     */
    public function testAHeldProposalNamingARetiredTagStillApplies(): void
    {
        $note = $this->kb->note($this->writer, 'One', 'Body.', ['inbox']);
        $proposal = $this->writer->propose(
            $note, $this->kb->agentToken, null, null, ['inbox', 'extra'], applyTags: false,
        );
        self::assertInstanceOf(EditProposal::class, $proposal);
        $proposalId = $proposal->getId();

        $this->tags->remove($this->tag('inbox'), $this->kb->account->getName());

        $verdicts = self::getContainer()->get(\App\Service\ReviewVerdicts::class);
        $proposal = $this->em->getRepository(EditProposal::class)->find($proposalId);
        $verdicts->approveProposal(
            $proposal,
            ['comment' => null, 'precedent' => false],
            $this->reviewSnapshot($proposal),
        );

        self::assertSame(['extra', 'inbox'], $this->tagsOf($note->getId()));
        self::assertSame([], $this->tags->retiredNames());
    }

    /**
     * The enforcement, at the one boundary where memex itself chooses words.
     *
     * `tag_ids` cannot carry a retired name — retiring a tag deletes its row —
     * so `new_tags`, the names the model invents, is the whole surface.
     */
    public function testEnrichmentDoesNotInventANameTheOwnerRetired(): void
    {
        $settings = self::getContainer()->get(\App\Service\EnrichmentSettings::class);
        $key = $settings->saveKey(\App\Service\AiProviders::OPENAI, 'OpenAI', 'sk-valid-key')['credential'];
        $settings->save(enabled: true, credential: $key);

        $this->kb->note($this->writer, 'One', 'Body.', ['inbox']);
        $this->tags->remove($this->tag('inbox'), $this->kb->account->getName());

        $this->ml->suggestedNewTags = ['inbox', 'something-new'];
        $note = $this->kb->note($this->writer, 'Arrived later', 'Body.', []);

        $suggested = self::getContainer()->get(\App\Service\NoteEnricher::class)
            ->enrich($note, \App\Service\EmbeddingSpend::Metered, generateSummary: false);

        self::assertSame(
            ['something-new'],
            array_values($suggested['new_tags']),
            'A word the owner took off every note is not offered straight back',
        );
    }

    /**
     * The lock lives in the SERVICE, not only in the controller in front of it.
     *
     * The controller refuses first and gives the nicer answer, but a console
     * command or an import tidy-up would not go through it, and the act here
     * is the irreversible one. This is the assertion that keeps the guard where
     * a future caller cannot walk round it.
     */
    public function testTheServiceItselfRefusesToRetireATagMemexReads(): void
    {
        $this->kb->note($this->writer, 'Charter', 'Body.', ['skill']);
        $tag = $this->tag('skill');
        self::assertNotNull($tag);

        $this->expectException(SystemTagException::class);
        $this->tags->remove($tag, $this->kb->account->getName());
    }

    public function testTheRefusalLeavesTheNotesAndTheVocabularyUntouched(): void
    {
        $note = $this->kb->note($this->writer, 'Runbook', 'Body.', ['live-state', 'infra']);
        $other = $this->tag('infra');
        $tag = $this->tag('live-state');
        self::assertNotNull($tag);
        self::assertNotNull($other);

        try {
            $this->tags->merge($tag, $other, $this->kb->account->getName());
            self::fail('A merge takes the word off every note carrying it, same as a removal');
        } catch (SystemTagException $e) {
            self::assertSame('live-state', $e->tag);
        }

        // Nothing half-happened: the guard runs before the transaction opens,
        // so there is no partial rewrite and no retired_tags row claiming the
        // owner rejected the word.
        self::assertNotNull($this->tag('live-state'));
        self::assertContains('live-state', $this->tagsOf($note->getId() ?? 0));
        self::assertNotContains('live-state', $this->tags->retiredNames());
    }

    public function testASavedFilterFollowsItsTagIntoAMerge(): void
    {
        $this->kb->note($this->writer, 'One', 'Body.', ['interview']);
        $this->kb->note($this->writer, 'Two', 'Body.', ['job']);
        $from = $this->tag('interview');
        $into = $this->tag('job');
        self::assertNotNull($from);
        self::assertNotNull($into);
        $only = $this->preset('Job interview', [(int) $from->getId()]);
        $both = $this->preset('Hiring', [(int) $from->getId(), (int) $into->getId()]);

        $this->tags->merge($from, $into, $this->kb->account->getName());

        self::assertSame(
            [(int) $into->getId()],
            $this->presetTags($only),
            'A filter left naming a tag that no longer exists filters on nothing and matches every note',
        );
        self::assertSame([(int) $into->getId()], $this->presetTags($both), 'No duplicate after the merge');
    }

    public function testRemovingATagTakesItOutOfSavedFilters(): void
    {
        $this->kb->note($this->writer, 'One', 'Body.', ['inbox', 'keep']);
        $inbox = $this->tag('inbox');
        $keep = $this->tag('keep');
        self::assertNotNull($inbox);
        self::assertNotNull($keep);
        $preset = $this->preset('Triage', [(int) $inbox->getId(), (int) $keep->getId()]);

        $this->tags->remove($inbox, $this->kb->account->getName());

        self::assertSame([(int) $keep->getId()], $this->presetTags($preset));
    }

    public function testATagASavedFilterNamesOutlivesItsLastNote(): void
    {
        $this->kb->note($this->writer, 'One', 'Body.', ['todo', 'scratch']);
        $todo = $this->tag('todo');
        self::assertNotNull($todo);
        $todoId = (int) $todo->getId();
        $preset = $this->preset('To do', [$todoId]);

        $this->em->getConnection()->executeStatement('DELETE FROM note_tag');
        $this->writer->gcTags();
        $this->em->clear();

        self::assertNotNull($this->tag('todo'), 'Collected, the filter would hold a dead id and match every note');
        self::assertSame([$todoId], $this->presetTags($preset));
        self::assertNull($this->tag('scratch'), 'A tag nothing names is still collected');
    }

    /** @param list<int> $tagIds */
    private function preset(string $name, array $tagIds): int
    {
        $preset = new SearchPreset($name);
        $preset->setCriteria('', $tagIds, '', null);
        $this->em->persist($preset);
        $this->em->flush();

        return (int) $preset->getId();
    }

    /** @return list<int> read from the database rather than the identity map */
    private function presetTags(int $presetId): array
    {
        $raw = $this->em->getConnection()->fetchOne('SELECT tag_ids FROM search_presets WHERE id = :id', ['id' => $presetId]);

        return json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);
    }

    private function tag(string $name): ?Tag
    {
        return $this->em->getRepository(Tag::class)->findOneBy(['name' => $name]);
    }

    /** @return string[] sorted, read from the database rather than the identity map */
    private function tagsOf(int $noteId): array
    {
        $names = $this->em->getConnection()->fetchFirstColumn(
            'SELECT t.name FROM tags t JOIN note_tag nt ON nt.tag_id = t.id WHERE nt.note_id = :id ORDER BY t.name',
            ['id' => $noteId],
        );

        return array_map('strval', $names);
    }

    private function version(int $noteId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT version FROM notes WHERE id = :id',
            ['id' => $noteId],
        );
    }
}
