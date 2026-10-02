<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ShippedText;
use App\Service\SkillLibrary;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * *Connect your first assistant* is copied into every new account. On an
 * edition that does not serve ChatGPT, Claude and Gemini, its steps for them
 * could only fail, so the note there gives the assistants beside it alone.
 */
final class WelcomeConnectNoteTest extends TestCase
{
    private const NOTE = __DIR__.'/../../config/welcome/2-connect-your-first-assistant.md';

    private static function render(string $base, bool $web): string
    {
        $parsed = SkillLibrary::parseSkillFile((string) file_get_contents(self::NOTE));
        self::assertNotNull($parsed);
        $text = new ShippedText(new RequestStack(), new NullLogger(), sys_get_temp_dir(), $base, $web);

        return $text->render($parsed['body']);
    }

    public function testWithoutWebAssistantsTheNoteGivesOnlyTheAssistantsBesideIt(): void
    {
        $note = self::render('http://localhost:8080', false);

        foreach (['## ChatGPT', '## Claude', '## Gemini', 'chatgpt.com', 'claude.ai', 'gemini.google.com', 'choose **Other**'] as $web) {
            self::assertStringNotContainsString($web, $note);
        }
        self::assertStringContainsString('## Your assistant', $note);
        self::assertStringContainsString('`http://localhost:8080/mcp`', $note);
        self::assertStringNotContainsString('{{', str_replace('{{base}}', '', $note));
    }

    public function testWithWebAssistantsTheNoteGivesEveryAssistant(): void
    {
        $note = self::render('https://memex.example.org', true);

        foreach (['## ChatGPT', '## Claude', '## Gemini Spark', '## An agent or a script', 'choose **Other**'] as $section) {
            self::assertStringContainsString($section, $note);
        }
        self::assertStringNotContainsString('## Your assistant', $note);
        self::assertStringNotContainsString('{{', str_replace('{{base}}', '', $note));
    }
}
