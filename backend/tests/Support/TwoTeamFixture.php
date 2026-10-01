<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Container\ContainerInterface;

/**
 * Two tenants, each with its own account, vault, connections and notes.
 * Symmetric on purpose: B is a real vault, not a decoy, because the bugs worth
 * catching are the ones where B's request reaches A's data. It leaves no vault
 * entered, so test code names the one it reads.
 */
final class TwoTeamFixture
{
    public Tenant $a;
    public Tenant $b;

    public function __construct(ContainerInterface $container)
    {
        $this->a = new Tenant($container, 'A');
        $this->b = new Tenant($container, 'B');
        Vaults::leave($container);
    }
}
