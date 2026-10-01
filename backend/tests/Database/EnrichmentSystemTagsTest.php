<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;
use App\Entity\Tag;
use App\Entity\AiCredential;
use App\Entity\VaultSettings;
use App\Service\NoteEnricher;
use App\Service\SystemTags;
use App\Tests\Support\MockMlResponder;

/**
 * Enrichment may not decide what assistants are served.
 *
 * `skill` and `live-state` are the only two words the server itself branches
 * on: one publishes a note to every connected assistant as loadable
 * instructions, the other attaches a standing write-back instruction to every
 * retrieval. Adding either is a product decision, and a model is not the owner.
 *
 * Found in the operator's own knowledge base on 2026-08-28. He removed `skill`
 * from the project record and it came back inside the same request: the edit
 * cleared his tags and re-added the nine he kept, then enrichment read a
 * 20,000-character document that discusses skills constantly, the model picked
 * `skill` out of the vocabulary it had been handed, and `addTag()` restored it.
 * The note was published to every connected assistant again, silently, and
 * there was no way to remove it from the interface at all — every save put it
 * back.
 *
 * The existing rule was careful about the wrong half. Enrichment is additive so
 * that a tag somebody chose is never removed by a save; nobody had considered
 * that ADDING one of these two is itself the decision.
 */
final class EnrichmentSystemTagsTest extends ApiTestCase
{
    /**
     * A tag id the model was not offered never reaches the caller: the answer
     * carries only ids from the vocabulary it was shown, so a system tag the
     * model names anyway is not handed back as a suggestion.
     */
    public function testATagIdTheModelWasNotOfferedIsDropped(): void
    {
        $this->enableEnrichment();
        $skill = $this->tagId(SystemTags::SKILL);
        $ordinary = $this->tagId('invoices');
        $this->ml->suggestedTagIds = [$skill, $ordinary];

        $this->request('POST', '/api/notes', $this->kb->a->curatorBearer, [
            'title' => 'Invoice', 'body_md' => 'The invoice was paid.', 'summary' => 'Paid.', 'tags' => [],
        ]);
        self::assertSame(201, $this->httpStatus(), $this->body());
        $data = $this->jsonResponse();
        self::assertSame(['invoices'], array_column($data['note']['tags'], 'name'));
        self::assertSame([$ordinary], $data['suggested_tags']['tag_ids']);
    }

    /**
     * Text enrichment on, on the account's own key.
     *
     * Without it `MlClient` refuses every text call, `suggestTags` answers
     * nothing, and the whole tag branch is dead — a test that passes because
     * the code never ran, which is the shape this project has shipped eighteen
     * times.
     */
    private function enableEnrichment(): void
    {
        // A REALLY encrypted key. A plaintext one decrypts to null, and
        // `EnrichmentSettings` fails CLOSED on an unreadable key (audit M-8) —
        // so the fixture would look configured and text would be off.
        $cipher = self::getContainer()->get(\App\Service\CredentialCipher::class);
        $this->in($this->kb->a);
        $credential = new AiCredential(
            'openai', 'OpenAI', $cipher->encrypt('sk-test-key'), '...test',
        );
        $this->em->persist($credential);

        $settings = $this->em->getRepository(VaultSettings::class)->current();
        $settings->setCredential($credential);
        $settings->setModel('gpt-4o-mini');
        $settings->setAiEnabled(true);
        $this->em->flush();

        self::assertTrue(
            self::getContainer()->get(\App\Service\EnrichmentSettings::class)
                ->forVault()->textEnabled,
            'the fixture must actually switch text on, or every assertion below is vacuous',
        );
    }

    private function tagId(string $name): int
    {
        $this->in($this->kb->a);
        $tag = new Tag($name);
        $this->em->persist($tag);
        $this->em->flush();

        return $tag->getId();
    }

    private function noteWith(string $title, string $body): Note
    {
        $this->in($this->kb->a);
        $note = new Note(
            null,
            $title,
            $body,
            'web',
            null,
            Note::STATUS_VERIFIED,
        );
        $this->em->persist($note);
        $this->em->flush();

        return $note;
    }

    private function tagNamesOn(Note $note): array
    {
        $names = array_map(static fn (Tag $t) => $t->getName(), $note->getTags()->toArray());
        sort($names);

        return $names;
    }

