<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'skill_serves')]
#[ORM\Index(name: 'idx_skill_serves_time', columns: ['served_at'])]
#[ORM\Index(name: 'idx_skill_serves_slug_time', columns: ['slug', 'served_at'])]
#[ORM\Index(name: 'skill_serves_token_slug_version', columns: ['token_id', 'slug', 'version'])]
class SkillServe
{
    public const PATH_TOOL = 'tool';
    public const PATH_PROMPT = 'prompt';
    public const PATH_RESOURCE = 'resource';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $slug;

    #[ORM\ManyToOne(targetEntity: ApiToken::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ApiToken $token = null;

    #[ORM\ManyToOne(targetEntity: CurationPreset::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CurationPreset $preset = null;

    #[ORM\Column(name: 'preset_version', options: ['default' => 0])]
    private int $presetVersion = 0;

    #[ORM\Column(length: 16)]
    private string $path;

    #[ORM\Column]
    private \DateTimeImmutable $servedAt;

    /** A hash of the text served, so Personalization can name the connections holding the current memex-writing. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $version = null;

    public function __construct(string $slug, ?ApiToken $token, ?CurationPreset $preset, string $path, ?string $version = null)
    {
        $this->version = $version;
        $this->slug = $slug;
        $this->token = $token;
        $this->preset = $preset;
        $this->presetVersion = $preset?->getVersion() ?? 0;
        $this->path = $path;
        $this->servedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function getToken(): ?ApiToken { return $this->token; }
    public function getPreset(): ?CurationPreset { return $this->preset; }
    public function getPresetVersion(): int { return $this->presetVersion; }
    public function getPath(): string { return $this->path; }
    public function getServedAt(): \DateTimeImmutable { return $this->servedAt; }
    public function getVersion(): ?string { return $this->version; }
}
