<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The version this code was deployed as. The deploy writes `RELEASE` from
 * `deploy/release-version.mjs`; a checkout nobody released has none.
 */
final class ReleaseVersion
{
    public const UNRELEASED = 'unreleased';

    public function __construct(
        #[Autowire('%kernel.project_dir%/RELEASE')]
        private readonly string $file,
    ) {
    }

    public function current(): string
    {
        $stamp = is_file($this->file) ? trim((string) file_get_contents($this->file)) : '';

        return $stamp === '' ? self::UNRELEASED : $stamp;
    }
}
