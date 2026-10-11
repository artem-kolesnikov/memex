<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EditProposal;
use App\Entity\Note;
use App\Entity\NoteRevision;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class SkillNotUnlockable extends \RuntimeException
{
}

final class SkillUnlockConflict extends \RuntimeException
{
}

/**
 * The owner's two verbs on a system skill: unlock it into a note of theirs,
 * and put memex's own text back into that note. Both are the owner's, from a
 * browser; the note is written as the editor writes, so the journal and the
 * history carry them like any other save.
 */
class SkillUnlocking
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShippedSkills $shipped,
        private readonly UnlockedSkills $unlocked,
        private readonly NoteWriter $writer,
        private readonly NoteLimbo $limbo,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * memex's text into a note of the owner's, served under the alias from now
     * on: the copy a restore locked when there is one, as a new version of it,
     * otherwise a new note.
     */
    public function unlock(string $slug): Note
    {
        $original = $this->shipped->original($slug) ?? throw new SkillNotUnlockable('memex has no skill called '.$slug.' that can be unlocked');
        if (isset($this->unlocked->served()[$slug])) {
            throw new SkillUnlockConflict('This skill is already unlocked');
        }

        $lockedId = $this->unlocked->lockedCopy($slug);
        $locked = $lockedId === null ? null : $this->em->find(Note::class, $lockedId);
        if ($locked !== null) {
            $this->rewrite($locked, $original, 'Unlocked again from memex\'s version', fn () => $this->unlocked->reopen($lockedId, UnlockedSkills::hash($original)));
            $this->embed($locked, $slug);

            return $locked;
        }

        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $note = $this->writer->create(
                null,
                $original['title'],
                $original['body'],
                Note::SOURCE_MANUAL,
                null,
                [SystemTags::SKILL],
                null,
                summary: $original['description'],
                applyTags: false,
            )['note'];
            $this->unlocked->record($slug, (int) $note->getId(), UnlockedSkills::hash($original));
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
        $this->embed($note, $slug);

        return $note;
    }

    /**
     * memex's own text served again and kept current by memex: the copy takes
     * memex's text as a new version, so the edits stay in its history, and is
     * locked until the owner unlocks it again.
     */
    public function restore(string $slug): Note
    {
        $original = $this->shipped->original($slug) ?? throw new SkillNotUnlockable('memex has no skill called '.$slug.' that can be unlocked');
        $noteId = $this->unlocked->served()[$slug]['note_id'] ?? null;
        $note = $noteId === null ? null : $this->em->find(Note::class, $noteId);
        if ($note === null) {
            throw new SkillNotUnlockable('This skill is not unlocked');
        }

        $this->rewrite($note, $original, 'Restored memex\'s version', fn () => $this->unlocked->lock($noteId));
        $this->embed($note, $slug);

        return $note;
    }

    /**
     * memex's text as a new version of the copy, and the record's change with
     * it in one transaction.
     *
     * @param array{title: string, description: string, body: string} $original
     * @param callable(): void $record
     */
    private function rewrite(Note $note, array $original, string $changeTitle, callable $record): void
    {
        $this->limbo->whileHoldingNotes([$note], function () use ($note, $original, $changeTitle, $record): void {
            $held = $this->em->getRepository(EditProposal::class)->findBy(['note' => $note, 'status' => EditProposal::STATUS_HELD]);
            if ($held !== []) {
                throw new SkillUnlockConflict('An assistant\'s change to this skill is waiting in Review Inbox. Decide it first.');
            }
            $this->writer->update(
                $note,
                $original['title'],
                $original['body'],
                null,
                null,
                summaryProvided: true,
                summary: $original['description'],
                actor: Note::actorFor(null, null),
                changeTitle: $changeTitle,
                operation: NoteRevision::OP_EDIT,
            );
            $record();
        });
    }

    // Bought after the write, as the editor's save is: the copy is served
    // whether or not its embedding can be paid for today.
    private function embed(Note $note, string $slug): void
    {
        try {
            $this->writer->finishUpdateEnrichment($note, true, false, EmbeddingSpend::Metered, null);
        } catch (\Throwable $e) {
            $this->logger->warning('An unlocked skill was not embedded', ['slug' => $slug, 'error' => $e->getMessage()]);
        }
    }
}
