<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Service\HybridSearch;
use App\Service\NoteLimbo;
use App\Service\NoteWriter;
use App\Service\SystemTags;
use App\Tests\Support\KbFixture;

/**
 * The two tags memex reads lead every list that shows tags.
 *
 * Alphabet alone decided it, and the notes list collapses whatever will not
 * fit into "+N" — so whether a note announced itself as a skill depended on
 * which other words it happened to carry. `live-state` lost to `automation`
 * and `skill` to anything before "s".
 */
final class SystemTagsFirstTest extends DatabaseTestCase
{
    private NoteWriter $writer;
    private KbFixture $kb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = self::getContainer()->get(NoteWriter::class);
        $this->kb = new KbFixture(self::getContainer());
    }

    /** @return string[] */
    private function tagsOf(int $noteId): array
    {
        return $this->em->getConnection()->fetchFirstColumn(
            'SELECT t.name FROM note_tag nt JOIN tags t ON t.id = nt.tag_id
             WHERE nt.note_id = :id ORDER BY '.SystemTags::sqlRank('t.name').', t.name',
            ['id' => $noteId]
        );
    }

    public function testTheRankPutsBothSystemTagsAheadOfEveryOrdinaryWord(): void
    {
        $note = $this->kb->note(
            $this->writer,
            'Tagged every which way',
            'Body text.',
            ['automation', 'live-state', 'zebra', 'skill', 'aardvark'],
        );

        self::assertSame(
            ['skill', 'live-state', 'aardvark', 'automation', 'zebra'],
            $this->tagsOf($note->getId()),
        );
    }

    public function testTheNotesListCarriesEachNotesTagsSystemFirst(): void
    {
        // The list that collapses what will not fit into "+N": the tags it is
        // handed are the tags the chip row gets to choose from.
        $this->kb->note(
            $this->writer,
            'Findable by its own word',
            'Distinctivewordhere in the body.',
            ['automation', 'skill', 'aardvark'],
        );

        $found = self::getContainer()->get(HybridSearch::class)->search(
            'Distinctivewordhere', [], null, null, 1, 10,
        );

        $hit = null;
        foreach ($found['items'] as $row) {
            if ($row['title'] === 'Findable by its own word') {
                $hit = $row;
            }
        }
        self::assertNotNull($hit, 'The note the search was written for was not returned');
        self::assertSame(['skill', 'aardvark', 'automation'], array_column($hit['tags'], 'name'));
    }

    public function testARetiredNoteKeepsTheSameOrderOnItsTombstone(): void
    {
        $note = $this->kb->note($this->writer, 'Doomed', 'Body.', ['automation', 'live-state', 'skill']);
        $id = $note->getId();

        self::getContainer()->get(NoteLimbo::class)->retire($note, 'operator', 'Testing.');

        $tags = json_decode(
            (string) $this->em->getConnection()->fetchOne(
                'SELECT tags FROM deleted_notes WHERE id = :id',
                ['id' => $id]
            ),
            true,
            8,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(['skill', 'live-state', 'automation'], $tags);
    }

    public function testTheSortIsTheSameAnswerInPhpAsInSql(): void
    {
        self::assertSame(
            ['skill', 'live-state', 'aardvark', 'automation', 'zebra'],
            SystemTags::sort(['automation', 'live-state', 'zebra', 'skill', 'aardvark']),
        );
    }
}
