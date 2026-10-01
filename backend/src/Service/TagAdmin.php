<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CuratorLogEntry;
use App\Entity\Note;
use App\Entity\RetiredTag;
use App\Entity\SearchPreset;
use App\Entity\Tag;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * Removing a tag from a vocabulary, and moving one into another.
 *
 * Both are BULK EDITS OF NOTES wearing the clothes of a small tidy-up, and
 * that is the whole reason this is a service rather than four lines in a
 * controller. Deleting `inbox` rewrites every note that carries it. Nothing
 * else in memex changes ninety notes on one click: a deleted note retires
 * into limbo and comes back, an agent's edit waits in the inbox, an import
 * shows you what it will do first. This one is genuinely irreversible, so it
 * is the caller's job to have said the number out loud before arriving here,
 * and this class's job to leave a record that it happened.
 *
 * Four things happen together, inside one transaction:
 *
 *  1. the notes lose the tag (or gain the one it merged into),
 *  2. an Activity Journal row records the act, its count and who did it,
 *  3. a {@see RetiredTag} row records that the word was rejected on purpose,
 *     so enrichment stops suggesting it back,
 *  4. saved filters naming the tag follow it into the merge, or drop it.
 *
 * Notes that change get their `updated_at` and `version` bumped, because they
 * did change: a stale editor open on one of them should be told, and the
 * embedding sweep should look again. It will not SPEND anything looking —
 * tags are not part of the embeddable text, so the hash guard in NoteEnricher
 * finds the vector already correct.
 */
