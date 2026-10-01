<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;
use App\Service\NoteLimbo;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Re-importing a vault must not put back the notes curation removed.
 *
 * `ImportController` computed the tombstone list, documented it as the reason
 * the permanent tombstone exists — "without this a re-import silently
 * resurrects everything curation removed" — and then used it only in the
 * dry-run response, which the interface did not display. So the ordinary case,
 * re-importing the zip the nightly backup had just produced, silently reversed
 * every deletion. There was no test of `tombstonesByTitle` at all, and no
 * import controller test of any kind.
 */
final class ImportResurrectionTest extends ApiTestCase
{
    /** @param array<string, string> $files title => body */
    private function zipOf(array $files): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'memex-import-').'.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($files as $title => $body) {
            $zip->addFromString($title.'.md', "---\ntitle: ".$title."\n---\n\n".$body."\n");
        }
        $zip->close();

        return new UploadedFile($path, 'vault.zip', 'application/zip', test: true);
    }

    /**
     * Import as the owner, from a browser session.
     *
     * It went in as a bearer token until 2026-08-22, when L-1 closed this
     * endpoint to tokens entirely: import reaches PAST the review gate by
     * design — a raw `UPDATE notes SET import_path`, then a relink pass that
     * can rewire wiki-links inside verified notes — so the one caller it has
     * ever had, the import panel, is now the only caller it accepts. Signing in
     * here is what that panel does.
     *
     * @param array<string, scalar> $options
     */
    private function import(UploadedFile $zip, array $options = []): void
    {
        $this->loginAs($this->kb->a);
        $this->client->request(
            'POST',
            '/api/import',
            parameters: $options,
            files: ['archive' => $zip],
        );
    }

    private function retire(string $title): int
    {
        $note = $this->kb->a->note($title, 'The body that was removed.');
        $id = $note->getId();
        self::getContainer()->get(NoteLimbo::class)->retire($note, Note::ACTOR_HUMAN, 'Curated away.');

        return $id;
    }

    private function liveTitles(): array
    {
        $this->in($this->kb->a);

        return $this->em->getConnection()->fetchFirstColumn('SELECT title FROM notes ORDER BY title');
    }

    public function testAConfirmedImportDoesNotRecreateANoteThisTeamDeleted(): void
    {
        $this->retire('Removed on purpose');

        $this->import($this->zipOf([
            'Removed on purpose' => 'The body that was removed.',
            'Genuinely new' => 'Never seen before.',
        ]));

        self::assertContains($this->httpStatus(), [200, 201]);
        $body = $this->jsonResponse();
        self::assertSame(1, $body['created'], 'Only the new note is created');
        self::assertSame(['Genuinely new'], $this->liveTitles());
        // …and the operator is told, on the confirmed response, not only in an
        // analysis nobody has to run.
        self::assertSame(['Removed on purpose'], $body['previously_retired_held']);
        self::assertSame('Removed on purpose', $body['previously_retired'][0]['title']);
        self::assertSame('Curated away.', $body['previously_retired'][0]['reason']);
    }

    public function testTheOperatorCanAskForThemBackExplicitly(): void
    {
        $this->retire('Removed on purpose');

        $this->import($this->zipOf(['Removed on purpose' => 'The body that was removed.']), ['resurrect_retired' => '1']);

        self::assertSame(1, $this->jsonResponse()['created']);
        self::assertSame(['Removed on purpose'], $this->liveTitles(), 'Asked for explicitly, it comes back');
        self::assertTrue($this->jsonResponse()['resurrected_retired']);
    }

    public function testTheDryRunCountsWhatWillActuallyBeCreated(): void
    {
        // The count on the button was the bug's cover: retired titles were
        // included in "N notes ready to import", so nothing looked wrong.
        $this->retire('Removed on purpose');

        $this->import($this->zipOf([
            'Removed on purpose' => 'Body.',
            'Genuinely new' => 'Body.',
        ]), ['dry_run' => '1']);

        $body = $this->jsonResponse();
        self::assertSame(1, $body['importable']);
        self::assertSame(['Genuinely new'], $body['titles']);
        self::assertSame(['Removed on purpose'], $body['previously_retired_held']);
        self::assertSame([], $this->liveTitles(), 'A dry run writes nothing');
    }

    public function testAPurgedNoteIsHeldBackToo(): void
    {
        // A purged tombstone keeps its title forever precisely so this works
        // after the body is gone.
        $id = $this->retire('Purged for a reason');
        self::getContainer()->get(NoteLimbo::class)->purge($id);

        $this->import($this->zipOf(['Purged for a reason' => 'The credential.']));

        self::assertSame(0, $this->jsonResponse()['created']);
        self::assertSame([], $this->liveTitles(), 'Purged content must not walk back in through import');
        self::assertFalse($this->jsonResponse()['previously_retired'][0]['restorable']);
    }

    public function testAnotherTeamsTombstoneDoesNotBlockThisTeamsImport(): void
    {
        // The tombstone lookup is the vault's own; a title B deleted says nothing
        // about what A may import.
        $noteB = $this->kb->b->note('Shared title', 'B’s body.');
        self::getContainer()->get(NoteLimbo::class)->retire($noteB, Note::ACTOR_HUMAN, 'B removed it.');

        $this->import($this->zipOf(['Shared title' => 'A’s body.']));

        self::assertSame(1, $this->jsonResponse()['created']);
        self::assertSame(['Shared title'], $this->liveTitles());
    }
}
