<?php

declare(strict_types=1);

namespace App\Tests\Standalone;

use App\Directory\Account;
use App\Entity\Note;
use App\Service\BearerTokens;
use App\Standalone\FirstRun;
use App\Standalone\OwnerPassword;
use App\Storage\VaultScope;
use App\Tests\Support\TestData;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/** What whoever runs the server does from a shell: first run, a lost password, a local assistant. */
final class OwnerConsoleTest extends KernelTestCase
{
    private const EMAIL = 'owner@example.org';
    private const PASSWORD = 'correct horse battery staple';

    protected function setUp(): void
    {
        parent::setUp();
        TestData::fresh();
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        putenv('MEMEX_TOKEN');
        parent::tearDown();
        TestData::discard();
    }

    public function testTheSetupCodeIsPrintedUntilTheAccountExists(): void
    {
        $tester = $this->command('app:setup-code');
        $tester->execute([]);
        self::assertMatchesRegularExpression('/Setup code: [A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}/', $tester->getDisplay());
        self::assertStringContainsString(static::getContainer()->get(FirstRun::class)->code(), $tester->getDisplay(), 'the same code until it is used');
        self::assertStringContainsString('For the next 10 minutes it needs no code', $tester->getDisplay());

        static::getContainer()->get('doctrine.dbal.directory_connection')->executeStatement('UPDATE setup_code SET created_at = :at', [
            'at' => (new \DateTimeImmutable('-'.(FirstRun::OPEN_FOR_MINUTES + 1).' minutes'))->format('Y-m-d H:i:s'),
        ]);
        $tester->execute([]);
        self::assertStringContainsString('enter it to create your account', $tester->getDisplay());

        $this->owner();
        $tester->execute([]);
        self::assertStringNotContainsString('Setup code', $tester->getDisplay());
    }

    public function testTheAccountCanBeCreatedFromAShell(): void
    {
        $tester = $this->command('app:create-owner');
        $tester->setInputs([self::PASSWORD]);

        self::assertSame(0, $tester->execute(['email' => self::EMAIL], ['interactive' => false]));
        $owner = static::getContainer()->get(FirstRun::class)->owner();
        self::assertSame(self::EMAIL, $owner?->getEmail());
        self::assertTrue(static::getContainer()->get(OwnerPassword::class)->verify($owner, self::PASSWORD));
    }

    public function testAForgottenPasswordIsReplacedFromAShell(): void
    {
        $owner = $this->owner();
        $tester = $this->command('app:reset-password');

        $tester->setInputs(['']);
        self::assertSame(1, $tester->execute([], ['interactive' => false]));

        $tester->setInputs(['x']);
        self::assertSame(0, $tester->execute([], ['interactive' => false]));
        $passwords = static::getContainer()->get(OwnerPassword::class);
        self::assertFalse($passwords->verify($owner, self::PASSWORD));
        self::assertTrue($passwords->verify($owner, 'x'), 'any password but an empty one');
    }

    public function testALocalAssistantSpeaksMcpOverStdioAsItsConnection(): void
    {
        $owner = $this->owner();
        [, $token] = static::getContainer()->get(VaultScope::class)->run(
            $owner->vault(),
            static fn (): array => static::getContainer()->get(BearerTokens::class)->issue($owner, 'Claude Desktop'),
        );
        putenv('MEMEX_TOKEN='.$token);

        $tester = $this->command('app:mcp-stdio');
        $tester->setInputs([
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]),
            json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']),
            json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'health', 'arguments' => []]]),
        ]);

        self::assertSame(0, $tester->execute([], ['interactive' => false]));
        $lines = array_values(array_filter(explode("\n", $tester->getDisplay())));
        self::assertCount(2, $lines, 'one line per answer, and none for the notification');
        self::assertSame('2025-06-18', json_decode($lines[0], true)['result']['protocolVersion']);
        self::assertSame('Claude Desktop', json_decode($lines[1], true)['result']['structuredContent']['authenticated_as']);
    }

    /**
     * Claude Desktop keeps the process for as long as it runs, so a change made
     * in a browser meanwhile has to reach the next message, not the next restart.
     */
    public function testEachMessageSeesTheAccountAsItIsNow(): void
    {
        $owner = $this->owner();
        $scope = static::getContainer()->get(VaultScope::class);
        [, $token] = $scope->run($owner->vault(), static fn (): array => static::getContainer()->get(BearerTokens::class)->issue($owner, 'Claude Desktop'));
        $noteId = $scope->run($owner->vault(), static function (): int {
            foreach (static::getContainer()->get(EntityManagerInterface::class)->getRepository(Note::class)->findAll() as $note) {
                if ($note->isLastWrittenByOwner()) {
                    return (int) $note->getId();
                }
            }
            self::fail('no note the owner wrote');
        });
        putenv('MEMEX_TOKEN='.$token);

        $get = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get', 'arguments' => ['id' => $noteId]]]);
        LineByLine::$lines = [$get, $get];
        LineByLine::$before = [1 => static fn () => static::getContainer()->get('doctrine.dbal.directory_connection')->executeStatement("UPDATE accounts SET name = 'Renamed meanwhile'")];
        $input = new ArrayInput([]);
        $input->setStream(fopen(LineByLine::open(), 'r'));
        $input->setInteractive(false);
        $output = new BufferedOutput();

        self::assertSame(0, (new Application(self::$kernel))->find('app:mcp-stdio')->run($input, $output));
        $names = array_map(
            static fn (string $line): string => json_decode($line, true)['result']['structuredContent']['edited_by']['name'],
            array_values(array_filter(explode("\n", $output->fetch()))),
        );
        self::assertSame(['owner', 'Renamed meanwhile'], $names);
    }

    public function testWithoutATokenStdioServesNothing(): void
    {
        $this->owner();
        putenv('MEMEX_TOKEN=mxt_nothing');
        $tester = $this->command('app:mcp-stdio');
        $tester->setInputs([json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])]);

        self::assertSame(1, $tester->execute([], ['interactive' => false]));
        self::assertStringNotContainsString('"result"', $tester->getDisplay());
    }

    private function owner(): Account
    {
        return static::getContainer()->get(FirstRun::class)->createOwner(self::EMAIL, self::PASSWORD);
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application(self::$kernel))->find($name));
    }
}

/** A stream that hands over one line per read, running a test's step before a given line. */
final class LineByLine
{
    /** @var string[] */
    public static array $lines = [];

    /** @var array<int, callable> */
    public static array $before = [];

    public mixed $context;

    private int $at = 0;

    public static function open(): string
    {
        if (!\in_array('line-by-line', stream_get_wrappers(), true)) {
            stream_wrapper_register('line-by-line', self::class);
        }

        return 'line-by-line://stdin';
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        return true;
    }

    public function stream_read(int $count): string|false
    {
        if (!isset(self::$lines[$this->at])) {
            return '';
        }
        if (isset(self::$before[$this->at])) {
            (self::$before[$this->at])();
        }

        return self::$lines[$this->at++]."\n";
    }

    public function stream_eof(): bool
    {
        return !isset(self::$lines[$this->at]);
    }
}
