<?php

declare(strict_types=1);

namespace App\Storage;

final class NoVaultBound extends \LogicException
{
    public function __construct()
    {
        parent::__construct('No vault is bound: vault data is reachable only after authenticating as a vault owner, or inside VaultScope::run().');
    }
}
