<?php

declare(strict_types=1);

namespace App\Service;

class SkillLint
{
    public const DESCRIPTION_MIN = 40;
    public const DESCRIPTION_MAX = 1024;
    public const BODY_MAX_TOKENS = 5000;

    /** @return list<array{code:string, message:string}> */
    public function findings(array $row, array $usage): array
    {
        if ($row['kind'] !== 'note') {
            return [];
        }
        $out = [];
        $description = trim((string) $row['description']);
        if ($description === '') {
            $out[] = ['code' => 'no_description', 'message' => 'No description: an assistant has nothing to match a task against.'];
        } elseif (mb_strlen($description) < self::DESCRIPTION_MIN) {
            $out[] = ['code' => 'description_short', 'message' => 'The description is under 40 characters; say what the skill does and when to use it.'];
        } elseif (mb_strlen($description) > self::DESCRIPTION_MAX) {
            $out[] = ['code' => 'description_long', 'message' => 'The description is over 1,024 characters, the most a skill listing carries.'];
        }
        if ((int) ceil(strlen((string) $row['body']) / 4) > self::BODY_MAX_TOKENS) {
            $out[] = ['code' => 'body_long', 'message' => 'Over 5,000 tokens: an assistant loads all of it every time. Move detail into a linked note.'];
        }
        if (preg_match('/-\d+$/', (string) $row['slug']) === 1 && preg_match('/-\d+$/', SkillSlug::fromTitle((string) $row['title'])) !== 1) {
            $out[] = ['code' => 'slug_suffixed', 'message' => 'The slug carries a number because another skill took the name. Rename one of them.'];
        }
        $servedSince = strtotime((string) $row['updated_at']) ?: time();
        if ($row['status'] === 'served' && ($usage['total_30d'] ?? 0) === 0 && $servedSince < strtotime('-30 days')) {
            $out[] = ['code' => 'unused', 'message' => 'No assistant has loaded this in 30 days.'];
        }

        return $out;
    }
}
