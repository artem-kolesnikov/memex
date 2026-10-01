<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * A signed-in principal that owns a vault. The only way a request comes to
 * work in a vault is by authenticating as one of these.
 */
interface VaultOwner
{
    public function vault(): BoundVault;
}
