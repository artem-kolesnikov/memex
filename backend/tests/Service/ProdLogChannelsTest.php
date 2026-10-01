<?php

declare(strict_types=1);

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Symfony's HTTP client logs every request's full URL, and Telegram's Bot API
 * carries the bot token in the URL path. On the box the fingers_crossed buffer
 * wrote those lines out whenever an error followed a send, so prod.log held
 * the token. No prod handler may take the `http_client` channel. The suite runs
 * in the test environment and cannot see prod's handlers, so this reads them.
 */
final class ProdLogChannelsTest extends TestCase
{
    public function testNoProdHandlerWritesOutboundRequestUrls(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 2).'/config/packages/monolog.yaml');
        $handlers = $config['when@prod']['monolog']['handlers'] ?? [];
        self::assertNotEmpty($handlers, 'No prod handlers in monolog.yaml');

        $children = array_filter(array_column($handlers, 'handler'));

        foreach ($handlers as $name => $handler) {
            if (\in_array($name, $children, true)) {
                continue;
            }
            $channels = $handler['channels'] ?? [];
            $inclusive = array_filter($channels, static fn (string $c) => !str_starts_with($c, '!'));

            if ($inclusive !== []) {
                self::assertNotContains('http_client', $inclusive, "Prod handler \"$name\" takes the http_client channel");
            } else {
                self::assertContains('!http_client', $channels, "Prod handler \"$name\" takes the http_client channel");
            }
        }
    }
}
