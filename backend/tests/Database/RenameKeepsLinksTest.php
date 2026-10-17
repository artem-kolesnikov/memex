<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;
use App\Service\FormerTitles;
use App\Service\NoteLimbo;
use App\Service\NoteRevisions;
use App\Service\NoteWriter;
use App\Service\ReviewVerdicts;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A rename keeps the links written under the old title (operator, 2026-10-17):
 * the note answers to its former titles after every name it answers to now,
 * so no linking note is rewritten and nothing waits for review.
 */
final class RenameKeepsLinksTest extends ApiTestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    /** @return array<string, int|null> raw target => resolved note id */
    private function linksOf(int $noteId): array
    {
        $this->in($this->kb->a);
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT raw_target, to_note_id FROM note_links WHERE from_note_id = :id',
            ['id' => $noteId]
        );
        $links = [];
        foreach ($rows as $row) {
            $links[$row['raw_target']] = $row['to_note_id'] === null ? null : (int) $row['to_note_id'];
        }

        return $links;
    }

    private function rename(Note $note, string $title): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$note->getId(), ['title' => $title]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $this->in($this->kb->a);
    }

    private function edit(Note $note, string $body): void
    {
        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$note->getId(), ['body_md' => $body]);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $this->in($this->kb->a);
    }

    /** @return list<string> */
    private function formerTitles(Note $note): array
    {
        $this->in($this->kb->a);

        return self::getContainer()->get(FormerTitles::class)->of((int) $note->getId());
    }

    public function testALinkUnderTheOldTitleKeepsItsNoteWhenTheLinkingNoteIsEditedLater(): void
    {
        $plan = $this->kb->a->note('Garden plan', 'Beds and paths.');
        $index = $this->kb->a->note('Index', 'See [[Garden plan]].');

        $this->rename($plan, 'Garden plan — 2027');
        self::assertSame(['Garden plan' => $plan->getId()], $this->linksOf((int) $index->getId()));

        // An edit rebuilds the linking note's links from its text, which is
        // where a redirect kept only in note_links would be lost.
        $this->edit($index, 'See [[Garden plan]] again.');
        self::assertSame(['Garden plan' => $plan->getId()], $this->linksOf((int) $index->getId()));
        self::assertSame(['Garden plan'], $this->formerTitles($plan));
    }

    public function testANoteCreatedLaterUnderTheOldTitleTakesItsLinks(): void
    {
        $plan = $this->kb->a->note('Garden plan', 'Beds and paths.');
        $index = $this->kb->a->note('Index', 'See [[Garden plan]].');
        $this->rename($plan, 'Garden plan — 2027');

        $successor = $this->kb->a->note('Garden plan', 'The plan as it stands now.');

        self::assertSame(
            ['Garden plan' => $successor->getId()],
            $this->linksOf((int) $index->getId()),
            'a note that answers to a title now outranks one that used to'
        );
    }

    public function testTheNewestRenameWinsBetweenTwoNotesThatOnceHeldATitle(): void
    {
        $first = $this->kb->a->note('Budget', 'First.');
        $index = $this->kb->a->note('Index', 'See [[Budget]].');
        $this->rename($first, 'Budget 2026');
        $second = $this->kb->a->note('Budget', 'Second.');
        $this->rename($second, 'Budget 2027');

        self::assertSame(['Budget' => $second->getId()], $this->linksOf((int) $index->getId()));
    }

    public function testRenamingBackLeavesNoFormerTitleNamingTheCurrentOne(): void
    {
        $note = $this->kb->a->note('Alpha', 'Body.');
        $this->rename($note, 'Beta');
        $this->rename($note, 'Alpha');

        self::assertSame(['Beta'], $this->formerTitles($note));
    }

    public function testACaseOnlyRenameRecordsNothing(): void
    {
        $note = $this->kb->a->note('kitchen refit', 'Body.');
        $this->rename($note, 'Kitchen refit');

        self::assertSame([], $this->formerTitles($note));
    }

    public function testAMergeRedirectSurvivesAnEditOfTheLinkingNote(): void
    {
        $keeper = $this->kb->a->note('Kitchen refit — spec and budget', 'The keeper.');
        $absorbed = $this->kb->a->note('Kitchen notes', 'The duplicate.');
        $referrer = $this->kb->a->note('Quotes', 'Per [[Kitchen notes]].');

        $writer = self::getContainer()->get(NoteWriter::class);
        $proposal = $writer->proposeMerge($absorbed, $keeper, $this->kb->a->agentToken(), null, 'The same kitchen, twice.');
        self::getContainer()->get(ReviewVerdicts::class)->approveProposal($proposal, ['comment' => null, 'precedent' => false], [
            'expected_revision' => $proposal->getRevision(),
            'expected_version' => $proposal->getNote()->getVersion(),
            'expected_merge_version' => $proposal->getMergeIntoNote()->getVersion(),
        ]);

        $this->in($this->kb->a);
        $referrer = $this->em->find(Note::class, $referrer->getId());
        $this->edit($referrer, 'Per [[Kitchen notes]], as quoted.');

        self::assertSame(['Kitchen notes' => $keeper->getId()], $this->linksOf((int) $referrer->getId()));
    }

    public function testFormerTitlesComeBackWithARestoredNote(): void
    {
        $plan = $this->kb->a->note('Garden plan', 'Beds and paths.');
        $index = $this->kb->a->note('Index', 'See [[Garden plan]].');
        $this->rename($plan, 'Garden plan — 2027');

        $limbo = self::getContainer()->get(NoteLimbo::class);
        $limbo->retire($this->em->find(Note::class, $plan->getId()), 'operator');
        self::assertSame(['Garden plan' => null], $this->linksOf((int) $index->getId()));

        $limbo->restore((int) $plan->getId());
        self::assertSame(['Garden plan' => $plan->getId()], $this->linksOf((int) $index->getId()));
    }

    public function testForgettingANotesHistoryForgetsItsFormerTitles(): void
    {
        $plan = $this->kb->a->note('Garden plan', 'Beds and paths.');
        $this->rename($plan, 'Garden plan — 2027');
        self::assertSame(['Garden plan'], $this->formerTitles($plan));

        $index = $this->kb->a->note('Index', 'See [[Garden plan]].');
        self::assertSame(['Garden plan' => $plan->getId()], $this->linksOf((int) $index->getId()));

        self::getContainer()->get(NoteRevisions::class)->forget((int) $plan->getId());

        self::assertSame([], $this->formerTitles($plan));
        self::assertSame(['Garden plan' => null], $this->linksOf((int) $index->getId()), 'a link may not keep reaching a note by a title it forgot');
    }

    public function testAnExportCarriesFormerTitlesAsAliases(): void
    {
        $plan = $this->kb->a->note('Garden plan', 'Beds and paths.');
        $this->rename($plan, 'Garden plan — 2027');

        $this->loginAs($this->kb->a);
        $this->client->request('GET', '/api/notes/'.$plan->getId().'/export');
        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertStringContainsString("\naliases: [\"Garden plan\"]\n", $this->body());
    }

    public function testAnImportedAliasAnswersTheLinksThatNameIt(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'aliases-zip-');
        $this->paths[] = $path;
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
        // The linking note first, so the alias has to answer a link that was
        // already written down when its note arrived.
        $zip->addFromString('A index.md', "See [[Old garden plan]].\n");
        $zip->addFromString('B plan.md', "---\ntitle: Garden plan\naliases: [Old garden plan]\n---\nBeds and paths.\n");
        self::assertTrue($zip->close());

        $this->loginAs($this->kb->a);
        $this->client->request('POST', '/api/import', files: ['archive' => new UploadedFile($path, 'vault.zip', 'application/zip', test: true)]);
        self::assertSame(201, $this->httpStatus(), $this->body());

        $this->in($this->kb->a);
        $ids = $this->em->getConnection()->fetchAllKeyValue("SELECT title, id FROM notes WHERE title IN ('A index', 'Garden plan')");
        self::assertSame(['Old garden plan' => (int) $ids['Garden plan']], $this->linksOf((int) $ids['A index']));
    }
}
