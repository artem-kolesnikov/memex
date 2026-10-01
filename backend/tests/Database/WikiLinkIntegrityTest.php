<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;
use App\Service\NoteEnricher;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Whether a wiki-link points where it says it points.
 *
 * Both failures here are silent, which is what makes them worth pinning: the
 * link renders, the reader sees a link, and nothing anywhere reports that it
 * leads to the wrong note or to none. Five dead targets accumulated in this
 * knowledge base over two days before a curation pass noticed them by eye.
 */
final class WikiLinkIntegrityTest extends ApiTestCase
{
    private function setImportPath(int $noteId, string $path): void
    {
        self::getContainer()->get('doctrine')->getConnection()->executeStatement(
            'UPDATE notes SET import_path = :p WHERE id = :id',
            ['p' => $path, 'id' => $noteId]
        );
    }

    /** @return array<string, int|null> raw target => resolved note id */
    private function linksOf(int $noteId): array
    {
        $rows = self::getContainer()->get('doctrine')->getConnection()->fetchAllAssociative(
            'SELECT raw_target, to_note_id FROM note_links WHERE from_note_id = :id',
            ['id' => $noteId]
        );

        $links = [];
        foreach ($rows as $row) {
            $links[$row['raw_target']] = $row['to_note_id'] === null ? null : (int) $row['to_note_id'];
        }

        return $links;
    }

    public static function resolutionPaths(): iterable
    {
        yield 'initial resolution' => ['initial'];
        yield 'catch-up' => ['catch-up'];
        yield 'relink unresolved' => ['relink'];
    }

    private function assertResolution(string $path, string $target, Note $trigger, Note $expected): void
    {
        $linking = $this->kb->a->note('Link source', $path === 'initial' ? '[['.$target.']]' : 'Waiting.');
        if ($path !== 'initial') {
            $this->em->getConnection()->executeStatement(
                'INSERT INTO note_links (from_note_id, raw_target) VALUES (:from, :target)',
                ['from' => $linking->getId(), 'target' => $target]
            );
            self::assertSame([$target => null], $this->linksOf($linking->getId()));
            $enricher = self::getContainer()->get(NoteEnricher::class);
            if ($path === 'catch-up') {
                $enricher->syncLinks($trigger);
            } else {
                self::assertSame(1, $enricher->relinkUnresolved());
            }
        }

        self::assertSame([$target => $expected->getId()], $this->linksOf($linking->getId()));
    }

    public function testBacklinksAreListedInTitleOrderWhateverTheCase(): void
    {
        $target = $this->kb->a->note('Target', 'Cited.');
        $this->kb->a->note('banana', 'See [[Target]].');
        $this->kb->a->note('Cherry', 'See [[Target]].');
        $this->kb->a->note('apple', 'See [[Target]].');

        $this->request('GET', '/api/notes/'.$target->getId(), $this->kb->a->agentBearer);

        self::assertSame(['apple', 'banana', 'Cherry'], array_column($this->jsonResponse()['backlinks'], 'title'));
    }

    #[DataProvider('resolutionPaths')]
    public function testExactBasenameBeatsUnwrappedTitle(string $path): void
    {
        $unwrapped = $this->kb->a->note('dir/Run book');
        $exact = $this->kb->a->note('Run  book');

        $this->assertResolution($path, 'dir/Run  book', $unwrapped, $exact);
    }

    #[DataProvider('resolutionPaths')]
    public function testExactImportPathBeatsUnwrappedTitleWithNullImportPath(string $path): void
    {
        $unwrapped = $this->kb->a->note('dir/Run book');
        $exact = $this->kb->a->note('Operations');
        $this->setImportPath($exact->getId(), 'dir/Run  book');

        $this->assertResolution($path, 'dir/Run  book', $unwrapped, $exact);
    }

    #[DataProvider('resolutionPaths')]
    public function testUnwrappedImportPathSuffixResolves(string $path): void
    {
        $target = $this->kb->a->note('Operations');
        $target->setImportPath('docs/The runbook');
        $this->em->flush();

        $this->assertResolution($path, "The\n  runbook", $target, $target);
    }

