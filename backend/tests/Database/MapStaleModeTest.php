<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Entity\Note;

/**
 * The map's "possibly stale" mode, over the browser's own route.
 *
 * The answer already existed as the `blast_radius` MCP tool, so what is new
 * here is only the door — and a door onto a curation query is exactly where a
 * mode can go quietly wrong. Three properties matter to the map and to nothing
 * else: the note it names is the one that did NOT change, the neighbour that
 * did travels with it so the rail can say why, and the window is a window, so
 * a change older than it ages out rather than accumulating for ever.
 */
final class MapStaleModeTest extends ApiTestCase
{
    private function age(Note $note, int $days): void
    {
        $this->in($this->kb->a);
        $this->em->getConnection()->executeStatement(
            'UPDATE notes SET updated_at = :at WHERE id = :id',
            [
                'at' => (new \DateTimeImmutable("-$days days", new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'id' => $note->getId(),
            ],
        );
    }

    /** @return array<string, mixed> */
    private function stale(?int $days = null): array
    {
        $this->request('GET', '/api/graph/blast'.($days === null ? '' : "?since_days=$days"), $this->kb->a->agentBearer);
        self::assertSame(200, $this->httpStatus(), $this->body());

        return $this->jsonResponse();
    }

    public function testANoteWhoseNeighbourChangedIsNamedWithTheChangeThatDidIt(): void
    {
        $runbook = $this->kb->a->note('Deploy runbook', 'How the box is deployed.');
        $checklist = $this->kb->a->note('Release checklist', 'Follow [[Deploy runbook]] first.');
        $this->age($checklist, 60);

        $answer = $this->stale();

        self::assertSame(1, $answer['total'], json_encode($answer, JSON_THROW_ON_ERROR));
        $affected = $answer['notes'][0];
        // The checklist is stale BECAUSE the runbook moved; the runbook itself
        // is the blast, not the radius, and naming it here would send a reader
        // to reread the note they just wrote.
        self::assertSame($checklist->getId(), $affected['note_id']);
        self::assertSame(1, $affected['changed_neighbours']);
        self::assertSame([$runbook->getId()], array_column($affected['changed'], 'note_id'));
    }

    public function testAChangeOlderThanTheWindowIsNotDrawn(): void
    {
        $runbook = $this->kb->a->note('Deploy runbook', 'How the box is deployed.');
        $checklist = $this->kb->a->note('Release checklist', 'Follow [[Deploy runbook]] first.');
        $this->age($checklist, 200);
        $this->age($runbook, 40);

        self::assertSame(0, $this->stale(14)['total']);
        self::assertSame(1, $this->stale(90)['total'], 'a wider window must find the change the narrow one aged out');
    }

    public function testTheSourcesTravellingWithANoteAreBoundedAndTheCountIsNot(): void
    {
        for ($i = 1; $i <= 12; ++$i) {
            $this->kb->a->note("Runbook $i", "Step $i.");
        }
        $cites = implode(' ', array_map(static fn (int $i) => "[[Runbook $i]]", range(1, 12)));
        $checklist = $this->kb->a->note('Release checklist', "Follow $cites in order.");
        $this->age($checklist, 60);

        $affected = $this->stale()['notes'][0];

        // The reader is told the true number and shown a readable few of them.
        self::assertSame(12, $affected['changed_neighbours']);
        self::assertCount(8, $affected['changed']);
    }

    public function testAnUnlinkedNoteIsNeverStale(): void
    {
        $alone = $this->kb->a->note('Standalone note', 'Nothing points here.');
        $this->age($alone, 60);
        $this->kb->a->note('Something else entirely', 'Also unconnected.');

        self::assertSame(0, $this->stale()['total']);
    }
}
