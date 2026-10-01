<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * The user guide memex ships, served to every assistant as the `memex-guide`
 * skill. It is never copied into a knowledge base: new accounts open with the
 * welcome notes ({@see WelcomeNotes}) instead, and the guide stays one text
 * that is always current.
 */
class UserGuide
{
    public const SLUG = 'memex-guide';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ShippedText $text,
        private readonly string $shippedSkillsDir,
    ) {
    }

    /** @return array{title: string, description: string, body: string}|null */
    public function shipped(): ?array
    {
        $path = rtrim($this->shippedSkillsDir, '/').'/'.self::SLUG.'.md';
        $raw = is_readable($path) ? file_get_contents($path) : false;
        $parsed = $raw === false ? null : SkillLibrary::parseSkillFile($raw);
        if ($parsed === null) {
            $this->logger->error('The shipped user guide could not be read', ['file' => $path]);

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
