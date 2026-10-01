<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\CurationCharter;
use App\Entity\Note;
use App\Tests\Support\Tenant;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Retiring the knowledge-base copies of the charter the desk replaces.
 *
 * It had no test at all, which Codex ranked second on review — the command
 * could have been deleted, or its slug match inverted so it retired everything
 * EXCEPT the charter, and nothing would have failed. It is also the only
 * destructive thing in this change: the migration deliberately touches no note.
 *
 * The property that matters most is the one it did NOT have when it was
 * written: a note is retired for what its BODY is, not for what it is called.
 * `SkillLibrary` is non-destructive about a title collision — it suffixes the
 * note and keeps serving it — so retiring on the slug alone destroyed a note
 * the runtime was perfectly happy to keep.
 */
final class RetireCharterNotesTest extends ApiTestCase
{
    private const CHARTER_BODY = "> **You are the Curator** — the resident librarian of this knowledge base.\n\n# Mission\n\nMake it trustworthy.\n";

    private function retire(bool $dryRun = false): string
    {
        $this->leave();
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:retire-charter-notes'));
        $tester->execute($dryRun ? ['--dry-run' => true] : []);

        return $tester->getDisplay();
    }

    private function isLive(Tenant $tenant, int $id): bool
    {
        $this->in($tenant);

        return (bool) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM notes WHERE id = :id',
            ['id' => $id],
        );
    }

    private function isRetired(Tenant $tenant, int $id): bool
    {
        $this->in($tenant);

        return (bool) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM deleted_notes WHERE id = :id',
            ['id' => $id],
        );
    }

    public function testACharterCopyIsRetiredAndRestorable(): void
    {
        $note = $this->kb->a->note('memex — curation', self::CHARTER_BODY, ['skill'], 'The charter.');
        $id = (int) $note->getId();

        $this->retire();

        self::assertFalse($this->isLive($this->kb->a, $id), 'the stale copy is still being served');
        // Retired, not destroyed: it is the only copy of whatever that
        // knowledge base edited into it.
        self::assertTrue($this->isRetired($this->kb->a, $id), 'it was removed without going through limbo');
    }

    /**
     * The defect the body check exists to stop. Anyone whose own `skill` note
     * happens to be titled like the charter would have lost it.
     */
    public function testANoteMerelyTitledLikeTheCharterIsLeftAlone(): void
    {
        $mine = $this->kb->a->note(
            'Memex Curation',
            "My own notes about how I like curation done here.\n",
            ['skill'],
            'Mine.',
        );
        $id = (int) $mine->getId();

        $output = $this->retire();

        self::assertTrue($this->isLive($this->kb->a, $id), 'a note that is not a charter copy was retired');
        self::assertStringContainsString('leaving note '.$id.' "Memex Curation" ('.$this->kb->a->handle().')', $output,
            'it must say what it declined to take, or a surprising no-op looks like a bug');
    }

    public function testOtherSkillsAndOtherTeamsAreUntouched(): void
    {
        $recall = $this->kb->a->note('memex-recall — using the shared memory', 'How to recall.', ['skill'], 'Recall.');
        $theirs = $this->kb->b->note('memex — curation', self::CHARTER_BODY, ['skill'], 'Their charter.');
        $mineToo = $this->kb->a->note('memex — curation', self::CHARTER_BODY, ['skill'], 'My charter.');

        $this->retire();

        self::assertTrue($this->isLive($this->kb->a, (int) $recall->getId()), 'an unrelated skill was retired');
        // Every vault's copy goes: the command runs once for the whole box.
        self::assertFalse($this->isLive($this->kb->b, (int) $theirs->getId()));
        self::assertFalse($this->isLive($this->kb->a, (int) $mineToo->getId()));
    }

    public function testDryRunChangesNothingAndSaysWhatItWould(): void
    {
        $note = $this->kb->a->note('memex — curation', self::CHARTER_BODY, ['skill'], 'The charter.');
        $id = (int) $note->getId();

        $output = $this->retire(dryRun: true);

        self::assertTrue($this->isLive($this->kb->a, $id), '--dry-run retired a note');
        self::assertStringContainsString('would retire', $output);
    }

    /** It runs on every deploy; doing nothing must be the normal outcome. */
    public function testRunningItTwiceIsSafe(): void
    {
        $this->kb->a->note('memex — curation', self::CHARTER_BODY, ['skill'], 'The charter.');

        $this->retire();
        $output = $this->retire();

        self::assertStringContainsString('No charter notes found', $output);
    }

    /** A pending copy is not served, so it is not ours to retire. */
    public function testAPendingNoteIsNotRetired(): void
    {
        $note = $this->kb->a->note('memex — curation', self::CHARTER_BODY, ['skill'], 'Proposed.');
        $this->em->getConnection()->executeStatement(
            'UPDATE notes SET status = :status WHERE id = :id',
            ['status' => Note::STATUS_PENDING, 'id' => $note->getId()],
        );

        $this->retire();

        self::assertTrue($this->isLive($this->kb->a, (int) $note->getId()));
    }

    public function testTheMarkerIsStillTheShippedCanonsOpeningLine(): void
    {
        $canon = (string) file_get_contents(__DIR__.'/../../config/curation/canon.md');

        // If the canon is ever reworded past this line, the command silently
        // stops recognising the copies it exists to retire — and a silent
        // no-op is indistinguishable from a clean run.
        self::assertStringContainsString(CurationCharter::CHARTER_MARKER, $canon,
            'the marker is no longer in the shipped canon, so no copy of it will be recognised');
    }
}
