<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CurationCharter;
use App\Service\SkillLibrary;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The catalogue of skills memex ships, and the curation canon it does NOT ship
 * that way.
 *
 * A skill IS a note tagged `skill`, with one exception: the curation charter is
 * served from {@see CurationCharter} because it is memex's own machinery. What
 * ships as a catalogue is offered by onboarding and written into the knowledge
 * base as a real note, and the slug ties an entry to the note taken from it.
 *
 * These are the pure halves of both: the slug function, a guard on the shipped
 * catalogue files, and the guards on the canon. A file with malformed
 * frontmatter is skipped in silence, so without them a bad edit would quietly
 * empty the instruction set.
 */
final class SkillLibraryTest extends TestCase
{
    private const DEFAULTS_DIR = __DIR__.'/../../config/skills';
    /** The suite's own catalogue entry: memex ships none, and the guards below must still run on one. */
    private const FIXTURE_DIR = __DIR__.'/../Fixtures/skills';
    private const CANON = __DIR__.'/../../config/curation/canon.md';

    public function testTheCanonSlugsToTheReservedSlug(): void
    {
        $parsed = SkillLibrary::parseSkillFile((string) file_get_contents(self::CANON));

        self::assertNotNull($parsed, 'the canon must parse — a malformed file is skipped in '
            .'silence, and every connected assistant would be served no charter at all');
        self::assertSame(CurationCharter::SLUG, SkillLibrary::slugify($parsed['title']),
            'agents are told the charter\'s name at connect time and load it by that slug. If the '
            .'title slugged elsewhere the pointer would name something that is not served');
    }

    /**
     * The charter must not ALSO be a catalogue entry. Onboarding writes a
     * catalogue entry into the KB as a note, and a note is a frozen copy: the
     * operator's own was written before 2026-08-26, never received a single
     * shipped correction, and told agent-role passes to browse instead of using
     * the queue — the improvised pass the charter exists to prevent.
     */
    public function testTheCharterIsNotOfferedAsANoteAsWell(): void
    {
        $slugs = [];
        foreach ((array) glob(self::DEFAULTS_DIR.'/*.md') as $file) {
            $parsed = SkillLibrary::parseSkillFile((string) file_get_contents($file));
            $slugs[basename($file)] = SkillLibrary::slugify($parsed['title'] ?? '');
        }

        // The assertion is over the SET, so it holds on an empty catalogue as
        // well as the current four — a guard that only worked once entries
        // existed would have been silent for the whole time there were none.
        self::assertNotContains(CurationCharter::SLUG, $slugs,
            'a catalogue entry is written into a knowledge base as a NOTE, and a charter note is '
            .'the frozen copy the desk exists to replace: the operator\'s own never received a '
            .'single shipped correction after it was taken');
    }

    public function testTheCanonCarriesADescriptionAndInstructions(): void
    {
        $parsed = SkillLibrary::parseSkillFile((string) file_get_contents(self::CANON));

        self::assertNotSame('', $parsed['description'], 'the description is what list_skills shows; '
            .'without it neither a person nor an assistant can tell whether the skill matches what '
            .'they are doing');
        self::assertStringContainsString('You are the Curator', $parsed['body']);
        self::assertStringNotContainsString('---', substr($parsed['body'], 0, 4),
            'the frontmatter must be stripped, not served as instructions');
    }

    public function testEveryShippedSkillParsesAndIsNamedByItsFile(): void
    {
        $checked = 0;
        foreach ($this->catalogueFiles() as $file) {
            ++$checked;
            $parsed = SkillLibrary::parseSkillFile((string) file_get_contents($file));
            self::assertNotNull(
                $parsed,
                basename($file).' does not parse, so the catalogue would silently drop it'
            );
            // The catalogue slugs by FILENAME and a taken skill slugs by its
            // note TITLE. They are the same join, so a file whose title slugs
            // elsewhere would be offered for ever to a KB that already took it.
            // Asserted for every file rather than only the charter, because
            // the second shipped skill is where this first became possible.
            self::assertSame(
                basename($file, '.md'),
                SkillLibrary::slugify($parsed['title']),
                basename($file).': the title must slug to the filename'
            );
            self::assertNotSame('', $parsed['description'],
                basename($file).' has no description, so neither the catalogue nor list_skills '
                .'can say what it is for');
            $this->assertShortIsItsOwnLine($file);
        }

        // An empty catalogue passes every assertion above by never reaching
        // one, and comparing the loop count with the glob count does not
        // catch it — both are zero. The floor is what makes the loop mean
        // something.
        self::assertGreaterThan(0, $checked, 'no catalogue entry, shipped or fixture, so every '
            .'guard in this test ran zero times and passed by checking nothing');
        self::assertSame(\count($this->catalogueFiles()), $checked);
    }

