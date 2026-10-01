<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ReviewSnapshot
{
    public static function version(array $data, string $key = 'expected_version'): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value) || $value < ($key === 'expected_revision' ? 0 : 1)) {
            throw new BadRequestHttpException($key.' must be a valid integer snapshot value. Reload this page before approving.');
        }
        return $value;
    }

    public static function proposal(array $data, bool $merge): array
    {
        $snapshot = [
            'expected_revision' => self::version($data, 'expected_revision'),
            'expected_version' => self::version($data),
        ];
        if ($merge) {
            $snapshot['expected_merge_version'] = self::version($data, 'expected_merge_version');
        }
        return $snapshot;
    }
}