class TagAdmin
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Take a tag out of the vocabulary and off every note carrying it.
     *
     * @return int how many notes lost it
     */
    public function remove(Tag $tag, string $byName): int
    {
        return $this->rewrite($tag, null, $byName);
    }

    /**
     * Move a tag's notes onto another tag and retire the first.
     *
     * This is the operation people actually reach for. "project" and
     * "projects" is not a mistake anybody wants to fix by removing one and
     * re-tagging forty notes by hand, and offering only removal turns the
     * common case into an afternoon.
     *
     * @return int how many notes were moved
     */
    public function merge(Tag $from, Tag $into, string $byName): int
    {
        if ($from->getId() === $into->getId()) {
            throw new \InvalidArgumentException('A tag cannot be merged into itself');
        }
        return $this->rewrite($from, $into, $byName);
    }

    /**
     * Names with the retired ones removed.
     *
     * The one door for the suppression rule, because two callers now apply it
     * — enrichment on arrival and the scheduled pass — and a filter that has
     * to be remembered in two places is a filter that will be applied in one.
     *
     * @param string[] $names
     *
     * @return string[]
     */
    public function withoutRetired(array $names): array
    {
        if ($names === []) {
            return [];
        }
        $retired = $this->retiredNames();
        if ($retired === []) {
            return array_values($names);
        }

        return array_values(array_filter(
            $names,
            static fn (string $name) => !in_array(mb_strtolower($name), $retired, true),
        ));
    }

    /** The retired names, lowest-cost lookup for the suggestion filter. */
    public function retiredNames(): array
    {
        return array_column(
            $this->em->getConnection()->fetchAllAssociative('SELECT name FROM retired_tags ORDER BY name'),
            'name',
        );
    }

    /**
     * A name is back in the vocabulary, so the record that it was rejected is
     * no longer true. Called from the one place a tag row is ever created.
     */
    public function unretire(string $name): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM retired_tags WHERE name = :name', ['name' => $name]);
    }

    /** @return array{name: string, note_count: int, merged_into: ?string, retired_at: string}[] */
    public function retired(): array
    {
        return array_map(
            static fn (array $r) => [
                'name' => $r['name'],
                'note_count' => (int) $r['note_count'],
                'merged_into' => $r['merged_into'],
                'retired_at' => (new \DateTimeImmutable($r['retired_at']))->format(DATE_ATOM),
            ],
            $this->em->getConnection()->fetchAllAssociative(
                'SELECT name, note_count, merged_into, retired_at FROM retired_tags ORDER BY retired_at DESC',
            ),
        );
    }

    /**
     * Refuse to take one of memex's own tags out of the vocabulary.
     *
     * Here rather than in the controller because both entry points pass
     * through {@see rewrite()} and a fourth caller will one day not go through
     * the controller at all — an import tidy-up, a console command. The rule
     * belongs with the irreversible act, not with the button that asks for it.
     *
     * Only the tag being RETIRED is checked. Merging another word into `skill`
     * is the consolidation the curator charter asks for, and blocking it would
     * make `skill`/`skills` unfixable; see {@see SystemTags}.
     */
    private static function assertRemovable(Tag $tag): void
    {
        $reason = SystemTags::reason($tag->getName());
        if ($reason !== null) {
            throw new SystemTagException($tag->getName(), $reason);
        }
    }

    /** The shared body of remove() and merge(): $into null means "just take it off". */
    private function rewrite(Tag $tag, ?Tag $into, string $byName): int
    {
        self::assertRemovable($tag);

        $conn = $this->em->getConnection();
        $tagId = (int) $tag->getId();
        $name = $tag->getName();

        $conn->beginTransaction();
        try {
            $affected = $this->noteIds($conn, $tagId);

            if ($into !== null) {
                // ON CONFLICT rather than a NOT EXISTS filter: a note may
                // already carry BOTH tags, and that is the ordinary case when
                // somebody has been tagging with two words for the same idea —
                // which is precisely why they are merging them.
                $conn->executeStatement(
                    'INSERT INTO note_tag (note_id, tag_id)
                     SELECT nt.note_id, :into FROM note_tag nt WHERE nt.tag_id = :from
                     ON CONFLICT DO NOTHING',
                    ['into' => (int) $into->getId(), 'from' => $tagId],
                );
            }

            $conn->executeStatement('DELETE FROM note_tag WHERE tag_id = :tag', ['tag' => $tagId]);

            if ($affected !== []) {
                $conn->executeStatement(
                    'UPDATE notes SET updated_at = :now, version = version + 1 WHERE id IN (:ids)',
                    ['ids' => $affected, 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
                    ['ids' => ArrayParameterType::INTEGER],
                );
            }

            // Replaces any earlier record for the same name — a tag can be
            // retired, brought back by hand, and retired again, and the row
            // should describe the LAST time, not the first.
            $conn->executeStatement(
                'INSERT INTO retired_tags (name, note_count, merged_into, retired_at)
                 VALUES (:name, :count, :into, :now)
                 ON CONFLICT (name) DO UPDATE
                 SET note_count = EXCLUDED.note_count,
                     merged_into = EXCLUDED.merged_into,
                     retired_at = EXCLUDED.retired_at',
                [
                    'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    'name' => $name,
                    'count' => count($affected),
                    'into' => $into?->getName(),
                ],
            );

            // The owner's name, and no token: this is a person's act. Written
            // through the entity so the row cannot drift from every other one
            // in the journal.
            $this->em->persist((new CuratorLogEntry(
                $byName,
                $into === null ? CuratorLogEntry::ACTION_TAG_REMOVED : CuratorLogEntry::ACTION_TAG_MERGED,
                $into === null
                    ? 'Removed the tag “'.$name.'” from '.count($affected).' note(s).'
                    : 'Merged the tag “'.$name.'” into “'.$into->getName().'” across '.count($affected).' note(s).',
            ))->withAffectedNotes($affected));
            // Saved filters hold tags by id, so one naming this tag would be
            // left filtering on nothing and match the whole notes list. Read
            // fresh inside the write transaction: two merges at once each
            // rewrite a filter's whole list, and the second must start from
            // the first one's result.
            $presets = $this->em->createQueryBuilder()
                ->select('p')->from(SearchPreset::class, 'p')
                ->getQuery()
                ->setHint(Query::HINT_REFRESH, true)
                ->getResult();
            foreach ($presets as $preset) {
                $preset->retireTag($tagId, $into?->getId());
            }
            // The tag row goes through the ORM rather than through SQL, so
            // Doctrine stops holding an entity whose row no longer exists —
            // and it goes after the note_tag rows, which reference it.
            $this->em->remove($tag);
            $this->em->flush();

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();

            throw $e;
        }

        // The notes were changed in SQL, so any that the caller is still
        // holding are now wrong about their tags and their version. Only the
        // ones actually in the identity map are refreshed — clearing the whole
        // map would be cheaper to write and would detach the caller's own
        // entities out from under it, which is a rude thing for a service to
        // do to whoever called it.
        $unit = $this->em->getUnitOfWork();
        foreach ($affected as $noteId) {
            $managed = $unit->tryGetById($noteId, Note::class);
            if ($managed instanceof Note) {
                $this->em->refresh($managed);
            }
        }

        return count($affected);
    }

    /** @return int[] */
    private function noteIds(Connection $conn, int $tagId): array
    {
        return array_map(
            static fn (array $r) => (int) $r['note_id'],
            $conn->fetchAllAssociative('SELECT note_id FROM note_tag WHERE tag_id = :tag', ['tag' => $tagId]),
        );
    }
}
