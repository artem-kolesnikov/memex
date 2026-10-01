<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Storage\BoundVault;
use App\Storage\VaultContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;

/**
 * Where test code reads and writes. A test works in one vault at a time and
 * names it: entering another clears what the entity managers hold and closes
 * the file, exactly as {@see \App\Storage\VaultScope} does for a job. Every
 * request ends unbound ({@see UnbindAroundRequests}), so test code that forgets
 * to enter a vault after one fails instead of reading whichever vault the
 * request used.
 */
final class Vaults
{
    public static function enter(ContainerInterface $container, BoundVault $vault): void
    {
        $context = $container->get(VaultContext::class);
        if ($context->resolve()?->is($vault)) {
            return;
        }
        $em = $container->get(EntityManagerInterface::class);
        if ($em->isOpen()) {
            $em->clear();
        } else {
            $container->get('doctrine')->resetManager('vault');
        }
        $context->reset();
        $context->bind($vault);
    }

    /** Work in no vault, as a job that walks them all starts. */
    public static function leave(ContainerInterface $container): void
    {
        $context = $container->get(VaultContext::class);
        if ($context->resolve() === null) {
            return;
        }
        $em = $container->get(EntityManagerInterface::class);
        $em->isOpen() ? $em->clear() : $container->get('doctrine')->resetManager('vault');
        $context->reset();
    }
}
