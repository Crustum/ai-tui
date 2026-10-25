<?php
declare(strict_types=1);

namespace Crustum\AiTui\Test\Feature;

use Closure;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\ToolResult;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Crustum\Ai\Storage\DatabaseConversationStore;
use Crustum\Ai\Streaming\Event\ReasoningDelta;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\ToolCall as StreamToolCall;
use Crustum\Ai\Streaming\Event\ToolResult as StreamToolResult;
use Crustum\AiInteraction\Configuration\ConfigurationStore;
use Crustum\AiInteraction\InputHistory\InputHistory;
use Crustum\AiInteraction\InteractionCommand\CakeCommandAdapter;
use Crustum\AiInteraction\InteractionCommand\CommandInterceptor;
use Crustum\AiInteraction\InteractionCommand\Commands;
use Crustum\AiInteraction\InteractionCommand\HelpCommand;
use Crustum\AiInteraction\InteractionCommand\LeaveCommand;
use Crustum\AiInteraction\InteractionCommand\Selection;
use Crustum\AiInteraction\InteractionCommand\SelectionOption;
use Crustum\AiInteraction\InteractionCommand\TurnRunner as InteractionTurnRunner;
use Crustum\AiInteraction\Interruption\StopSignal;
use Crustum\AiInteraction\Storage\InMemoryStorage;
use Crustum\AiTui\Tui;
use Crustum\AiTui\View\TextQuestion;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui as SymfonyTui;
use function implode;
use function str_contains;
use function time;

/**
 * Headless feature coverage for the terminal host.
 */
final class TuiTest extends TestCase
{
    public function testStreamsAgentAnswerAndReturnsToReady(): void
    {
        [$turns, $commands, $history] = $this->parts($this->agent([
            new TextDelta('event-1', 'message-1', 'Hello world', time()),
        ]));
        $sent = false;
        $screen = $this->runApplication($turns, $commands, $history, function (
            VirtualTerminal $terminal,
            string $screen,
        ) use (&$sent): bool {
            if (!$sent) {
                $terminal->simulateInput("Question\r");
                $sent = true;
            }

            return str_contains($screen, 'Hello world') && !str_contains($screen, 'Working');
        });

        self::assertStringContainsString('Hello world', $screen);
        self::assertStringContainsString('Ready', $screen);
    }

    public function testSlashCommandDispatchShowsNotice(): void
    {
        [$turns, $commands, $history] = $this->parts($this->agent([]));
        $sent = false;
        $screen = $this->runApplication($turns, $commands, $history, function (
            VirtualTerminal $terminal,
            string $screen,
        ) use (&$sent): bool {
            if (!$sent) {
                $terminal->simulateInput("/help\r");
                $sent = true;
            }

            return str_contains($screen, 'Lists what can be typed here.');
        });

        self::assertStringContainsString('Lists what can be typed here.', $screen);
    }

    public function testToolCallBlockRenders(): void
    {
        [$turns, $commands, $history] = $this->parts($this->agent([
            new TextDelta('event-1', 'message-1', 'Checking.', time()),
            new StreamToolCall('event-2', new ToolCall(id: 'call-1', name: 'weather', arguments: []), time()),
            new StreamToolResult(
                'event-3',
                new ToolResult(id: 'call-1', name: 'weather', arguments: [], result: 'Sunny'),
                true,
                null,
                time(),
            ),
            new TextDelta('event-4', 'message-1', 'It is sunny', time()),
        ]));
        $sent = false;
        $screen = $this->runApplication($turns, $commands, $history, function (
            VirtualTerminal $terminal,
            string $screen,
        ) use (&$sent): bool {
            if (!$sent) {
                $terminal->simulateInput("Weather?\r");
                $sent = true;
            }

            return str_contains($screen, 'It is sunny') && !str_contains($screen, 'Working');
        });

        self::assertStringContainsString('weather', $screen);
        self::assertStringContainsString('It is sunny', $screen);
    }

