<?php
declare(strict_types=1);

namespace Crustum\AiTui\Test\Unit;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\ToolResult;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Crustum\Ai\Storage\DatabaseConversationStore;
use Crustum\Ai\Streaming\Event\ReasoningDelta;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\ToolApprovalRequest;
use Crustum\Ai\Streaming\Event\ToolCall as StreamToolCall;
use Crustum\Ai\Streaming\Event\ToolResult as StreamToolResult;
use Crustum\AiInteraction\Configuration\ConfigurationStore;
use Crustum\AiInteraction\InputHistory\InputHistory;
use Crustum\AiInteraction\InteractionCommand\CakeCommandAdapter;
use Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface;
use Crustum\AiInteraction\InteractionCommand\CommandInput;
use Crustum\AiInteraction\InteractionCommand\CommandInterceptor;
use Crustum\AiInteraction\InteractionCommand\CommandInterface;
use Crustum\AiInteraction\InteractionCommand\Commands;
use Crustum\AiInteraction\InteractionCommand\Selection;
use Crustum\AiInteraction\InteractionCommand\SelectionOption;
use Crustum\AiInteraction\InteractionCommand\TurnRunner as InteractionTurnRunner;
use Crustum\AiInteraction\Interruption\StopSignal;
use Crustum\AiInteraction\Storage\InMemoryStorage;
use Crustum\AiTui\TurnRunner;
use Crustum\AiTui\View\MainView;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Revolt\EventLoop;
use RuntimeException;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui as SymfonyTui;
use function collection;
use function implode;
use function preg_replace;
use function substr_count;
use function time;

/**
 * Unit coverage for the turn item to view mapping.
 */
final class TurnRunnerTest extends TestCase
{
    public function testStreamsTextToolCallAndResult(): void
    {
        $agent = $this->agent([
            new TextDelta('event-1', 'message-1', 'Checking.', time()),
            new StreamToolCall('event-2', new ToolCall(id: 'call-1', name: 'weather', arguments: []), time()),
            new StreamToolResult(
                'event-3',
                new ToolResult(id: 'call-1', name: 'weather', arguments: [], result: 'Sunny'),
                true,
                null,
                time(),
            ),
        ]);
        $screen = $this->runTurn($agent, [$this->echoCommand()], 'Weather?');

        self::assertStringContainsString('Checking.', $screen);
        self::assertStringContainsString('weather', $screen);
        self::assertStringNotContainsString('Sunny', $screen);
    }

    public function testCommandInputDispatchesToNotice(): void
    {
        $screen = $this->runTurn($this->agent([]), [$this->echoCommand()], new CommandInput('/echo', 'hello'));

        self::assertStringContainsString('echo:hello', $screen);
    }

    public function testWarningAndErrorNoticesStayDistinct(): void
    {
        $screen = $this->runTurn($this->agent([]), [$this->levelsCommand()], new CommandInput('/levels', ''));

        self::assertStringContainsString('ℹ Heads up', $screen);
        self::assertStringContainsString('ℹ Careful', $screen);
        self::assertStringContainsString('! Bad thing', $screen);
    }

    public function testSelectionOutcomeOpensPicker(): void
    {
        $screen = $this->runTurn($this->agent([]), [$this->pickCommand()], new CommandInput('/pick', ''));

        self::assertStringContainsString('Pick one', $screen);
        self::assertStringContainsString('Alpha', $screen);
    }

    public function testTaskOutcomeRunsAsNextTurnWithoutRendering(): void
    {
        $agent = $this->agent([
            new TextDelta('event-1', 'message-1', 'Task output.', time()),
        ]);
        $screen = $this->runTurn($agent, [$this->taskCommand()], new CommandInput('/task', 'do the thing'));

        self::assertStringContainsString('Task output.', $screen);
        self::assertStringContainsString('❯ /task', $screen);
        self::assertStringNotContainsString('do the thing', $screen);
    }

    public function testReasoningDeltaRendersOnThinkingLine(): void
    {
        $agent = $this->agent([
            new ReasoningDelta('event-1', 'reasoning-1', 'Considering options.', time()),
            new ReasoningDelta('event-2', 'reasoning-1', ' Decided.', time()),
            new TextDelta('event-3', 'message-1', 'Answer.', time()),
        ]);
        $screen = $this->runTurn($agent, [$this->echoCommand()], 'Think?');

        self::assertStringContainsString('Considering options. Decided.', $screen);
        self::assertStringContainsString('Answer.', $screen);
    }

