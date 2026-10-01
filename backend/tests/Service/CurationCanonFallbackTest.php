<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CurationCharter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * What is served when the shipped canon cannot be read.
 *
 * A deploy that loses `canon.md` used to serve an empty body under a plausible
 * title — indistinguishable from a working charter until an agent improvised a
 * pass, which is the failure this class exists to remove. It shipped without a
 * test, which is the same gap the retirement command shipped with (Codex, on
 * the second review); this closes it.
 *
 * Deliberately not a kernel test: the point is what happens with a path that
 * does not resolve, and the container's path always does.
 */
final class CurationCanonFallbackTest extends TestCase
{
    private function charterOn(string $path, ?object &$logger = null): CurationCharter
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string|\Stringable}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => $message];
            }
        };

        return new CurationCharter(
            $this->createMock(EntityManagerInterface::class),
            $logger,
            $path,
        );
    }

    public function testAMissingCanonIsLoggedAndSaysSoInTheInstructions(): void
    {
        $charter = $this->charterOn('/nonexistent/canon.md', $logger);

        $canon = $charter->canon();

        self::assertNotSame('', $canon['body'],
            'an empty body is served as instructions and reads as a charter that says nothing — '
            .'the agent then improvises, which is exactly the failure this class removes');
        self::assertStringContainsString('Do not curate', $canon['body'],
            'the fallback must tell the agent to stop rather than proceed on its own judgment');
        self::assertStringNotContainsString(CurationCharter::CHARTER_MARKER, $canon['body'],
            'the fallback must not look like the charter it is standing in for');
        self::assertNotSame('', $canon['description'],
            'list_skills shows the description, so a silent one hides the failure from the owner too');

        self::assertNotEmpty($logger->records, 'a broken deploy left no trace anywhere');
        self::assertSame('error', $logger->records[0]['level']);
    }

    /** Malformed is the same failure as absent: frontmatter is what makes it parse. */
    public function testAMalformedCanonFallsBackTheSameWay(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'canon');
        self::assertIsString($file);
        file_put_contents($file, "no frontmatter here, just prose\n");

        try {
            $canon = $this->charterOn($file, $logger)->canon();
            self::assertStringContainsString('Do not curate', $canon['body']);
            self::assertNotEmpty($logger->records);
        } finally {
            unlink($file);
        }
    }

    public function testTheShippedCanonParsesAndIsNotTheFallback(): void
    {
        $canon = $this->charterOn(__DIR__.'/../../config/curation/canon.md', $logger)->canon();

        self::assertStringContainsString(CurationCharter::CHARTER_MARKER, $canon['body']);
        self::assertEmpty($logger->records, 'the shipped canon logged an error, so the fallback is live in production');
    }
}