    public function testQuestionAnswerBypassesTurns(): void
    {
        $agent = $this->createMock(Agent::class);
        $agent->expects($this->never())->method('stream');
        [$turns, $commands, $history] = $this->parts($agent);
        $answered = 'pending';
        $asked = false;
        $confirmed = false;
        $done = false;
        $terminal = new VirtualTerminal(100, 40);
        $tui = new SymfonyTui(terminal: $terminal);
        $host = new Tui($turns, $commands, $history, static fn(): array => []);
        (new ReflectionProperty(Tui::class, 'tui'))->setValue($host, $tui);
        $buffer = new ScreenBuffer(100, 40);
        $screen = '';
        $timedOut = false;
        $output = '';
        $poll = EventLoop::repeat(0.0001, function () use (
            $terminal,
            $tui,
            $host,
            &$buffer,
            &$screen,
            &$output,
            &$asked,
            &$confirmed,
            &$answered,
            &$done,
        ): void {
            $tui->processRender();
            $frame = $terminal->consumeOutput();
            $output .= $frame;
            $buffer->write($frame);
            $screen = AnsiUtils::stripAnsiCodes(implode("\n", $buffer->getLines()));
            if (!$asked && str_contains($screen, 'Ready')) {
                $asked = true;
                $host->mainView()->askQuestion(
                    new Selection('policy', 'Pick one', [
                        new SelectionOption('a', 'Alpha'),
                        new SelectionOption('b', 'Beta'),
                    ]),
                    static function (?string $value) use (&$answered): void {
                        $answered = $value ?? 'dismissed';
                    },
                );
            }

            if ($asked && !$confirmed && str_contains($screen, 'Alpha')) {
                $confirmed = true;
                $terminal->simulateInput("\r");
            }

            if ($answered !== 'pending' && !$done) {
                $done = true;
                $terminal->simulateInput("\x03");
            }
        });
        $timeout = EventLoop::delay(5, function () use ($tui, &$timedOut): void {
            $timedOut = true;
            $tui->stop();
        });

        try {
            $host->run();
        } finally {
            EventLoop::cancel($poll);
            EventLoop::cancel($timeout);
        }

        self::assertFalse($timedOut, $screen);
        self::assertSame('a', $answered);
    }

    public function testLeaveCommandStopsTheSession(): void
    {
        [$turns, $commands, $history] = $this->parts($this->agent([]));
        $sent = false;
        $terminal = new VirtualTerminal(100, 40);
        $tui = new SymfonyTui(terminal: $terminal);
        $host = new Tui($turns, $commands, $history, static fn(): array => []);
        (new ReflectionProperty(Tui::class, 'tui'))->setValue($host, $tui);
        $timedOut = false;
        $poll = EventLoop::repeat(0.0001, static function () use ($terminal, $tui, &$sent): void {
            $tui->processRender();
            $terminal->consumeOutput();
            if (!$sent && $tui->isRunning()) {
                $sent = true;
                $terminal->simulateInput("/exit\r");
            }
        });
        $timeout = EventLoop::delay(5, static function () use ($tui, &$timedOut): void {
            $timedOut = true;
            $tui->stop();
        });

        try {
            $host->run();
        } finally {
            EventLoop::cancel($poll);
            EventLoop::cancel($timeout);
        }

        self::assertFalse($timedOut);
        self::assertFalse($tui->isRunning());
    }

