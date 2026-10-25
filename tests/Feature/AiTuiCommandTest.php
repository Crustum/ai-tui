<?php
declare(strict_types=1);

namespace Crustum\AiTui\Test\Feature;

use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleOutput;
use Crustum\AiTui\Command\AiTuiCommand;
use Crustum\AiTui\Test\Feature\Support\CannedAiTuiCommand;
use Crustum\AiTui\Test\Feature\Support\StubTuiAgent;
use PHPUnit\Framework\TestCase;
use function implode;
use function stream_isatty;
use const STDIN;

/**
 * Feature coverage for the host terminal command.
 */
final class AiTuiCommandTest extends TestCase
{
    public function testCommandName(): void
    {
        self::assertSame('ai tui', AiTuiCommand::defaultName());
    }

    public function testTuiRequiresAnAgent(): void
    {
        $error = new StubConsoleOutput();
        $io = new ConsoleIo(new StubConsoleOutput(), $error);
        $command = new CannedAiTuiCommand();

        $code = $command->run(['ai tui'], $io);

        self::assertSame(AiTuiCommand::CODE_ERROR, $code);
        self::assertStringContainsString('agent', implode("\n", $error->messages()));
    }

    public function testTuiRequiresAParticipant(): void
    {
        $error = new StubConsoleOutput();
        $io = new ConsoleIo(new StubConsoleOutput(), $error);
        $command = new CannedAiTuiCommand();

        $code = $command->run(['ai tui', '--agent', StubTuiAgent::class], $io);

        self::assertSame(AiTuiCommand::CODE_ERROR, $code);
        self::assertStringContainsString('--user', implode("\n", $error->messages()));
    }

    public function testTuiRejectsANonUuidParticipant(): void
    {
        $error = new StubConsoleOutput();
        $io = new ConsoleIo(new StubConsoleOutput(), $error);
        $command = new CannedAiTuiCommand();

        $code = $command->run(['ai tui', '--agent', StubTuiAgent::class, '--user', 'console'], $io);

        self::assertSame(AiTuiCommand::CODE_ERROR, $code);
        self::assertStringContainsString('uuid', implode("\n", $error->messages()));
    }

    public function testTuiRefusesWithoutAnInteractiveTerminal(): void
    {
        if (stream_isatty(STDIN)) {
            self::markTestSkipped('The TTY guard only triggers on redirected input.');
        }

        $error = new StubConsoleOutput();
        $io = new ConsoleIo(new StubConsoleOutput(), $error);
        $command = new CannedAiTuiCommand();

        $code = $command->run(
            ['ai tui', '--agent', StubTuiAgent::class, '--user', '01933c1e-8f5c-7c0d-9a1b-2d3e4f5a6b7c'],
            $io,
        );

        self::assertSame(AiTuiCommand::CODE_ERROR, $code);
        self::assertStringContainsString('interactive', implode("\n", $error->messages()));
    }
}