    public function testConversationOutcomeResyncsHistory(): void
    {
        $adapter = $this->createStub(CommandAdapterInterface::class);
        $adapter->method('admit')->willReturn(true);
        $adapter->method('afterExecution')->willReturn([
            'identifier' => '/echo',
            'status' => 'completed',
            'error' => null,
            'notices' => [],
            'warnings' => [],
            'errors' => [],
            'selection' => null,
            'stopped' => false,
            'conversationId' => 'conversation-1',
        ]);
        $mounted = (new Commands())->addCommand([$this->echoCommand()]);
        $history = new InputHistory(new InMemoryStorage());
        $turns = new InteractionTurnRunner(
            new CommandInterceptor($mounted),
            $adapter,
            $this->agent([]),
            $history,
            new StopSignal(new InMemoryStorage(), 'tui-test'),
        );
        $view = new MainView();
        $view->onHistorySync(static fn(): array => [new UserMessage('Synced question')]);

        $screen = $this->drain(new TurnRunner($turns, $view, $this->headlessTui()), $view, new CommandInput('/echo', ''));

        self::assertStringContainsString('Synced question', $screen);
    }

    public function testStoppedTurnNotifiesOnce(): void
    {
        $signal = new StopSignal(new InMemoryStorage(), 'tui-test');
        $agent = $this->createStub(Agent::class);
        $agent->method('stream')->willReturn(
            new StreamableAgentResponse('invocation', static function () use ($signal): iterable {
                $signal->request();
                yield new TextDelta('event-1', 'message-1', 'Hello.', time());
                yield new TextDelta('event-2', 'message-1', 'Unreached.', time());
            }, new Meta()),
        );
        $screen = $this->runTurn($agent, [$this->echoCommand()], 'Hello.', $signal);

        self::assertSame(1, substr_count($screen, 'Response stopped.'));
        self::assertStringNotContainsString('Hello.', $screen);
        self::assertStringNotContainsString('Unreached.', $screen);
    }

    public function testApprovalPauseSurfacesInlineNoticeOnce(): void
    {
        $agent = $this->agent([
            new TextDelta('event-1', 'message-1', 'Hello.', time()),
            new ToolApprovalRequest('approval-1', collection([]), time()),
        ]);
        $screen = $this->runTurn($agent, [$this->echoCommand()], 'Hello.');

        self::assertStringContainsString('Hello.', $screen);
        self::assertSame(
            1,
            substr_count(
                (string)preg_replace('/\s+/', ' ', $screen),
                'Agent paused: approval or external tool results are required.',
            ),
        );
    }