    public function testTextQuestionAnswerBypassesTurns(): void
    {
        $agent = $this->createMock(Agent::class);
        $agent->expects($this->never())->method('stream');
        [$turns, $commands, $history] = $this->parts($agent);
        $answered = 'pending';
        $asked = false;
        $sent = false;
        $done = false;
        $terminal = new VirtualTerminal(100, 40);
        $tui = new SymfonyTui(terminal: $terminal);
        $host = new Tui($turns, $commands, $history, static fn(): array => []);
        (new ReflectionProperty(Tui::class, 'tui'))->setValue($host, $tui);
        $buffer = new ScreenBuffer(100, 40);
        $screen = '';
        $timedOut = false;
        $poll = EventLoop::repeat(0.0001, function () use (
            $terminal,
            $tui,
            $host,
            &$buffer,
            &$screen,
            &$asked,
            &$sent,
            &$answered,
            &$done,
        ): void {
            $tui->processRender();
            $frame = $terminal->consumeOutput();
            $buffer->write($frame);
            $screen = AnsiUtils::stripAnsiCodes(implode("\n", $buffer->getLines()));
            if (!$asked && str_contains($screen, 'Ready')) {
                $asked = true;
                $host->mainView()->askText(
                    new TextQuestion('Model id?', 'sonnet'),
                    static function (?string $value) use (&$answered): void {
                        $answered = $value ?? 'dismissed';
                    },
                );
            }

            if ($asked && !$sent && str_contains($screen, 'Model id?')) {
                $sent = true;
                $terminal->simulateInput("opus\r");
            }

            if ($answered !== 'pending' && !$done) {
                $done = true;
                $terminal->simulateInput("\x03");
            }
        });
        $timeout = EventLoop::delay(5, function () use ($tui, &$timedOut): void {
            $timedOut = true;
            $tui->stop();
        });

        try {
            $host->run();
        } finally {
            EventLoop::cancel($poll);
            EventLoop::cancel($timeout);
        }

        self::assertFalse($timedOut, $screen);
        self::assertSame('opus', $answered);
    }

    public function testQueuedTaskRunsAtStartupWithoutTypedInput(): void
    {
        $agent = $this->agent([
            new TextDelta('event-1', 'message-1', 'Queued hello', time()),
            new StreamEnd('event-2', 'stop', new TextUsage(10, 5), time()),
        ]);
        [$turns, $commands, $history] = $this->parts($agent);
        $done = false;
        $terminal = new VirtualTerminal(100, 40);
        $tui = new SymfonyTui(terminal: $terminal);
        $host = new Tui($turns, $commands, $history, static fn(): array => []);
        (new ReflectionProperty(Tui::class, 'tui'))->setValue($host, $tui);
        $host->mainView()->queueTask('Say hello');
        $buffer = new ScreenBuffer(100, 40);
        $screen = '';
        $timedOut = false;
        $poll = EventLoop::repeat(0.0001, function () use (
            $terminal,
            $tui,
            &$buffer,
            &$screen,
            &$done,
        ): void {
            $tui->processRender();
            $frame = $terminal->consumeOutput();
            $buffer->write($frame);
            $screen = AnsiUtils::stripAnsiCodes(implode("\n", $buffer->getLines()));
            if (!$done && str_contains($screen, 'Queued hello') && !str_contains($screen, 'Working')) {
                $done = true;
                $terminal->simulateInput("\x03");
            }
        });
        $timeout = EventLoop::delay(5, function () use ($tui, &$timedOut): void {
            $timedOut = true;
            $tui->stop();
        });

        try {
            $host->run();
        } finally {
            EventLoop::cancel($poll);
            EventLoop::cancel($timeout);
        }

        self::assertFalse($timedOut, $screen);
        self::assertStringContainsString('Queued hello', $screen);
    }

