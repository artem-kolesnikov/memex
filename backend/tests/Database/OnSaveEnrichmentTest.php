<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;
use App\Entity\Tag;
use App\Service\AiProviders;
use App\Service\EnrichmentSettings;
use App\Service\NoteWriter;
use App\Tests\Support\KbFixture;

/**
 * Enrichment happens ON SAVE, and tags are written rather than handed back.
 *
 * **The ruling (operator, 2026-08-23).** "If there is an API provided and
 * on-server enrichment is enabled, it should fire automatically on save —
 * summary, tags. No scheduling, no runs." The summary half had always worked
 * that way. The tag half had not: `NoteWriter` asked for suggestions on every
 * save, returned them as `suggested_tags`, and no view in the SPA had read
 * them since the Analyze card was removed. Every save bought an answer nobody
 * saw.
 *
 * **What is applied, and what is only offered.** Tags from the vault's own
 * vocabulary are applied; names the model invents are returned and nothing
 * writes them. That split is the whole of the ruling's second half, and it is
 * not fastidiousness: models reliably invent near-duplicates of words that are
 * already there, which is why `retired_tags` had to exist. A vocabulary that
 * grows by itself stops being one.
 */
final class OnSaveEnrichmentTest extends DatabaseTestCase
{
    private NoteWriter $writer;
    private KbFixture $kb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = self::getContainer()->get(NoteWriter::class);
        $this->kb = new KbFixture(self::getContainer());

        $settings = self::getContainer()->get(EnrichmentSettings::class);
        $key = $settings->saveKey(AiProviders::OPENAI, 'OpenAI', 'sk-valid-key')['credential'];
        $settings->save(enabled: true, credential: $key);
        $this->em->flush();
    }

    /** A tag in the vault's vocabulary, ready to be picked. */
    private function vocabulary(string ...$names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $tag = new Tag($name);
            $this->em->persist($tag);
            $this->em->flush();
            $ids[$name] = $tag->getId();
        }

        return $ids;
    }

    /**
     * A save through the real path.
     *
     * NOT `KbFixture::note()`, which opts out of tagging so that the hundreds
     * of notes the suite builds as scenery do not each buy a call. The thing
     * under test here IS what an ordinary save does, so it has to be one.
     *
     * @param string[] $tags
     * @return array{note: Note, suggestions: array{tag_ids: int[], new_tags: string[]}}
     */
    private function save(string $title, string $body, array $tags = []): array
    {
        return $this->writer->create(
            null, $title, $body, Note::SOURCE_MANUAL, null, $tags,
            \App\Service\EmbeddingSpend::Metered,
        );
    }

    /** @return string[] */
    private function tagNames(Note $note): array
    {
        $names = array_map(static fn (Tag $t) => $t->getName(), $note->getTags()->toArray());
        sort($names);

        return $names;
    }

    public function testSavingANoteFilesItUnderTheVocabularyItBelongsIn(): void
    {
        $vocab = $this->vocabulary('infra', 'postgres');
        $this->ml->suggestedTagIds = [$vocab['infra'], $vocab['postgres']];

        $note = $this->save('A note about the database', 'Body.')['note'];

        self::assertSame(['infra', 'postgres'], $this->tagNames($note), 'The save has to DO the tagging, not report it');
    }

    /**
     * The half that is deliberately not written. An invented name is returned
     * for somebody to accept, and a note that goes in untagged can come out
     * untagged rather than carrying a word no human has ever used.
     */
    public function testAnInventedNameIsOfferedAndNeverWritten(): void
    {
        $this->ml->suggestedNewTags = ['database-tuning'];

        $result = $this->save('A note', 'Body.');

        self::assertSame([], $this->tagNames($result['note']), 'A word the model made up must not file anybody\'s note');
        self::assertSame(['database-tuning'], $result['suggestions']['new_tags'], 'but it is offered');
        self::assertSame(
            0,
            (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM tags WHERE name = :n',
                ['n' => 'database-tuning'],
            ),
            'and the vocabulary did not grow behind the owner\'s back',
        );
    }

    /**
     * Additive, always. The model sees the whole vocabulary and not the reason
     * a note was filed the way it was, so a save may add a heading and may
     * never take one away.
     */
    public function testATagSomebodyChoseSurvivesTheSave(): void
    {
        $vocab = $this->vocabulary('infra');
        $this->ml->suggestedTagIds = [$vocab['infra']];

        $note = $this->save('A note', 'Body.', ['my-own-word'])['note'];

        self::assertSame(['infra', 'my-own-word'], $this->tagNames($note));
    }

    /** A name the owner retired is not offered back, on this path as on the others. */
    public function testARetiredNameIsNotOfferedBack(): void
    {
        $doomed = new Tag('inbox');
        $this->em->persist($doomed);
        $this->em->flush();
        self::getContainer()->get(\App\Service\TagAdmin::class)->remove($doomed, $this->kb->account->getName());
        $this->ml->suggestedNewTags = ['inbox'];

        $result = $this->save('A note', 'Body.');

        self::assertSame([], $result['suggestions']['new_tags']);
    }

    /**
     * The spend rule that replaced M-9. Tags are read out of the TEXT, so an
     * edit that leaves the text alone has nothing new to ask about — and an
     * edit is the commonest write in the system, so asking on every one of them
     * would buy the same answer for as long as the note existed.
     */
    public function testAnEditThatLeavesTheTextAloneBuysNoTags(): void
    {
        $note = $this->save('A note', 'Body.', ['kept'])['note'];
        $before = $this->ml->callCount('/suggest-tags');

        $this->writer->update($note, 'A better title', null, null, \App\Service\EmbeddingSpend::Metered);

        self::assertSame($before, $this->ml->callCount('/suggest-tags'), 'A retitle re-read text nobody changed');
    }

    public function testAnEditThatChangesTheTextDoesReadTagsAgain(): void
    {
        $vocab = $this->vocabulary('infra');
        $note = $this->save('A note', 'Body.', ['kept'])['note'];
        $this->ml->suggestedTagIds = [$vocab['infra']];
        $before = $this->ml->callCount('/suggest-tags');

        $this->writer->update($note, null, 'An entirely different body about servers.', null, \App\Service\EmbeddingSpend::Metered);

        self::assertSame($before + 1, $this->ml->callCount('/suggest-tags'));
        self::assertSame(['infra', 'kept'], $this->tagNames($note), 'and what it found was filed');
    }

    /**
     * The §Spend rule at its sharpest: a team that has not switched server-side
     * text on gets no calls at all, and its notes stay exactly as written.
     */
    public function testATeamThatPaysForNothingIsChargedNothing(): void
    {
        self::getContainer()->get(EnrichmentSettings::class)->save(enabled: false);
        $this->em->flush();
        $this->ml->suggestedTagIds = [1];
        $before = $this->ml->callCount('/suggest-tags');

        $note = $this->save('A note', 'Body.')['note'];

        self::assertSame($before, $this->ml->callCount('/suggest-tags'));
        self::assertSame([], $this->tagNames($note));
    }
}
