<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Tests\Support\ConnectionProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clients never see internal error text. The sweep runs while something
 * underneath fails with text only the box should read: the vault or the
 * directory throwing a driver error that names a file, or every outbound
 * call to ml-processor and the AI providers failing the way the transport
 * reports it. Whatever the client is told, that text is not in it.
 */
final class NoInternalErrorTextTest extends DesignSweepTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function failures(): iterable
    {
        yield 'every vault statement fails' => ['vault', 'all'];
        yield 'every vault write fails' => ['vault', 'writes'];
        yield 'every directory statement fails' => ['directory', 'all'];
        yield 'every directory write fails' => ['directory', 'writes'];
    }

    #[DataProvider('failures')]
    public function testADatabaseFailureReachesNoClient(string $connection, string $mode): void
    {
        $leaks = [];
        $failed = 0;

        $this->sweep(
            $this->kb->a,
            function (string $label, Response $response) use (&$leaks, &$failed): void {
                $failed += $response->getStatusCode() >= 500 ? 1 : 0;
                foreach ($this->internalTextIn(self::received($response)) as $text) {
                    $leaks[] = "$label: $text";
                }
            },
            static fn () => ConnectionProbe::failFromController($connection, $mode),
        );

        self::assertGreaterThan(0, $failed, 'Nothing failed, so the sweep proved nothing');
        self::assertSame([], $leaks);
    }

    /** @return iterable<string, array{int|string}> */
    public static function outages(): iterable
    {
        yield 'unreachable' => ['throw'];
        yield 'answering 500' => [500];
    }

    #[DataProvider('outages')]
    public function testAnOutboundFailureReachesNoClient(int|string $how): void
    {
        $leaks = [];
        $this->ml->failWith = [':8201' => $how, 'api.openai.com' => $how, 'api.anthropic.com' => $how, 'generativelanguage.googleapis.com' => $how];

        $this->sweep($this->kb->a, function (string $label, Response $response) use (&$leaks): void {
            foreach ($this->internalTextIn(self::received($response)) as $text) {
                $leaks[] = "$label: $text";
            }
        });

        self::assertNotSame([], $this->ml->calls, 'Nothing called out, so the sweep proved nothing');
        self::assertSame([], $leaks);
    }

    /** @return list<string> */
    private function internalTextIn(string $received): array
    {
        $markers = [
            'probe-fault-7f3a', 'disk I/O error', '/srv/memex', 'SQLSTATE', 'An exception occurred',
            'Doctrine\\', 'Stack trace', '.php', 'Failed to connect', "Couldn't connect", '127.0.0.1:8201',
            'forced by the test', (string) getenv('MEMEX_DATA_DIR'),
        ];

        return array_values(array_filter($markers, static fn (string $marker): bool => str_contains($received, $marker)));
    }
}