    public function testFailedStreamNotifiesError(): void
    {
        $agent = $this->createStub(Agent::class);
        $agent->method('stream')->willReturn(
            new StreamableAgentResponse('invocation', static function (): iterable {
                throw new RuntimeException('Stream failed');
            }, new Meta()),
        );
        $screen = $this->runTurn($agent, [$this->echoCommand()], 'Hello.');

        self::assertStringContainsString('! Stream failed', $screen);
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
     * Runs one turn headless and returns the stripped screen.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent Canned agent.
     * @param list<\Crustum\AiInteraction\InteractionCommand\CommandInterface> $commands Mounted commands.
     * @param string|\Crustum\AiInteraction\InteractionCommand\CommandInput $input Submitted input.
     * @param \Crustum\AiInteraction\Interruption\StopSignal|null $stop Shared stop signal.
     * @return string Stripped screen.
     */
    private function runTurn(
        Agent $agent,
        array $commands,
        string|CommandInput $input,
        ?StopSignal $stop = null,
    ): string {
        $mounted = (new Commands())->addCommand($commands);
        $adapter = new CakeCommandAdapter(
            $agent,
            $mounted,
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
            new CommandInterceptor($mounted),
            $adapter,
            $agent,
            $history,
            $stop ?? new StopSignal(new InMemoryStorage(), 'tui-test'),
        );
        $view = new MainView();
        $loop = new TurnRunner($turns, $view, $this->headlessTui());
        $view->onInput($loop->run(...));

        return $this->drain($loop, $view, $input);
    }

    /**
     * Drains one turn through the public entry point and renders the screen.
     *
     * @param \Crustum\AiTui\TurnRunner $runner View loop.
     * @param \Crustum\AiTui\View\MainView $view Chat screen.
     * @param string|\Crustum\AiInteraction\InteractionCommand\CommandInput $input Submitted input.
     * @return string Stripped screen.
     */
    private function drain(TurnRunner $runner, MainView $view, string|CommandInput $input): string
    {
        (new ReflectionProperty(MainView::class, 'busy'))->setValue($view, true);
        $runner->run($input);
        EventLoop::run();
        $lines = (new Renderer())->renderWidget($view, new RenderContext(60, 24));

        return AnsiUtils::stripAnsiCodes(implode("\n", $lines));
    }

    /**
     * Builds a headless terminal host reporting itself as running.
     *
     * @return \Symfony\Component\Tui\Tui Headless terminal host.
     */
    private function headlessTui(): SymfonyTui
    {
        return new class extends SymfonyTui {
            /**
             * Builds the headless host on a virtual terminal.
             */
            public function __construct()
            {
                parent::__construct(terminal: new VirtualTerminal(80, 24));
            }

            /**
             * Reports the host as running without a terminal loop.
             *
             * @return bool Always true.
             */
            public function isRunning(): bool
            {
                return true;
            }
        };
    }

    /**
     * Builds an echo command notifying its raw value.
     *
     * @return \Crustum\AiInteraction\InteractionCommand\CommandInterface Echo command.
     */
    private function echoCommand(): CommandInterface
    {
        return new class implements CommandInterface {
            /**
             * Returns the slash-prefixed identifier.
             *
             * @return string Slash-prefixed command name.
             */
            public function name(): string
            {
                return '/echo';
            }

            /**
             * Returns the test description.
             *
             * @return string Command description.
             */
            public function describe(): string
            {
                return 'Echoes the value.';
            }

            /**
             * Notifies the raw value.
             *
             * @param \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed> $adapter Active adapter.
             * @param string $value Raw input value.
             * @return void
             */
            public function run(CommandAdapterInterface $adapter, string $value): void
            {
                $adapter->notify('echo:' . $value);
            }
        };
    }

    /**
     * Builds a command emitting every notice level.
     *
     * @return \Crustum\AiInteraction\InteractionCommand\CommandInterface Levels command.
     */
    private function levelsCommand(): CommandInterface
    {
        return new class implements CommandInterface {
            /**
             * Returns the slash-prefixed identifier.
             *
             * @return string Slash-prefixed command name.
             */
            public function name(): string
            {
                return '/levels';
            }

            /**
             * Returns the test description.
             *
             * @return string Command description.
             */
            public function describe(): string
            {
                return 'Emits every notice level.';
            }

            /**
             * Emits notice, warning and error texts.
             *
             * @param \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed> $adapter Active adapter.
             * @param string $value Raw input value.
             * @return void
             */
            public function run(CommandAdapterInterface $adapter, string $value): void
            {
                $adapter->notify('Heads up');
                $adapter->warn('Careful');
                $adapter->error('Bad thing');
            }
        };
    }

    /**
     * Builds a command requesting a selection.
     *
     * @return \Crustum\AiInteraction\InteractionCommand\CommandInterface Selection command.
     */
    private function pickCommand(): CommandInterface
    {
        return new class implements CommandInterface {
            /**
             * Returns the slash-prefixed identifier.
             *
             * @return string Slash-prefixed command name.
             */
            public function name(): string
            {
                return '/pick';
            }

            /**
             * Returns the test description.
             *
             * @return string Command description.
             */
            public function describe(): string
            {
                return 'Requests a selection.';
            }

            /**
             * Requests a single-option selection.
             *
             * @param \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed> $adapter Active adapter.
             * @param string $value Raw input value.
             * @return void
             */
            public function run(CommandAdapterInterface $adapter, string $value): void
            {
                $adapter->requestSelection(new Selection('/pick', 'Pick one', [
                    new SelectionOption('a', 'Alpha'),
                ]));
            }
        };
    }

    /**
     * Builds a command resolving to task text.
     *
     * @return \Crustum\AiInteraction\InteractionCommand\CommandInterface Task command.
     */
    private function taskCommand(): CommandInterface
    {
        return new class implements CommandInterface {
            /**
             * Returns the slash-prefixed identifier.
             *
             * @return string Slash-prefixed command name.
             */
            public function name(): string
            {
                return '/task';
            }

            /**
             * Returns the test description.
             *
             * @return string Command description.
             */
            public function describe(): string
            {
                return 'Resolves to task text.';
            }

            /**
             * Requests the value as task text under the command origin.
             *
             * @param \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed> $adapter Active adapter.
             * @param string $value Raw input value.
             * @return void
             */
            public function run(CommandAdapterInterface $adapter, string $value): void
            {
                $adapter->requestTask($value, '/task');
            }
        };
    }
}