    /** @return list<string> the shipped catalogue's files and the suite's own entry */
    private function catalogueFiles(): array
    {
        return array_merge((array) glob(self::DEFAULTS_DIR.'/*.md'), (array) glob(self::FIXTURE_DIR.'/*.md'));
    }

    /**
     * `short` is the one-line row the catalogue surfaces render beside an Add
     * button, and {@see SkillLibrary::parseSkillFile()} defaults it to the
     * description when a file omits it. That fallback is a paragraph, so the
     * assertion has to reach past the parsed value to the FILE: a length guard
     * alone passes for a file that never wrote one, and passes for `short: ""`,
     * which renders an empty row.
     */
    private function assertShortIsItsOwnLine(string $file): void
    {
        $name = basename($file);
        preg_match('/^---\R(.*?)\R---\R/s', (string) file_get_contents($file), $m);
        $meta = Yaml::parse($m[1] ?? '');

        self::assertIsArray($meta);
        self::assertArrayHasKey('short', $meta,
            $name.' has no `short`, so the catalogue falls back to its description — a paragraph '
            .'in a row sized for one line');
        self::assertIsString($meta['short']);
        self::assertNotSame('', trim($meta['short']),
            $name.': an empty `short` renders a blank row beside the Add button');
        self::assertNotSame(trim($meta['description'] ?? ''), trim($meta['short']),
            $name.': `short` repeats the description, which is the fallback it exists to replace');
        self::assertLessThanOrEqual(80, mb_strlen(trim($meta['short'])),
            $name.': `short` is one line beside a button. Over 80 characters it wraps');
    }

    // ---- the two-document arrangement ------------------------------------

    /**
     * §4 of the curation plan reads *shrink the charter*. It was built as a
     * SECOND document instead, on the operator's ruling of 2026-08-24, and
     * these three tests are what keeps that arrangement from quietly
     * collapsing back into one.
     *
     * The reason is measurement, not taste. The full charter run as a
     * whole-collection pass is how the task-shaped curation gets scored: if
     * the thorough sweep keeps turning up judgment-level work,
     * prevention-plus-tasks is leaking. An instrument you trim mid-experiment
     * stops being a control, so `canon.md` is not to be cut down — and a test is a better
     * place to record that than a comment nobody reads before editing.
     */
    public function testCurationShipsExactlyOneInstructionSet(): void
    {
        $files = array_map('basename', array_merge(
            (array) glob(self::DEFAULTS_DIR.'/*.md'),
            (array) glob(dirname(self::CANON).'/*.md'),
        ));

        // The `memex-curate` task loop shipped and was withdrawn the same day
        // (2026-08-24). It was not withdrawn for working badly; it was
        // withdrawn because BOTH were served at once and they contradicted
        // each other on the first step — the task loop said "call nothing
        // else first, no charter, no log, no candidate list", the charter said
        // bootstrap from all three. Two instruction sets that disagree about
        // where to start do not average out: a model picks one, and which one
        // is not something the operator chose.
        self::assertNotContains('memex-curate.md', $files,
            'the withdrawn one-task curation skill is back — curation ships ONE instruction set, '
            .'and a second one that starts differently is how an agent stops following the charter');
        self::assertContains('canon.md', $files);
    }

    public function testTheCharterIsStillTheDeepRunDocument(): void
    {
        $charter = (string) SkillLibrary::parseSkillFile((string) file_get_contents(self::CANON))['body'];

        // An ABSOLUTE floor, because this document's whole value is that it is
        // long enough to describe a real pass. A mutation proved a ratio guard
        // useless on its own: the charter could be cut by a third and stay
        // green. ~10% headroom for ordinary edits, and no more.
        self::assertGreaterThan(14000, mb_strlen($charter),
            'the charter has been cut down. It is the deep-run document, and curation now has no '
            .'other — trimming it does not move the instructions elsewhere, it deletes them');
        // The HEADING, not the words. "Run procedure" also appears twice as a
        // cross-reference inside the Authority section, so asserting the bare
        // phrase passed with the whole section deleted — found by mutating it.
        self::assertStringContainsString("\n# Run procedure", $charter,
            'the charter without its run procedure section is no longer the deep-run document');
    }

