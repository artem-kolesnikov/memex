<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ShippedSkills;
use App\Service\SkillLibrary;
use App\Service\SkillServing;
use PHPUnit\Framework\TestCase;

/**
 * The pure half of what memex serves of its own.
 *
 * Every guard here exists because the failure it prevents is SILENT: a shipped
 * file whose frontmatter is malformed is skipped without a word, and the first
 * anyone would know is an assistant working without instructions it was
 * supposed to have.
 */
final class ShippedSkillsTest extends TestCase
{
    private const DIR = __DIR__.'/../../config/skills-shipped';
    /** Where each edition supplies the docs skill of its own. */
    private const EDITION_DIR = __DIR__.'/../../config/edition/skills';

    public function testEveryShippedSlugHasAFileThatParses(): void
    {
        foreach (ShippedSkills::reservedSlugs() as $slug) {
            if ($slug === SkillLibrary::CURATION_CHARTER) {
                // The charter composes rather than ships flat; its own file is
                // guarded in SkillLibraryTest.
                continue;
            }
            if (ShippedSkills::canonical($slug) !== $slug) {
                // An earlier name, reserved so no note takes it, and served
                // as the skill it now names.
                continue;
            }

            $path = ($slug === ShippedSkills::GUIDE ? self::EDITION_DIR : self::DIR).'/'.$slug.'.md';
            self::assertFileExists($path, "$slug is reserved, so no note may claim it — if nothing "
                .'ships under that name, the slug is simply unusable by anybody');

            $parsed = SkillLibrary::parseSkillFile((string) file_get_contents($path));
            self::assertNotNull($parsed, "$slug must parse — a malformed file is skipped in "
                .'silence and the skill is served to nobody');
            self::assertNotSame('', $parsed['description'],
                'the description is all an assistant sees in list_skills when deciding whether to '
                .'load it, so a blank one makes the skill invisible in practice');
            if ($slug !== ShippedSkills::WRITING) {
                // memex-writing's body is generated from the owner's presets;
                // its file carries only the title and description.
                self::assertNotSame('', trim($parsed['body']));
            }

            // The charter's convention, and the reason it is load-bearing: a
            // shipped title that slugs elsewhere leaves the reserved slug
            // guarding a name nothing is served under, while the skill itself
            // answers to a slug a note could still claim.
            self::assertSame($slug, SkillLibrary::slugify($parsed['title']),
                "$slug is reserved, so the title must slug to it");
        }
    }

    /**
     * The reservation list is the load-bearing half, not the file list.
     *
     * `SkillLibrary::all()` reserves these slugs BEFORE walking the vault's
     * notes. A slug that ships but is not reserved can be claimed by a note
     * titled into it, and an agent asking for a name memex vouches for is then
     * handed something a stranger's knowledge base wrote.
     */
    public function testEveryShippedFileIsReserved(): void
    {
        $files = [...glob(self::DIR.'/*.md'), ...glob(self::EDITION_DIR.'/*.md')];
        self::assertNotSame([], $files, 'this directory is what memex serves of its own; empty '
            .'means the recall instructions reach nobody');

        foreach ($files as $file) {
            self::assertContains(basename($file, '.md'), ShippedSkills::reservedSlugs(),
                'a shipped file whose slug is not reserved can be shadowed by a note');
        }
    }

    /** The charter is reserved too, and by the same list rather than a second one. */
    public function testTheCharterSlugIsInTheOneReservationList(): void
    {
        self::assertContains(SkillLibrary::CURATION_CHARTER, ShippedSkills::reservedSlugs());
        self::assertContains(ShippedSkills::RECALL, ShippedSkills::reservedSlugs());
    }

    /**
     * A catalogue file may not claim a reserved slug.
     *
     * The two answers would otherwise disagree: `catalogue()` reports an entry
     * as untaken, because a shipped skill has no note id to report, while
     * `add()` refuses it 409, because its conflict check finds the shipped
     * skill. Onboarding would offer something nobody can accept. Dormant while
     * `config/skills/` is empty, and onboarding is what fills it.
     */
    public function testTheCatalogueOffersNoReservedSlug(): void
    {
        $dir = sys_get_temp_dir().'/mm-catalogue-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $write = static fn (string $slug) => file_put_contents(
            "$dir/$slug.md",
            "---\ntitle: \"$slug\"\ndescription: \"d\"\n---\n\nBody.\n",
        );

        try {
            $write(ShippedSkills::RECALL);
            $write(SkillLibrary::CURATION_CHARTER);
            $write('note-taking-house-style');

            $library = new SkillLibrary(
                $this->createMock(ShippedSkills::class),
                $this->createMock(SkillServing::class),
                $dir,
            );

            self::assertSame(
                ['note-taking-house-style'],
                array_column($library->catalogue(), 'slug'),
                'a catalogue entry on a reserved slug would be offered by catalogue() and then '
                .'refused 409 by add(), because add() finds the shipped skill and catalogue() '
                .'cannot see it',
            );
        } finally {
            array_map('unlink', glob("$dir/*.md") ?: []);
            rmdir($dir);
        }
    }

    /**
     * The recall skill must not tell an assistant to do something the server
     * refuses, which is the defect that put this file here: the note it
     * replaces directed agents to a settings pane renamed weeks earlier.
     *
     * Panes are named in one place — the SPA's settings menu — and prose is the
     * one thing no compiler checks, so the names it may use are pinned.
     */
    public function testRecallNamesNoSettingsPaneThatDoesNotExist(): void
    {
        $body = (string) file_get_contents(self::DIR.'/'.ShippedSkills::RECALL.'.md');

        foreach (['API tokens', 'Connections', 'Enrichment & Curation', 'Appearance'] as $gone) {
            self::assertStringNotContainsString("Settings → $gone", $body,
                "\"$gone\" is not a settings pane — an assistant relaying that sends its user "
                .'somewhere that is not there');
        }
    }
}
