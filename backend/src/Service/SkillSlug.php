<?php

declare(strict_types=1);

namespace App\Service;

final class SkillSlug
{
    public const MAX = 64;
    public const PATTERN = '/\A[a-z0-9]+(-[a-z0-9]+)*\z/';

    public static function isValid(string $slug): bool
    {
        return strlen($slug) <= self::MAX && preg_match(self::PATTERN, $slug) === 1;
    }

    public static function fromTitle(string $title): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($title)) ?? '', '-');
        if ($slug === '') {
            return 'skill';
        }
        if (strlen($slug) > self::MAX) {
            $slug = rtrim(substr($slug, 0, self::MAX), '-');
        }

        return $slug;
    }
}
