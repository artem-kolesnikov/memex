<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * memex's documentation, served to every assistant as the `memex-docs` skill.
 * Each edition supplies its own file in `config/edition/skills/`: memex.tools's
 * is generated from the docs.memex.tools pages by `landing/build.mjs`, and
 * self-hosted memex's points at those pages. It is never copied into a
 * knowledge base: new accounts open with the welcome notes
 * ({@see WelcomeNotes}) instead, so it stays one text that is always current.
 */
class UserGuide
{
    public const SLUG = 'memex-docs';
    /** The skill's earlier name, still answered to for assistants' memories and older notes, and never listed. */
    public const ALIAS = 'memex-guide';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ShippedText $text,
        #[Autowire('%kernel.project_dir%/config/edition/skills')]
        private readonly string $editionSkillsDir,
    ) {
    }

    public function path(): string
    {
        return rtrim($this->editionSkillsDir, '/').'/'.self::SLUG.'.md';
    }

    /** @return array{title: string, description: string, body: string}|null */
    public function shipped(): ?array
    {
        $path = $this->path();
        $raw = is_readable($path) ? file_get_contents($path) : false;
        $parsed = $raw === false ? null : SkillLibrary::parseSkillFile($raw);
        if ($parsed === null) {
            $this->logger->error('The shipped docs could not be read', ['file' => $path]);

            return null;
        }

        return ['title' => $parsed['title'], 'description' => $parsed['description'], 'body' => $this->text->render($parsed['body'])];
    }

    /** Eight hex characters of the shipped body's hash; changes when a word does. */
    public function version(): ?string
    {
        $shipped = $this->shipped();

        return $shipped === null ? null : substr(hash('sha256', $shipped['body']), 0, 8);
    }
}
