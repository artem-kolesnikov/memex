<?php

declare(strict_types=1);

namespace App\Storage;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class DataDir
{
    public function __construct(#[Autowire(env: 'MEMEX_DATA_DIR')] private readonly string $root)
    {
        if ($root === '' || !str_starts_with($root, '/')) {
            throw new \InvalidArgumentException('MEMEX_DATA_DIR must be an absolute path.');
        }
    }

    public function root(): string
    {
        return rtrim($this->root, '/');
    }

    public function directoryPath(): string
    {
        return $this->root().'/directory.sqlite';
    }

    public function vaultsDir(): string
    {
        return $this->root().'/vaults';
    }

    public function vaultPath(string $key): string
    {
        VaultKey::assertValid($key);

        return $this->vaultsDir().'/'.$key.'.sqlite';
    }
}
