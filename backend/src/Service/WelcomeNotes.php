<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Note;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The notes a new knowledge base opens with, ahead of anything its owner
 * writes: markdown files in `config/welcome/`, read in file-name order and
 * copied once, at sign-up, as notes memex wrote. From then on they are the
 * owner's, and memex never rewrites them.
 *
 * `{{base}}` in a body becomes `/<handle>`, so a link to a page of the app
 * opens this account's page; a handle never changes, so the link holds.
 * The rest is {@see ShippedText}'s: `{{origin}}` is this server's address.
 */
class WelcomeNotes
{
    public const BASE = '{{base}}';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NoteWriter $writer,
        private readonly LoggerInterface $logger,
        private readonly ShippedText $text,
        private readonly string $welcomeNotesDir,
    ) {
    }

    /** @return list<array{title: string, description: string, body: string}> in reading order */
    public function shipped(): array
    {
        $files = glob(rtrim($this->welcomeNotesDir, '/').'/*.md');
        if ($files === false) {
            return [];
        }
        sort($files);

        $notes = [];
        foreach ($files as $file) {
            $parsed = SkillLibrary::parseSkillFile((string) file_get_contents($file));
            if ($parsed === null) {
                $this->logger->error('A welcome note could not be read', ['file' => $file]);
                continue;
            }
            $notes[] = ['title' => $parsed['title'], 'description' => $parsed['description'], 'body' => $parsed['body']];
        }

        return $notes;
    }

    /**
     * Written last first, so the list, newest change first, opens on the first.
     * Nothing is bought: the embed sweep indexes the notes like any import.
     *
     * @return list<Note>
     */
    public function seed(string $handle): array
    {
        return $this->em->wrapInTransaction(function () use ($handle): array {
            $notes = [];
            foreach (array_reverse($this->shipped()) as $shipped) {
                $note = $this->writer->create(
                    null,
                    $shipped['title'],
                    $this->text->render(str_replace(self::BASE, '/'.$handle, $shipped['body'])),
                    Note::SOURCE_MEMEX,
                    null,
                    [],
                    null,
                    summary: $shipped['description'],
                    summaryBy: Note::ACTOR_MEMEX,
                )['note'];
                $note->attribute(Note::ACTOR_MEMEX, null);
                $notes[] = $note;
            }
            $this->em->flush();

            return array_reverse($notes);
        });
    }
}