    public function testTextQuestionDismissAnswersNull(): void
    {
        $agent = $this->createMock(Agent::class);
        $agent->expects($this->never())->method('stream');
        [$turns, $commands, $history] = $this->parts($agent);
        $answered = 'pending';
        $asked = false;
        $done = false;
        $terminal = new VirtualTerminal(100, 40);
        $tui = new SymfonyTui(terminal: $terminal);
        $host = new Tui($turns, $commands, $history, static fn(): array => []);
        (new ReflectionProperty(Tui::class, 'tui'))->setValue($host, $tui);
        $buffer = new ScreenBuffer(100, 40);
        $screen = '';
        $timedOut = false;
        $poll = EventLoop::repeat(0.0001, function () use (
            $terminal,
            $tui,
            $host,
            &$buffer,
            &$screen,
            &$asked,
            &$answered,
            &$done,
        ): void {
            $tui->processRender();
            $frame = $terminal->consumeOutput();
            $buffer->write($frame);
            $screen = AnsiUtils::stripAnsiCodes(implode("\n", $buffer->getLines()));
            if (!$asked && str_contains($screen, 'Ready')) {
                $asked = true;
                $host->mainView()->askText(
                    new TextQuestion('What changed?'),
                    static function (?string $value) use (&$answered): void {
                        $answered = $value ?? 'dismissed';
                    },
                );
                $terminal->simulateInput("\x1b");
            }

            if ($answered !== 'pending' && !$done) {
                $done = true;
                $terminal->simulateInput("\x03");
            }
        });
        $timeout = EventLoop::delay(5, function () use ($tui, &$timedOut): void {
            $timedOut = true;
            $tui->stop();
        });

        try {
            $host->run();
        } finally {
            EventLoop::cancel($poll);
            EventLoop::cancel($timeout);
        }

        self::assertFalse($timedOut, $screen);
        self::assertSame('dismissed', $answered);
    }

    public function testTextQuestionBlankAcceptsDefault(): void
    {
        $agent = $this->createMock(Agent::class);
        $agent->expects($this->never())->method('stream');
        [$turns, $commands, $history] = $this->parts($agent);
        $answered = 'pending';
        $asked = false;
        $sent = false;
        $done = false;
        $terminal = new VirtualTerminal(100, 40);
        $tui = new SymfonyTui(terminal: $terminal);
        $host = new Tui($turns, $commands, $history, static fn(): array => []);
        (new ReflectionProperty(Tui::class, 'tui'))->setValue($host, $tui);
        $buffer = new ScreenBuffer(100, 40);
        $screen = '';
        $timedOut = false;
        $poll = EventLoop::repeat(0.0001, function () use (
            $terminal,
            $tui,
            $host,
            &$buffer,
            &$screen,
            &$asked,
            &$sent,
            &$answered,
            &$done,
        ): void {
            $tui->processRender();
            $frame = $terminal->consumeOutput();
            $buffer->write($frame);
            $screen = AnsiUtils::stripAnsiCodes(implode("\n", $buffer->getLines()));
            if (!$asked && str_contains($screen, 'Ready')) {
                $asked = true;
                $host->mainView()->askText(
                    new TextQuestion('Model id?', 'sonnet'),
                    static function (?string $value) use (&$answered): void {
                        $answered = $value ?? 'dismissed';
                    },
                );
            }

            if ($asked && !$sent && str_contains($screen, 'Model id? [sonnet]')) {
                $sent = true;
                $terminal->simulateInput("\r");
            }

            if ($answered !== 'pending' && !$done) {
                $done = true;
                $terminal->simulateInput("\x03");
            }
        });
        $timeout = EventLoop::delay(5, function () use ($tui, &$timedOut): void {
            $timedOut = true;
            $tui->stop();
        });

        try {
            $host->run();
        } finally {
            EventLoop::cancel($poll);
            EventLoop::cancel($timeout);
        }

        self::assertFalse($timedOut, $screen);
        self::assertSame('sonnet', $answered);
    }

    public function testReasoningPhasesStayWithTheirTools(): void
    {
        [$turns, $commands, $history] = $this->parts($this->agent([
            new ReasoningDelta('event-1', 'message-1', 'first thought', time()),
            new StreamToolCall('event-2', new ToolCall(id: 'call-1', name: 'weather', arguments: []), time()),
            new ReasoningDelta('event-3', 'message-1', 'second thought', time()),
        ]));
        $sent = false;
        $screen = $this->runApplication($turns, $commands, $history, function (
            VirtualTerminal $terminal,
            string $screen,
        ) use (&$sent): bool {
            if (!$sent) {
                $terminal->simulateInput("Think\r");
                $sent = true;
            }

            return str_contains($screen, 'second thought');
        });

        $first = strpos($screen, 'first thought');
        $tool = strpos($screen, 'weather');
        $second = strpos($screen, 'second thought');

        self::assertIsInt($first);
        self::assertIsInt($tool);
        self::assertIsInt($second);
        self::assertLessThan($tool, $first);
        self::assertLessThan($second, $tool);
    }

