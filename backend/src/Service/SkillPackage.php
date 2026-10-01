<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

class SkillPackage
{
    public function render(array $skill, string $memexVersion): string
    {
        $q = static fn (string $s): string => json_encode($s, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $front = "---\nname: ".$q((string) $skill['slug'])."\n";
        $front .= 'description: '.$q((string) $skill['description'])."\n";
        $front .= "metadata:\n";
        if ($skill['id'] === null) {
            $front .= '  memex_shipped: '.$q($memexVersion)."\n";
        } else {
            $front .= '  memex_note_id: '.$q((string) $skill['id'])."\n";
            $front .= '  memex_updated_at: '.$q((string) $skill['updated_at'])."\n";
        }
        $front .= "---\n\n";

        return $front.rtrim((string) $skill['body'])."\n";
    }

    /** @return array{name:string, title:string, description:string, body:string} */
    public function parse(string $contents, string $fallbackName): array
    {
        $meta = [];
        $body = $contents;
        if (preg_match('/\A---\R(.*?)\R---\R*(.*)\z/s', $contents, $m)) {
            try {
                $meta = Yaml::parse($m[1]);
            } catch (\Symfony\Component\Yaml\Exception\ParseException $e) {
                throw new \InvalidArgumentException('Invalid skill frontmatter: '.$e->getMessage(), previous: $e);
            }
            if (!is_array($meta)) {
                throw new \InvalidArgumentException('Skill frontmatter must be a YAML mapping');
            }
            $body = $m[2];
        } elseif (preg_match('/\A---\R/', $contents)) {
            throw new \InvalidArgumentException('Skill frontmatter needs a closing --- line');
        }
        if (array_key_exists('name', $meta) && (!is_string($meta['name']) || !SkillSlug::isValid($meta['name']))) {
            throw new \InvalidArgumentException('A skill name must be 1 to 64 lowercase letters, digits and single hyphens');
        }
        $title = is_string($meta['title'] ?? null) && trim($meta['title']) !== '' ? trim($meta['title']) : null;
        $name = is_string($meta['name'] ?? null) && SkillSlug::isValid(trim($meta['name'])) ? trim($meta['name']) : null;
        if ($name === null) {
            $name = SkillSlug::fromTitle($title ?? $fallbackName);
        }
        $title ??= ucfirst(str_replace('-', ' ', $name));
        $description = is_string($meta['description'] ?? null) ? trim($meta['description']) : '';

        return ['name' => $name, 'title' => $title, 'description' => $description, 'body' => $body];
    }
}