    /**
     * The model names `skill` and it is refused — even though the row exists,
     * even though it is in this vault's own vocabulary.
     */
    public function testEnrichmentCannotAddASystemTag(): void
    {
        $this->enableEnrichment();
        $skill = $this->tagId(SystemTags::SKILL);
        $live = $this->tagId(SystemTags::LIVE_STATE);
        $ordinary = $this->tagId('infra');

        $ml = self::getContainer()->get(MockMlResponder::class);
        $ml->suggestedTagIds = [$skill, $live, $ordinary];

        $note = $this->noteWith('A note about skills and live state', 'Body about skills.');
        self::getContainer()->get(NoteEnricher::class)
            ->enrich($note, \App\Service\EmbeddingSpend::Metered);
        $this->em->flush();

        self::assertSame(['infra'], $this->tagNamesOn($note),
            'a model may file a note under the owner\'s own words, and these two are not words — '
            .'they change what every connected assistant is served');
    }

    /**
     * And it is not offered them either.
     *
     * Refusing at the application point alone would still put the two names in
     * front of the model on every enrichment call, which is a prompt inviting
     * exactly the answer that is then thrown away.
     */
    public function testTheModelIsNeverShownASystemTag(): void
    {
        $this->enableEnrichment();
        $this->tagId(SystemTags::SKILL);
        $this->tagId(SystemTags::LIVE_STATE);
        $this->tagId('infra');

        $ml = self::getContainer()->get(MockMlResponder::class);
        $ml->suggestedTagIds = [];
        $ml->calls = [];

        $note = $this->noteWith('Anything', 'Body.');
        self::getContainer()->get(NoteEnricher::class)
            ->enrich($note, \App\Service\EmbeddingSpend::Metered);

        $vocabCall = null;
        foreach ($ml->calls as $call) {
            if (str_contains($call['url'], '/api/v1/suggest-tags')) {
                $vocabCall = $call;
            }
        }
        self::assertNotNull($vocabCall, 'enrichment must have asked for tags at all');
        $offered = array_column($vocabCall['body']['vocabulary'] ?? $vocabCall['body']['tags'] ?? [], 'name');
        foreach (SystemTags::names() as $held) {
            self::assertNotContains($held, $offered,
                "$held was offered to the model — the refusal must not be the only guard");
        }
        self::assertContains('infra', $offered, 'ordinary words are still offered');
    }

    /**
     * And the server never SUGGESTS one either.
     *
     * `WriteHints` reports back on every MCP write and every editor draft
     * check: notes close by meaning, links the text implies, and vocabulary the
     * note is not filed under. A suggestion is a thing an agent may act on, and
     * a curator-role connection acts without review — so suggesting `skill`
     * puts the unattended grant back through a different door. Found by Codex
     * on 2026-08-28, after the enrichment half was already closed.
     */
    public function testWriteHintsNeverSuggestsASystemTag(): void
    {
        $this->tagId(SystemTags::SKILL);
        $this->tagId(SystemTags::LIVE_STATE);
        $this->tagId('gardening');

        $hints = self::getContainer()->get(\App\Service\WriteHints::class)->forDraft(
            'A note about a skill and live-state gardening',
            'This text mentions skill and live-state and gardening on purpose.',
            null,
        );

        $suggested = $hints['tags'] ?? [];
        self::assertContains('gardening', $suggested,
            'ordinary vocabulary is still suggested, or this test proves nothing');
        foreach (SystemTags::names() as $held) {
            self::assertNotContains($held, $suggested,
                "$held was suggested — a curator-role connection acting on it grants itself "
                .'exactly what enrichment was just stopped from granting');
        }
    }

    /**
     * The owner keeps theirs.
     *
     * The guard must not remove a system tag the person deliberately put on the
     * note; enrichment stays additive, it simply cannot add these two.
     */
    public function testASystemTagTheOwnerChoseSurvivesEnrichment(): void
    {
        $this->enableEnrichment();
        $skillId = $this->tagId(SystemTags::SKILL);
        $ordinary = $this->tagId('infra');

        $note = $this->noteWith('A real skill note', 'Follow these steps.');
        $note->addTag($this->em->getRepository(Tag::class)->find($skillId));
        $this->em->flush();

        $ml = self::getContainer()->get(MockMlResponder::class);
        $ml->suggestedTagIds = [$ordinary];

        self::getContainer()->get(NoteEnricher::class)
            ->enrich($note, \App\Service\EmbeddingSpend::Metered);
        $this->em->flush();

        self::assertSame(['infra', SystemTags::SKILL], $this->tagNamesOn($note),
            'enrichment is additive and must not strip what the owner chose');
    }
}
