<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\EditProposal;
use App\Service\AiProviders;
use App\Service\EnrichmentSettings;
use App\Service\NoteWriter;
use App\Service\ReviewKinds;
use App\Service\ReviewVerdicts;
use App\Tests\Support\KbFixture;

/**
 * An agent that knows a note is stale but not what replaces it.
 *
 * `live_state_notice` told every agent on all 25 live-state notes to send
 * `propose(note_id, comment:)` with no body as a staleness report, and the
 * server refused it: the one path offered to an agent that knows a note is
 * wrong did not exist. The fix is to accept it as a REPORT rather than as an
 * empty edit, because approving an empty edit would report success and change
 * nothing — which {@see NoteWriter::resolveBodyForApply()} calls the worst
 * available outcome.
 */
final class StalenessReportTest extends DatabaseTestCase
{
    private NoteWriter $writer;
    private ReviewVerdicts $verdicts;
    private KbFixture $kb;

    private const VERDICT = ['comment' => null, 'precedent' => false];

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = self::getContainer()->get(NoteWriter::class);
        $this->verdicts = self::getContainer()->get(ReviewVerdicts::class);
        $this->kb = new KbFixture(self::getContainer());
    }

    public function testACommentOnlyProposalIsAcceptedAsAReport(): void
    {
        $note = $this->kb->note($this->writer, 'The runbook', 'Deploys go through Jenkins.');

        $proposal = $this->writer->propose(
            $note, $this->kb->agentToken,
            null, null, null, 'Jenkins was retired months ago; I do not know what replaced it.',
        );

        self::assertSame(EditProposal::TYPE_REPORT, $proposal->getType());
        self::assertSame(
            'Jenkins was retired months ago; I do not know what replaced it.',
            $proposal->getComment(),
        );
    }

    public function testAReportProposesNoChangeToTheNote(): void
    {
        $note = $this->kb->note($this->writer, 'The runbook', 'Deploys go through Jenkins.');

        $proposal = $this->writer->propose(
            $note, $this->kb->agentToken,
            null, null, null, 'This is stale.',
        );

        self::assertNull($proposal->getProposedTitle());
        self::assertNull($proposal->getProposedBodyMd());
        self::assertNull($proposal->getProposedTags());
        self::assertNull($proposal->getProposedSummary());
        self::assertNull($proposal->getProposedPatch());
    }

    public function testAcknowledgingAReportLeavesTheNoteExactlyAsItWas(): void
    {
        $note = $this->kb->note($this->writer, 'The runbook', 'Deploys go through Jenkins.');
        $before = $note->getBodyMd();
        $titleBefore = $note->getTitle();

        $proposal = $this->writer->propose(
            $note, $this->kb->agentToken,
            null, null, null, 'This is stale.',
        );
        $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));
        $this->em->refresh($note);

        self::assertSame($before, $note->getBodyMd(), 'Acknowledging a report must not touch the body');
        self::assertSame($titleBefore, $note->getTitle(), 'Acknowledging a report must not touch the title');
    }

    public function testAcknowledgingAReportWritesNoRevision(): void
    {
        // The assertion that separates a report from an empty edit. Routing a
        // report through the edit path also leaves the body alone, so "the note
        // is unchanged" passes either way — but the edit path records a
        // NoteRevision, and a version of the note saying somebody thought it
        // was wrong is not a version of the note.
        $note = $this->kb->note($this->writer, 'The runbook', 'Deploys go through Jenkins.');
        $before = $this->revisionsOf($note);

        $proposal = $this->writer->propose(
            $note, $this->kb->agentToken,
            null, null, null, 'This is stale.',
        );
        $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));

        self::assertSame($before, $this->revisionsOf($note), 'A report must add no revision');
    }

    private function revisionsOf(\App\Entity\Note $note): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM note_revisions WHERE note_id = ?',
            [$note->getId()],
        );
    }

    public function testAcknowledgingAReportBuysNoSummary(): void
    {
        // The assertion that separates a report from an empty edit, and the one
        // the first version of this file could not make: the edit path ends by
        // queueing the note for deferred enrichment, and the drain buys a
        // summary for any note that has none. Server-side text has to be ON for
        // the difference to exist at all, which is why it is switched on here —
        // with it off, MlClient::summarize() returns before any call and both
        // paths look identical.
        // The note is written BEFORE the switch goes on, or creating it buys
        // the very summary this test is watching for.
        $note = $this->kb->note($this->writer, 'The runbook', 'Deploys go through Jenkins.');
        self::assertNull($note->getSummary(), 'The note must start undescribed for this to prove anything');

        $settings = self::getContainer()->get(EnrichmentSettings::class);
        $key = $settings->saveKey(AiProviders::OPENAI, 'OpenAI', 'sk-valid-key')['credential'];
        $settings->save(enabled: true, credential: $key);
        $this->em->flush();

        $before = $this->ml->callCount('summar');

        $proposal = $this->writer->propose(
            $note, $this->kb->agentToken,
            null, null, null, 'This is stale.',
        );
        $this->verdicts->approveProposal($proposal, self::VERDICT, $this->reviewSnapshot($proposal));
        $this->em->refresh($note);

        self::assertNull($note->getSummary(), 'Acknowledging a report must not buy a summary');
        self::assertSame($before, $this->ml->callCount('summar'), 'Acknowledging a report must never call the summarizer');
    }

    public function testAReportIsItsOwnReviewKind(): void
    {
        // Filed under `content` it rides along in a bulk approval of real edits
        // and is reported to the operator as applied — the safety property
        // ReviewKinds exists for, with the direction reversed.
        $note = $this->kb->note($this->writer, 'The runbook', 'Deploys go through Jenkins.');

        $proposal = $this->writer->propose(
            $note, $this->kb->agentToken,
            null, null, null, 'This is stale.',
        );

        self::assertSame([ReviewKinds::REPORT], ReviewKinds::ofProposal($proposal));
        self::assertContains(ReviewKinds::REPORT, ReviewKinds::all(), 'The inbox must be able to filter to it');
    }

    public function testInvisibleWhitespaceIsNotAReport(): void
    {
        // trim()'s charlist is ASCII, so the first three survived it and were
        // filed as reports that look blank in the operator's inbox. The rest
        // are the ones a charlist of spaces misses however long it gets:
        // fillers are letters, braille blank is a symbol, bidi marks are
        // format characters, and a combining mark has nothing to combine with.
        $note = $this->kb->note($this->writer, 'The runbook', 'Deploys go through Jenkins.');

        $blanks = [
            'no-break space' => "\u{00A0}",
            'zero width space' => "\u{200B}",
            'byte order mark' => "\u{FEFF}",
            'mixed invisibles' => "\u{00A0} \u{200B}",
            'hangul filler' => "\u{3164}",
            'hangul choseong filler' => "\u{115F}",
            'halfwidth hangul filler' => "\u{FFA0}",
            'braille pattern blank' => "\u{2800}",
            'left-to-right mark' => "\u{200E}",
            'right-to-left mark' => "\u{200F}",
            'left-to-right embedding' => "\u{202A}",
            'lone combining grave' => "\u{0300}",
            'filler beside a space' => " \u{3164} ",
        ];

        $filed = [];
        foreach ($blanks as $name => $blank) {
            try {
                $this->writer->propose($note, $this->kb->agentToken, null, null, null, $blank);
                $filed[] = $name;
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertSame([], $filed, 'Filed as reports that look blank in the inbox: '.implode(', ', $filed));
        // Without this the test passes for a rule that refuses EVERY comment.
        self::assertNotNull(
            $this->writer->propose($note, $this->kb->agentToken, null, null, null, 'Jenkins is gone.')->getComment(),
            'Refusing every comment is not the rule under test',
        );
    }

    public function testAWallOfVariationSelectorsIsNotAReport(): void
    {
        // 100,000 of them exhausted PCRE's stack, and an emptiness test written
        // as `^(?:blank)*$` answers `false` on exhaustion — neither 0 nor 1. A
        // check that only refuses on 1 then files the wall.
        $note = $this->kb->note($this->writer, 'The runbook', 'Deploys go through Jenkins.');

        $this->assertRefusesButNotEverything($note, str_repeat("\u{FE0F}", 100_000));
    }

    public function testACommentWhoseOnlyVisibleCharacterIsTruncatedAwayIsNotAReport(): void
    {
        // It passes an emptiness test applied BEFORE the cap, and is blank
        // after it.
        $note = $this->kb->note($this->writer, 'The runbook', 'Deploys go through Jenkins.');

        $this->assertRefusesButNotEverything($note, str_repeat("\u{FE0F}", 2000).'A');
    }

    /**
     * Refused, and not because everything is refused.
     *
     * `expectException` alone passes for a rule that rejects every comment,
     * which is the mutant these tests are least able to see (codex, third
     * round).
     */
    private function assertRefusesButNotEverything(\App\Entity\Note $note, string $comment): void
    {
        $refused = false;
        try {
            $this->writer->propose($note, $this->kb->agentToken, null, null, null, $comment);
        } catch (\InvalidArgumentException) {
            $refused = true;
        }

        self::assertTrue($refused, 'Filed as a report that looks blank in the inbox');
        self::assertSame(
            'Jenkins is gone.',
            $this->writer->propose($note, $this->kb->agentToken, null, null, null, 'Jenkins is gone.')->getComment(),
            'Refusing every comment is not the rule under test',
        );
    }

    public function testAnEmojiTagSequenceIsNotTrimmedApart(): void
    {
        // The England flag's tail is six tag characters, every one of them
        // `\p{Cf}`. Trimming all of `\p{C}` off the ends leaves a black flag.
        $note = $this->kb->note($this->writer, 'The runbook', 'Deploys go through Jenkins.');
        $flag = "\u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}";

        $proposal = $this->writer->propose(
            $note, $this->kb->agentToken,
            null, null, null, $flag,
        );

        self::assertSame($flag, $proposal->getComment());
    }

    public function testTheCapNeverKeepsHalfOfACharacter(): void
    {
        // mb_substr counts code points, so a cut at the boundary kept the `e`
        // and dropped its accent, and kept the woman while dropping the joiner
        // and the laptop.
        $note = $this->kb->note($this->writer, 'The runbook', 'Deploys go through Jenkins.');
        $body = str_repeat('a', 1999);
        $shorter = str_repeat('a', 1998);

        // A list of the things that join a character is a list somebody keeps
        // getting wrong: the first version had marks and the joiner and missed
        // the skin-tone modifiers, the regional indicator pairs, and the case
        // where the dropped code point is itself VISIBLE — the laptop.
        $ends = [
            'decomposed accent' => [$body."e\u{0301}", $body],
            'joined emoji' => [$shorter."\u{1F469}\u{200D}\u{1F4BB}", $shorter],
            'skin tone' => [$body."\u{1F44D}\u{1F3FD}", $body],
            'regional indicators' => [$body."\u{1F1FA}\u{1F1F8}", $body],
        ];

        foreach ($ends as $name => [$comment, $expected]) {
            $proposal = $this->writer->propose(
                $note, $this->kb->agentToken,
                null, null, null, $comment,
            );

            self::assertSame($expected, $proposal->getComment(), 'The cap kept half of a '.$name);
        }
    }

    public function testBidiControlsAroundRealTextSurvive(): void
    {
        // An isolate is not decoration: it says where the run it wraps is
        // placed in the text around it. Trimming it off leaves the Arabic
        // rendering somewhere the writer did not put it.
        $note = $this->kb->note($this->writer, 'The runbook', 'Deploys go through Jenkins.');
        $isolated = "\u{2067}مرحبا\u{2069}";

        $proposal = $this->writer->propose(
            $note, $this->kb->agentToken,
            null, null, null, $isolated,
        );

        self::assertSame($isolated, $proposal->getComment());
    }

    public function testAnAccentAtTheEndOfAReportSurvives(): void
    {
        // The emptiness test counts a combining mark as nothing, and the trim
        // must not: `\p{M}` on the trailing edge would take the accent off a
        // comment ending in a decomposed word and leave the operator reading
        // something the agent did not write.
        $note = $this->kb->note($this->writer, 'The runbook', 'Deploys go through Jenkins.');
        $decomposed = "Cette note est perime\u{0301}";

        $proposal = $this->writer->propose(
            $note, $this->kb->agentToken,
            null, null, null, $decomposed,
        );

        self::assertSame($decomposed, $proposal->getComment());
    }

    public function testAReportCommentIsCapped(): void
    {
        $note = $this->kb->note($this->writer, 'The runbook', 'Deploys go through Jenkins.');

        $proposal = $this->writer->propose(
            $note, $this->kb->agentToken,
            null, null, null, str_repeat('a', 50_000),
        );

        self::assertSame(2000, mb_strlen((string) $proposal->getComment()));
    }

    public function testAReportFromACuratorTokenIsHeldLikeEveryOther(): void
    {
        // A curator's safe writes apply immediately. There is nothing here to
        // apply, so there is nothing to trust it with: the operator has to read
        // it for the report to do anything at all.
        $note = $this->kb->note($this->writer, 'The runbook', 'Deploys go through Jenkins.');

        $proposal = $this->writer->propose(
            $note, $this->kb->curatorToken,
            null, null, null, 'This is stale.',
        );

        self::assertSame(EditProposal::TYPE_REPORT, $proposal->getType());
        self::assertFalse($proposal->isApplied(), 'A report is held for every role');
    }

    public function testAProposalWithNeitherAChangeNorACommentIsStillRefused(): void
    {
        $note = $this->kb->note($this->writer, 'The runbook', 'Deploys go through Jenkins.');

        $this->expectException(\InvalidArgumentException::class);
        $this->writer->propose($note, $this->kb->agentToken, null, null, null, null);
    }

    public function testAWhitespaceOnlyCommentIsNotAReport(): void
    {
        // Otherwise "   " is a staleness report that says nothing, and the
        // operator gets an inbox item with no content to act on.
        $note = $this->kb->note($this->writer, 'The runbook', 'Deploys go through Jenkins.');

        $this->expectException(\InvalidArgumentException::class);
        $this->writer->propose($note, $this->kb->agentToken, null, null, null, "  \n\t ");
    }

    public function testMemexsOwnPassCannotFileAReport(): void
    {
        // A null token is the enrichment pass, which describes notes and has
        // nothing to report. Without this it would file one on every note it
        // could not summarise.
        $note = $this->kb->note($this->writer, 'The runbook', 'Deploys go through Jenkins.');

        $this->expectException(\InvalidArgumentException::class);
        $this->writer->propose($note, null, null, null, null, 'Something is wrong.');
    }

    public function testAnEditThatCarriesACommentIsStillAnEdit(): void
    {
        // The comment is not what makes a report; the ABSENCE of a change is.
        $note = $this->kb->note($this->writer, 'The runbook', 'Deploys go through Jenkins.');

        $proposal = $this->writer->propose(
            $note, $this->kb->agentToken,
            null, 'Deploys go through GitHub Actions.', null, 'Jenkins was retired.',
        );

        self::assertSame(EditProposal::TYPE_EDIT, $proposal->getType());
    }
}
