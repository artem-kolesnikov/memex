<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ShippedText;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The guide and the welcome notes read true on the edition serving them. Each
 * edition's tree runs this suite, so each is checked against its own passages.
 */
final class ShippedTextTest extends TestCase
{
    private const CONFIG = __DIR__.'/../../config';

    /** @return array<string, list<string>> passage name => the shipped files asking for it */
    private static function asked(): array
    {
        $asked = [];
        foreach ([...glob(self::CONFIG.'/skills-shipped/*.md'), ...glob(self::CONFIG.'/welcome/*.md')] as $file) {
            foreach (ShippedText::names((string) file_get_contents($file)) as $name) {
                $asked[$name][] = basename($file);
            }
        }

        return $asked;
    }

    public function testThisEditionSuppliesEveryPassageTheShippedTextAsksFor(): void
    {
        $asked = self::asked();
        self::assertNotEmpty($asked);
        foreach ($asked as $name => $files) {
            self::assertFileExists(self::CONFIG.'/edition/text/'.$name.'.md',
                implode(', ', $files).' asks for the passage '.$name.', and without it the guide skips what this edition does there');
        }
    }

    public function testThisEditionSuppliesNoPassageNothingAsksFor(): void
    {
        $asked = self::asked();
        foreach (glob(self::CONFIG.'/edition/text/*.md') as $file) {
            self::assertArrayHasKey(basename($file, '.md'), $asked, basename($file).' is asked for by no shipped text');
        }
    }

    public function testAPassageTakesItsLineAndAnEmptyOneLeavesNoGap(): void
    {
        $dir = sys_get_temp_dir().'/shipped-text-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/said.md', "Said here.\n");
        file_put_contents($dir.'/unsaid.md', '');
        $text = new ShippedText(new RequestStack(), new NullLogger(), $dir, '', true);

        self::assertSame(
            "One.\n\nSaid here.\n\nTwo.\n\n- a\n- b\n",
            $text->render("One.\n\n{{edition:said}}\n\nTwo.\n\n{{edition:unsaid}}\n\n- a\n{{edition:unsaid}}\n- b\n"),
        );
        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }

    public function testWebAndLocalBlocksFollowWhetherTheEditionServesWebAssistants(): void
    {
        $text = "One.\n\n{{web}}\n## ChatGPT\n\nSteps.\n{{/web}}\n\n{{local}}\nOn this computer.\n{{/local}}\n\nTwo.\n";
        $render = static fn (string $base, bool $web): string => (new ShippedText(new RequestStack(), new NullLogger(), '/nowhere', $base, $web))->render($text);

        self::assertSame("One.\n\n## ChatGPT\n\nSteps.\n\nTwo.\n", $render('http://localhost:5100', true), 'memex.tools serves them on a developer machine too');
        self::assertSame("One.\n\nOn this computer.\n\nTwo.\n", $render('http://localhost:8080', false));
        self::assertSame("One.\n\nOn this computer.\n\nTwo.\n", $render('https://memex.example.org', false), 'a local-first edition at a public address is still local-first');
    }

    public function testTheOriginIsTheAddressInUseThenTheConfiguredOneThenLocalhost(): void
    {
        $requests = new RequestStack();
        $requests->push(Request::create('https://memex.example.org/mcp'));
        self::assertSame('https://memex.example.org/mcp', (new ShippedText($requests, new NullLogger(), '/nowhere', 'https://configured.example', true))->render('{{origin}}/mcp'));

        self::assertSame('https://configured.example/mcp', (new ShippedText(new RequestStack(), new NullLogger(), '/nowhere', 'https://configured.example/', true))->render('{{origin}}/mcp'));
        self::assertSame('http://localhost/mcp', (new ShippedText(new RequestStack(), new NullLogger(), '/nowhere', '', true))->render('{{origin}}/mcp'));
    }
}