    /**
     * Builds turn dependencies over a canned agent.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent Canned agent.
     * @return array{0: \Crustum\AiInteraction\InteractionCommand\TurnRunner, 1: \Crustum\AiInteraction\InteractionCommand\Commands, 2: \Crustum\AiInteraction\InputHistory\InputHistory} Turn dependencies.
     */
    private function parts(Agent $agent): array
    {
        $commands = (new Commands())->addCommand([new HelpCommand(), new LeaveCommand()]);
        $adapter = new CakeCommandAdapter(
            $agent,
            $commands,
            new DatabaseConversationStore(),
            static function (): void {
            },
            new ConfigurationStore(new InMemoryStorage(), 'tui-user'),
            'console',
            'tui-user',
            (object)['id' => 'tui-user'],
        );
        $history = new InputHistory(new InMemoryStorage());
        $turns = new InteractionTurnRunner(
            new CommandInterceptor($commands),
            $adapter,
            $agent,
            $history,
            new StopSignal(new InMemoryStorage(), 'tui-test'),
        );

        return [$turns, $commands, $history];
    }

    /**
     * Builds an agent streaming canned events.
     *
     * @param list<object> $events Canned stream events.
     * @return \Crustum\Ai\Contracts\Agent Canned agent.
     */
    private function agent(array $events): Agent
    {
        $agent = $this->createStub(Agent::class);
        $agent->method('stream')->willReturn(
            new StreamableAgentResponse('invocation', static function () use ($events): iterable {
                yield from $events;
            }, new Meta()),
        );

        return $agent;
    }

    /**
     * Runs the terminal host headless until the step completes.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\TurnRunner $turns Interaction turn primitive.
     * @param \Crustum\AiInteraction\InteractionCommand\Commands $commands Mounted slash-commands.
     * @param \Crustum\AiInteraction\InputHistory\InputHistory $history Submitted input history.
     * @param \Closure(\Symfony\Component\Tui\Terminal\VirtualTerminal, string): bool $step Completion poll.
     * @return string Stripped screen.
     */
    private function runApplication(
        InteractionTurnRunner $turns,
        Commands $commands,
        InputHistory $history,
        Closure $step,
    ): string {
        $terminal = new VirtualTerminal(100, 40);
        $tui = new SymfonyTui(terminal: $terminal);
        $host = new Tui($turns, $commands, $history, static fn(): array => []);
        (new ReflectionProperty(Tui::class, 'tui'))->setValue($host, $tui);
        $buffer = new ScreenBuffer(100, 40);
        $lastScreen = '';
        $timedOut = false;
        $output = '';
        $poll = EventLoop::repeat(0.0001, function () use (
            $terminal,
            $tui,
            &$buffer,
            &$lastScreen,
            &$output,
            $step,
        ): void {
            $tui->processRender();
            $frame = $terminal->consumeOutput();
            $output .= $frame;
            $buffer->write($frame);
            $lastScreen = AnsiUtils::stripAnsiCodes(implode("\n", $buffer->getLines()));
            if ($step($terminal, $lastScreen)) {
                $terminal->simulateInput("\x03");
            }
        });
        $timeout = EventLoop::delay(5, function () use ($tui, &$timedOut): void {
            $timedOut = true;
            $tui->stop();
        });

        try {
            $host->run();
        } finally {
            EventLoop::cancel($poll);
            EventLoop::cancel($timeout);
        }

        self::assertFalse($timedOut, $lastScreen);

        return $lastScreen;
    }
}
