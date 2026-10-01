<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VaultSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VaultSettings>
 */
final class VaultSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VaultSettings::class);
    }

    public function current(): VaultSettings
    {
        return $this->find(VaultSettings::ID) ?? throw new \RuntimeException('This vault has no settings row.');
    }
}