    #[DataProvider('resolutionPaths')]
    public function testUnwrappedBasenameResolves(string $path): void
    {
        $target = $this->kb->a->note('The runbook');

        $this->assertResolution($path, "docs/The\n  runbook", $target, $target);
    }

    #[DataProvider('resolutionPaths')]
    public function testExactDoubleSpaceTitleWinsInEveryResolutionPath(string $path): void
    {
        $unwrapped = $this->kb->a->note('Damp survey');
        $exact = $this->kb->a->note('Damp  survey');

        $this->assertResolution($path, 'Damp  survey', $unwrapped, $exact);
    }

    #[DataProvider('resolutionPaths')]
    public function testResolutionDoesNotCollapseNonBreakingSpaces(string $path): void
    {
        $this->kb->a->note('Damp survey');
        $target = $this->kb->a->note("Damp\u{00a0} survey");

        $this->assertResolution($path, "Damp\u{00a0}\n  survey", $target, $target);
    }

    #[DataProvider('resolutionPaths')]
    public function testResolutionStaysWithinTheSourceTeam(string $path): void
    {
        $this->kb->b->note("The\n  runbook");
        $foreignSource = $this->kb->b->note('Foreign source', '[[docs/The runbook]]');
        $target = $this->kb->a->note('The runbook');

        $this->assertResolution($path, "The\n  runbook", $target, $target);
        $this->in($this->kb->b);
        self::assertSame(['docs/The runbook' => null], $this->linksOf($foreignSource->getId()));
    }

    public function testATargetBrokenOverALineWrapStillNamesItsNote(): void
    {
        $target = $this->kb->a->note('memex.tools — positioning: category, audience, and the claims that follow');
        $linking = $this->kb->a->note(
            'The claims',
            "Read\n[[memex.tools — positioning: category, audience, and the claims that\n  follow]] before writing anything a stranger reads."
        );

        self::assertSame(
            [$target->getId()],
            array_values($this->linksOf($linking->getId())),
            'a target carrying a newline and the next line\'s indentation resolved to nothing'
        );
    }

    public function testRenamingANoteRepointsTheLinksThatNamedItsOldTitle(): void
    {
        $mm2 = $this->kb->a->note('projects/mm2/_index', 'The MM2 project index.');
        $index = $this->kb->a->note('Projects', 'See [[projects/mm2/_index]].');

        self::assertSame([$mm2->getTitle() => $mm2->getId()], $this->linksOf($index->getId()));

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$mm2->getId(), ['title' => 'MM2 — project record']);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $this->in($this->kb->a);

