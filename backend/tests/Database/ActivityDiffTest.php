<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Controller\CuratorLogController;
use App\Entity\CuratorLogEntry;
use App\Entity\EditProposal;
use App\Entity\Note;
use App\Service\NoteWriter;
use App\Tests\Support\KbFixture;

/**
 * What an expanded Activity Journal row shows, for an edit written as an
 * anchored patch.
 *
 * The defect this pins (2026-08-23: newer entries showed "(show diff)",
 * and it was empty): a patch is stored UNRESOLVED, on purpose, so two
 * held edits cannot destroy each other. Applying it resolved the anchors
 * against the note and wrote the result into the note — and nowhere else. The
 * proposal row, which is the audit record the journal reads, kept a null body,
 * a null title, null tags and a null summary. So the row offered a diff and
 * expanded to blank.
 *
 * Re-resolving the patch at READ time is not the fix and is the reason the
 * view passes `proposed_patch: null`: it would resolve against the note as it
 * stands today, which is not what was applied. The resolution has to be
 * written down once, at apply time.
 *
 * Curator auto-apply is the path under test because it is the only apply path
 * that KEEPS its proposal row — an operator's approval removes it, so those
 * rows carry no diff at all and never offered one.
 */
final class ActivityDiffTest extends DatabaseTestCase
{
    private NoteWriter $writer;
    private KbFixture $kb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = self::getContainer()->get(NoteWriter::class);
        $this->kb = new KbFixture(self::getContainer());
    }

    /** The mechanism: an applied patch records what it resolved to. */
    public function testAnAppliedPatchRecordsTheBodyItProduced(): void
    {
        $note = $this->kb->note($this->writer, 'Record', "Alpha stands.\n\nBravo stands.", []);

        $op = $this->patchEdit($note);
        self::assertTrue($op->isApplied(), 'A curator connection\'s anchored edit applies at once');
        self::assertSame("Alpha has moved.\n\nBravo stands.", $note->getBodyMd());

        self::assertSame(
            "Alpha has moved.\n\nBravo stands.",
            $op->getProposedBodyMd(),
            'The audit row has to say what was applied, not only which anchors were named',
        );
        self::assertSame(
            "Alpha stands.\n\nBravo stands.",
            $op->getPrevBodyMd(),
            'The other half of the diff: the body it replaced',
        );
        self::assertNotNull($op->getProposedPatch(), 'The operations are kept beside the result, not replaced by it');
    }

    /** The symptom: the row the operator clicks now has something to render. */
    public function testTheJournalRowForAPatchEditCarriesADiff(): void
    {
        $note = $this->kb->note($this->writer, 'Record', "Alpha stands.\n\nBravo stands.", []);

        $this->patchEdit($note);

        $row = $this->journalRow(CuratorLogEntry::ACTION_EDIT);

        self::assertNotNull($row['diff'], 'An edit that changed the body must expand to that change');
        self::assertSame("Alpha has moved.\n\nBravo stands.", $row['diff']['proposed_body_md']);
        self::assertSame("Alpha stands.\n\nBravo stands.", $row['diff']['prev_body_md']);
    }

    /**
     * The rows already written this way cannot be repaired — the body they
     * produced was never stored and cannot be recovered, because the note has
     * moved on since. What they must not do is keep promising a diff.
     */
    public function testARowWithNothingToShowOffersNoDiffAtAll(): void
    {
        $note = $this->kb->note($this->writer, 'Record', "Alpha stands.\n\nBravo stands.", []);

        $op = $this->patchEdit($note);

        // Exactly the shape every row written before this fix has: applied,
        // and holding no rendered before/after of any kind.
        $this->em->getConnection()->executeStatement(
            'UPDATE edit_proposals SET proposed_body_md = NULL WHERE id = :id',
            ['id' => $op->getId()],
        );
        $this->em->clear();

        self::assertNull(
            $this->journalRow(CuratorLogEntry::ACTION_EDIT)['diff'],
            'A row that cannot show a diff must not offer one',
        );
    }

    /** A summary-only edit still expands: it is the enrichment case. */
    public function testASummaryOnlyEditStillCarriesADiff(): void
    {
        $note = $this->kb->note($this->writer, 'Record', 'Body.', []);

        $this->writer->propose(
            $note,
            $this->kb->curatorToken,
            null,
            null,
            null,
            summary: 'What this note is.',
        );

        $row = $this->journalRow(CuratorLogEntry::ACTION_EDIT);
        self::assertNotNull($row['diff']);
        self::assertSame('What this note is.', $row['diff']['proposed_summary']);
    }

    /** The write under test: a curator's anchored edit, which applies at once. */
    private function patchEdit(Note $note): EditProposal
    {
        return $this->writer->propose(
            $note,
            $this->kb->curatorToken,
            null,
            null,
            null,
            patch: [['find' => 'Alpha stands.', 'replace' => 'Alpha has moved.']],
        );
    }

    /** The serialised row the SPA reads, for the newest entry of one action. */
    private function journalRow(string $action): array
    {
        $entry = $this->em->getRepository(CuratorLogEntry::class)->findOneBy(
            ['action' => $action],
            ['id' => 'DESC'],
        );
        self::assertNotNull($entry, 'Expected a journal row for '.$action);

        $toArray = (new \ReflectionClass(CuratorLogController::class))->getMethod('entryToArray');
        $toArray->setAccessible(true);

        return $toArray->invoke(
            (new \ReflectionClass(CuratorLogController::class))->newInstanceWithoutConstructor(),
            $entry,
            [],
            'Owner',
        );
    }
}
