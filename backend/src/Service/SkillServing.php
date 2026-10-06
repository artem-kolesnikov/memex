<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;
use App\Entity\CuratorLogEntry;
use App\Entity\Note;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final class SkillSlugTaken extends \RuntimeException
{
}

class SkillServing
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Journal $journal,
    ) {
    }

    private function db(): Connection
    {
        return $this->em->getConnection();
    }

    /** @return list<array{id:int, title:string, summary:?string, body_md:string, status:string, updated_at:string}> */
    public function skillNotes(bool $verifiedOnly = true): array
    {
        $statusClause = $verifiedOnly ? 'AND n.status = :verified' : "AND n.status IN ('verified', 'pending')";

        return $this->db()->fetchAllAssociative(
            "SELECT n.id, n.title, n.summary, n.body_md, n.status, n.updated_at
             FROM notes n
             JOIN note_tag nt ON nt.note_id = n.id
             JOIN tags t ON t.id = nt.tag_id
             WHERE t.name = :tag $statusClause
             ORDER BY n.updated_at DESC, n.id DESC",
            ['tag' => SystemTags::SKILL, 'verified' => Note::STATUS_VERIFIED],
        );
    }

    public function ensureRecords(): void
    {
        $have = $this->settings();
        $taken = array_fill_keys(ShippedSkills::reservedSlugs(), true);
        foreach ($have as $row) {
            $taken[$row['slug']] = true;
        }
        foreach ($this->skillNotes() as $note) {
            if (isset($have[(int) $note['id']])) {
                continue;
            }
            $base = SkillSlug::fromTitle($note['title']);
            do {
                $slug = $base;
                for ($i = 2; isset($taken[$slug]); ++$i) {
                    $slug = rtrim(substr($base, 0, SkillSlug::MAX - strlen('-'.$i)), '-').'-'.$i;
                }
                $taken[$slug] = true;
                $inserted = $this->db()->executeStatement(
                    'INSERT INTO skill_settings (note_id, slug, updated_at) VALUES (:n, :s, :now) ON CONFLICT DO NOTHING',
                    ['n' => $note['id'], 's' => $slug, 'now' => self::now()],
                );
                if ($inserted === 1) {
                    break;
                }
                $have = $this->settings();
                foreach ($have as $row) {
                    $taken[$row['slug']] = true;
                }
            } while (!isset($have[(int) $note['id']]));
        }
    }

    /** @return array<int, array{note_id:int, slug:string, enabled:bool, auto:bool, command:bool, updated_at:string}> */
    public function settings(): array
    {
        $out = [];
        foreach ($this->db()->fetchAllAssociative('SELECT * FROM skill_settings') as $row) {
            $out[(int) $row['note_id']] = [
                'note_id' => (int) $row['note_id'],
                'slug' => $row['slug'],
                'enabled' => (bool) $row['enabled'],
                'auto' => (bool) $row['auto'],
                'command' => (bool) $row['command'],
                'updated_at' => $row['updated_at'],
            ];
        }

        return $out;
    }

    /** @return array<int, list<int>> */
    public function grants(): array
    {
        $out = [];
        $rows = $this->db()->fetchAllAssociative(
            'SELECT g.note_id, g.token_id FROM skill_grants g
             JOIN api_tokens k ON k.id = g.token_id AND k.revoked_at IS NULL
             ORDER BY g.token_id',
        );
        foreach ($rows as $row) {
            $out[(int) $row['note_id']][] = (int) $row['token_id'];
        }

        return $out;
    }

    public function servedTo(array $skill, ?ApiToken $token, ?string $surface): bool
    {
        if ($skill['id'] === null) {
            return true;
        }
        if (!$skill['enabled']) {
            return false;
        }
        if ($surface !== null && !$skill[$surface]) {
            return false;
        }
        if ($token === null || $skill['grants'] === []) {
            return true;
        }

        return in_array($token->getId(), $skill['grants'], true);
    }

    /** @param array{enabled?:bool, auto?:bool, command?:bool, slug?:string, grants?:list<int>} $patch */
    public function update(int $noteId, array $patch): array
    {
        $this->ensureRecords();
        $current = $this->settings()[$noteId] ?? throw new \OutOfBoundsException('No skill with that id');
        $before = $current + ['grants' => $this->grants()[$noteId] ?? []];

        if (array_key_exists('slug', $patch)) {
            $slug = (string) $patch['slug'];
            if (!SkillSlug::isValid($slug)) {
                throw new \InvalidArgumentException('A slug is 1 to 64 lowercase letters, digits and single hyphens');
            }
            $taken = in_array($slug, ShippedSkills::reservedSlugs(), true)
                || $this->db()->fetchOne('SELECT 1 FROM skill_settings WHERE slug = :s AND note_id <> :n', ['s' => $slug, 'n' => $noteId]) !== false;
            if ($taken) {
                throw new SkillSlugTaken('Another skill is already served as '.$slug);
            }
            $current['slug'] = $slug;
        }
        foreach (['enabled', 'auto', 'command'] as $flag) {
            if (array_key_exists($flag, $patch)) {
                $current[$flag] = (bool) $patch[$flag];
            }
        }

        $wanted = null;
        if (array_key_exists('grants', $patch)) {
            $wanted = array_values(array_unique(array_map('intval', (array) $patch['grants'])));
            $live = array_map('intval', $this->db()->fetchFirstColumn(
                'SELECT id FROM api_tokens WHERE revoked_at IS NULL',
            ));
            foreach ($wanted as $tokenId) {
                if (!in_array($tokenId, $live, true)) {
                    throw new \OutOfBoundsException('No connection with id '.$tokenId);
                }
            }
        }

        $conn = $this->db();
        $conn->beginTransaction();
        try {
            $assignments = ['updated_at = :now'];
            $params = ['n' => $noteId, 'now' => self::now()];
            foreach (['slug', 'enabled', 'auto', 'command'] as $field) {
                if (array_key_exists($field, $patch)) {
                    $assignments[] = $field.' = :'.$field;
                    $params[$field] = $field === 'slug' ? $current[$field] : (int) $current[$field];
                }
            }
            $conn->executeStatement(
                'UPDATE skill_settings SET '.implode(', ', $assignments).' WHERE note_id = :n',
                $params,
            );

            if ($wanted !== null) {
                $conn->executeStatement('DELETE FROM skill_grants WHERE note_id = :n', ['n' => $noteId]);
                foreach ($wanted as $tokenId) {
                    $conn->executeStatement(
                        'INSERT INTO skill_grants (note_id, token_id) VALUES (:n, :k)',
                        ['n' => $noteId, 'k' => $tokenId],
                    );
                }
            }
            $after = $this->settings()[$noteId] + ['grants' => $this->grants()[$noteId] ?? []];
            $this->recordChange($noteId, $before, $after);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();

            throw $e;
        }

        return $after;
    }

    /**
     * @param array{slug: string, enabled: bool, auto: bool, command: bool, grants: list<int>} $before
     * @param array{slug: string, enabled: bool, auto: bool, command: bool, grants: list<int>} $after
     */
    private function recordChange(int $noteId, array $before, array $after): void
    {
        $changes = [];
        if ($before['enabled'] !== $after['enabled']) {
            $changes[] = $after['enabled'] ? 'turned on' : 'turned off';
        }
        if ($before['auto'] !== $after['auto']) {
            $changes[] = $after['auto'] ? 'used when relevant' : 'no longer used when relevant';
        }
        if ($before['command'] !== $after['command']) {
            $changes[] = $after['command'] ? 'available as a command' : 'no longer a command';
        }
        if ($before['slug'] !== $after['slug']) {
            $changes[] = 'served as '.$after['slug'];
        }
        $granted = $after['grants'];
        sort($granted);
        $had = $before['grants'];
        sort($had);
        if ($granted !== $had) {
            $names = $granted === [] ? [] : $this->db()->fetchFirstColumn(
                'SELECT COALESCE(display_name, name) FROM api_tokens WHERE id IN (:ids) ORDER BY id',
                ['ids' => $granted],
                ['ids' => ArrayParameterType::INTEGER],
            );
            $changes[] = $names === [] ? 'given to every connection' : 'given only to '.implode(', ', $names);
        }
        if ($changes === []) {
            return;
        }
        $note = $this->em->find(Note::class, $noteId);
        $this->journal->record(
            (new CuratorLogEntry('operator', CuratorLogEntry::ACTION_SKILL_CHANGED, 'Changed the skill “'.$note?->getTitle().'”: '.implode('; ', $changes)))->withNote($note)
        );
        $this->em->flush();
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