        self::assertSame(
            ['projects/mm2/_index' => null],
            $this->linksOf($index->getId()),
            'the link still points at a note that no longer answers to the name it uses'
        );
    }

    public function testAWrappedTargetResolvesWhenItsNoteArrivesLater(): void
    {
        $linking = $this->kb->a->note(
            'Written first',
            "Waiting on [[The operations\n  runbook]] to exist."
        );
        self::assertSame([null], array_values($this->linksOf($linking->getId())));

        $later = $this->kb->a->note('The operations runbook', 'Here at last.');

        self::assertSame(
            [$later->getId()],
            array_values($this->linksOf($linking->getId())),
            'a wrapped target written before its note existed was never repaired by the catch-up'
        );
    }

    public function testARenameIsClaimedByAWrappedTargetToo(): void
    {
        $elsewhere = $this->kb->a->note('Operations', 'Reached by its path.');
        $this->setImportPath($elsewhere->getId(), 'docs/The runbook');
        $draft = $this->kb->a->note('Draft note', 'Not yet named.');
        $referrer = $this->kb->a->note('Index page', "See [[The\n  runbook]].");
        self::assertSame([$elsewhere->getId()], array_values($this->linksOf($referrer->getId())));

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$draft->getId(), ['title' => 'The runbook']);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $this->in($this->kb->a);

        self::assertSame(
            [$draft->getId()],
            array_values($this->linksOf($referrer->getId())),
            'the rename was not noticed by a link whose target is only wrapped'
        );
    }

    public function testATitleSpelledWithADoubleSpaceIsStillMatchedExactly(): void
    {
        $exact = $this->kb->a->note('Damp  survey', 'Two spaces, on purpose.');
        $competing = $this->kb->a->note('Damp survey', 'One space, a different note.');
        $linking = $this->kb->a->note('The file', 'See [[Damp  survey]].');

        self::assertNotSame($exact->getId(), $competing->getId());

        self::assertSame(
            ['Damp  survey' => $exact->getId()],
            $this->linksOf($linking->getId()),
            'repairing line wraps cost a title that is genuinely spelled with a double space'
        );
    }

    public function testAnUnrelatedRenameLeavesAMergeRedirectAlone(): void
    {
        $keeper = $this->kb->a->note('Kitchen refit — spec and budget', 'The keeper.');
        $absorbed = $this->kb->a->note('Kitchen notes', 'The duplicate.');
        $hub = $this->kb->a->note('Bramble Lane', 'The house file.');
        $referrer = $this->kb->a->note('Quotes', 'Per [[Kitchen notes]] and [[Bramble Lane]].');

        $writer = self::getContainer()->get(\App\Service\NoteWriter::class);
        $verdicts = self::getContainer()->get(\App\Service\ReviewVerdicts::class);
        $proposal = $writer->proposeMerge(
            $absorbed,
            $keeper,
            $this->kb->a->agentToken(),
            null,
            'The same kitchen, twice.'
        );
        $verdicts->approveProposal($proposal, ['comment' => null, 'precedent' => false], [
            'expected_revision' => $proposal->getRevision(),
            'expected_version' => $proposal->getNote()->getVersion(),
            'expected_merge_version' => $proposal->getMergeIntoNote()->getVersion(),
        ]);

        $this->loginAs($this->kb->a);
        $this->in($this->kb->a);

        self::assertSame(
            $keeper->getId(),
            $this->linksOf($referrer->getId())['Kitchen notes'] ?? null,
            'the merge did not leave the redirect this test is about'
        );

        $this->sessionRequest('PUT', '/api/notes/'.$hub->getId(), ['title' => 'Bramble Lane — the house file']);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $this->in($this->kb->a);

        self::assertSame(
            $keeper->getId(),
            $this->linksOf($referrer->getId())['Kitchen notes'] ?? null,
            'renaming an unrelated note discarded a merge redirect on a link nobody touched'
        );
        $after = $this->linksOf($referrer->getId());
        self::assertArrayHasKey('Bramble Lane', $after);
        self::assertNull(
            $after['Bramble Lane'],
            'the rename itself was not applied to the link that names the old title'
        );
    }

    public function testARenameClaimsALinkThatResolvedElsewhereByPath(): void
    {
        $operations = $this->kb->a->note('Operations', 'Reached by its path.');
        $this->setImportPath($operations->getId(), 'docs/Runbook');

        $draft = $this->kb->a->note('Draft', 'Not yet named.');
        $referrer = $this->kb->a->note('Index', 'See [[Runbook]].');
        self::assertSame(['Runbook' => $operations->getId()], $this->linksOf($referrer->getId()));

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$draft->getId(), ['title' => 'Runbook']);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $this->in($this->kb->a);

        self::assertSame(
            ['Runbook' => $draft->getId()],
            $this->linksOf($referrer->getId()),
            'an exact title now answers this target, and the link still points at the path match'
        );
    }

    public function testARenameResolvesTheLinksTheNewTitleNowAnswers(): void
    {
        $waiting = $this->kb->a->note('Elsewhere', 'One day this will reach [[The runbook]].');
        self::assertSame(['The runbook' => null], $this->linksOf($waiting->getId()));

        $note = $this->kb->a->note('Hermes VM — operations runbook', 'How the box is run.');

        $this->loginAs($this->kb->a);
        $this->sessionRequest('PUT', '/api/notes/'.$note->getId(), ['title' => 'The runbook']);
        self::assertSame(200, $this->httpStatus(), $this->body());
        $this->in($this->kb->a);

        self::assertSame(
            ['The runbook' => $note->getId()],
            $this->linksOf($waiting->getId()),
            'a rename that makes a dangling target resolvable left it dangling'
        );
    }
}
