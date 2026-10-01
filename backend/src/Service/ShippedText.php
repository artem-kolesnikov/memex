<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Text memex ships for every edition (the guide, the welcome notes), made to
 * read true on the server serving it. A line `{{edition:<name>}}` is replaced
 * by `config/edition/text/<name>.md`, which each edition supplies, so what is
 * true of one edition only stays in that edition's files; an empty passage
 * removes the line and the blank line above it. `{{origin}}` becomes this
 * server's own address. Lines between `{{web}}` and `{{/web}}` are kept only
 * where ChatGPT, Claude and Gemini can reach this server, and those between
 * `{{local}}` and `{{/local}}` only where they cannot ({@see PublicAddress}).
 */
final class ShippedText
{
    public const ORIGIN = '{{origin}}';
    private const PASSAGE = '/^(\n?)\{\{edition:([a-z0-9-]+)\}\}\n?/m';
    private const REACH = '/^(\n?)\{\{(web|local)\}\}\n(.*?)^\{\{\/\2\}\}\n?/ms';

    public function __construct(
        private readonly RequestStack $requests,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/config/edition/text')]
        private readonly string $passagesDir,
        #[Autowire(env: 'APP_BASE_URL')]
        private readonly string $appBaseUrl,
        private readonly PublicAddress $publicAddress,
    ) {
    }

    public function render(string $text): string
    {
        return str_replace(self::ORIGIN, $this->origin(), $this->passages($this->reach($text)));
    }

    private function reach(string $text): string
    {
        $web = $this->publicAddress->reachableFromTheWeb();

        return (string) preg_replace_callback(self::REACH, static fn (array $m): string => ($m[2] === 'web') === $web ? $m[1].$m[3] : '', $text);
    }

    private function passages(string $text): string
    {
        return (string) preg_replace_callback(self::PASSAGE, function (array $m): string {
            $passage = $this->passage($m[2]);

            return $passage === '' ? '' : $m[1].$passage;
        }, $text);
    }

    /** @return list<string> the passages a text asks for */
    public static function names(string $text): array
    {
        preg_match_all(self::PASSAGE, $text, $m);

        return array_values(array_unique($m[2]));
    }

    /** The address the person is using, else `APP_BASE_URL`, else localhost. */
    public function origin(): string
    {
        $request = $this->requests->getMainRequest();
        if ($request !== null) {
            return $request->getSchemeAndHttpHost();
        }
        $base = rtrim(trim($this->appBaseUrl), '/');

        return $base !== '' ? $base : 'http://localhost';
    }

    private function passage(string $name): string
    {
        $path = $this->passagesDir.'/'.$name.'.md';
        $text = is_readable($path) ? file_get_contents($path) : false;
        if ($text === false) {
            $this->logger->error('An edition passage is missing', ['passage' => $name, 'file' => $path]);

            return '';
        }
        $text = rtrim($text, "\n");

        return $text === '' ? '' : $text."\n";
    }
}