    public function testTheCharterSaysAZeroCensusIsNotAnEmptyQueue(): void
    {
        $charter = (string) SkillLibrary::parseSkillFile((string) file_get_contents(self::CANON))['body'];

        // The failure this sentence exists to stop, from a real unattended run
        // (2026-08-24): `reason_counts` came back zero in every class, the run
        // read that as an empty queue and stopped — on a queue holding 76
        // notes no pass had ever opened. It was obeying this document, which
        // said "an empty queue is a complete run" and never said what empty
        // meant. Six of the eight hunt items are invisible to that census.
        self::assertStringContainsString('`reason_counts` all zero is NOT an empty queue', $charter,
            'the charter no longer distinguishes an exhausted defect census from an empty queue — '
            .'the exact reading that made a nightly run examine nothing and call it complete');
        self::assertStringContainsString('examined:', $charter,
            'the charter must tell a run to record what it READ. Nothing else records it, so '
            .'without that instruction the rotation cannot advance and every pass re-reads the '
            .'same notes');
    }

    /**
     * The three fields a run must send, because memex cannot work any of them
     * out for itself — and the one that was actually missing.
     *
     * The operator's charter note asked only for `examined`. It had been taken
     * from the catalogue before `started_at` existed and could never receive
     * it, so not one run-summary in 678 log rows has ever carried a start time
     * and the curation digest can attribute 76 of them. The canon is served
     * rather than copied now, which is the fix; this is the guard that the
     * sentence stays in it.
     */
    public function testTheCanonAsksForEverythingOnlyTheRunKnows(): void
    {
        $charter = (string) SkillLibrary::parseSkillFile((string) file_get_contents(self::CANON))['body'];

        self::assertStringContainsString('`started_at:`', $charter,
            'without this a run is unattributable: the digest can only summarise everything the '
            .'connection wrote since its last pass, which mixes the run in with hand-driven work');
        self::assertStringContainsString('`claimed:`', $charter,
            'the claims are what the tripwire compares against the rows memex logged; a run that '
            .'files none is never checked');
        self::assertStringContainsString('`examined:`', $charter,
            'nothing else records a note that was READ and left alone, so without it the rotation '
            .'cannot advance and every pass re-reads the same notes');
    }

    /** The brief is served with the canon, so a brief-less charter is a truncated one. */
    public function testTheCanonPointsAtTheBriefItIsServedWith(): void
    {
        $charter = (string) SkillLibrary::parseSkillFile((string) file_get_contents(self::CANON))['body'];

        self::assertStringContainsString('brief at the end of this document', $charter,
            'the canon must tell a run that the budget and the exclusions below are settings to '
            .'honour rather than suggestions it may weigh');
    }

    public function testSlugifyMatchesTheTitlesSkillNotesActuallyCarry(): void
    {
        self::assertSame('curator-charter', SkillLibrary::slugify('Curator — charter'));
        self::assertSame('memex-recall-using-the-shared-memory', SkillLibrary::slugify('memex-recall — using the shared memory'));
        self::assertSame('avoid-ai-writing-audit-rewrite', SkillLibrary::slugify('Avoid AI Writing — Audit & Rewrite'));
    }

    public function testATitleWithNothingSluggableStillGetsASlug(): void
    {
        // Otherwise the skill would answer to the empty string, and every other
        // untitled skill would answer to it too.
        self::assertSame('skill', SkillLibrary::slugify('———'));
        self::assertSame('skill', SkillLibrary::slugify(''));
    }

    public function testAFileWithoutFrontmatterOrATitleIsRefused(): void
    {
        self::assertNull(SkillLibrary::parseSkillFile("# Just a heading\n\nBody."));
        self::assertNull(SkillLibrary::parseSkillFile("---\ndescription: no title\n---\nBody."));
        self::assertNull(SkillLibrary::parseSkillFile("---\ntitle: \"  \"\n---\nBody."));
        self::assertNull(SkillLibrary::parseSkillFile("---\n: : not yaml : :\n---\nBody."));
    }
}
