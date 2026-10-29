<?php
declare(strict_types=1);

namespace Crustum\AiTui;

use Closure;
use Crustum\AiInteraction\InputHistory\InputHistory;
use Crustum\AiInteraction\InteractionCommand\CommandInput;
use Crustum\AiInteraction\InteractionCommand\Commands;
use Crustum\AiInteraction\InteractionCommand\TurnRunner as InteractionTurnRunner;
use Crustum\AiTui\Terminal\WindowsConsoleTerminal;
use Crustum\AiTui\View\MainView;
use Crustum\AiTui\View\Theme;
use Crustum\AiTui\View\ToolPresenter;
use LogicException;
use RuntimeException;
use Symfony\Component\Tui\Tui as SymfonyTui;
use function stream_isatty;
use const STDIN;
use const STDOUT;

/**
 * Terminal host wiring the chat screen into interaction turns.
 */
final class Tui
{
    /**
     * Chat screen.
     *
     * @var \Crustum\AiTui\View\MainView
     */
    private readonly MainView $mainView;

    /**
     * Terminal host owning the render loop.
     *
     * @var \Symfony\Component\Tui\Tui
     */
    private SymfonyTui $tui;

    /**
     * Whether the terminal loop already ran.
     *
     * @var bool
     */
    private bool $started = false;

    /**
     * Interaction turn primitive.
     *
     * @var \Crustum\AiInteraction\InteractionCommand\TurnRunner
     */
    private readonly InteractionTurnRunner $turns;

    /**
     * Mounted slash-commands for the completion menu.
     *
     * @var \Crustum\AiInteraction\InteractionCommand\Commands
     */
    private readonly Commands $commands;

    /**
     * Submitted input history for recall.
     *
     * @var \Crustum\AiInteraction\InputHistory\InputHistory
     */
    private readonly InputHistory $inputHistory;

    /**
     * Conversation messages supplier for history sync.
     *
     * @var \Closure(): iterable<\Crustum\Ai\Messages\Message>
     */
    private readonly Closure $historySync;

    /**
     * Creates the terminal host with explicit turn dependencies.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\TurnRunner $turns Interaction turn primitive.
     * @param \Crustum\AiInteraction\InteractionCommand\Commands $commands Mounted slash-commands.
     * @param \Crustum\AiInteraction\InputHistory\InputHistory $history Submitted input history.
     * @param \Closure(): iterable<\Crustum\Ai\Messages\Message> $historySync Conversation messages supplier.
     */
    public function __construct(
        InteractionTurnRunner $turns,
        Commands $commands,
        InputHistory $history,
        Closure $historySync,
    ) {
        $this->turns = $turns;
        $this->commands = $commands;
        $this->inputHistory = $history;
        $this->historySync = $historySync;
        $this->mainView = new MainView();
        $this->tui = new SymfonyTui(terminal: WindowsConsoleTerminal::create());
        $this->wire();
    }

    /**
     * Replaces the chat title.
     *
     * @param string $title Chat title.
     * @return static
     */
    public function setTitle(string $title): static
    {
        $this->mainView->setTitle($title);

        return $this;
    }

    /**
     * Replaces the header banner.
     *
     * @param string $text Banner text.
     * @param string $font Banner font name.
     * @return static
     */
    public function setFiglet(string $text, string $font = 'standard'): static
    {
        $this->mainView->setFiglet($text, $font);

        return $this;
    }

    /**
     * Replaces the chat description.
     *
     * @param string $description Chat description.
     * @return static
     */
    public function setDescription(string $description): static
    {
        $this->mainView->setDescription($description);

        return $this;
    }

    /**
     * Sets the human-readable tool presentation.
     *
     * Hosts whose tools ask questions out of band pass a presenter so call
     * blocks show the question instead of raw JSON; null keeps the default
     * argument dump.
     *
     * @param \Crustum\AiTui\View\ToolPresenter|null $presenter Tool presenter.
     * @return static
     */
    public function setToolPresenter(?ToolPresenter $presenter): static
    {
        $this->mainView->setToolPresenter($presenter);

        return $this;
    }

    /**
     * The chat screen, so embedders can wire host flows the turn loop does
     * not own — out-of-turn questions, completion providers, history sync.
     *
     * @return \Crustum\AiTui\View\MainView
     */
    public function mainView(): MainView
    {
        return $this->mainView;
    }

    /**
     * Wires screen callbacks into the turn dependencies.
     *
     * The tick hint keeps the terminal loop polling at full cadence for the
     * whole turn, so the spinner and freshly arrived chunks repaint even when
     * no widget explicitly requested a render between stream events.
     *
     * @return void
     */
    private function wire(): void
    {
        $this->mainView->onInput(function (string|CommandInput $input): void {
            (new TurnRunner($this->turns, $this->mainView, $this->tui))->run($input);
        });
        $this->mainView->onCommands(fn(): array => $this->commands->all());
        $this->mainView->onHistorySync(fn(): iterable => ($this->historySync)());
        $this->mainView->onInputHistory(fn(): array => $this->inputHistory->entries());
        $this->mainView->onEscape(fn(): bool => $this->turns->requestInterruption());

        $this->tui->onTick(fn(): bool => $this->mainView->isBusy());
    }

    /**
     * Runs the terminal loop once.
     *
     * @return void
     * @throws \LogicException When the host already ran.
     * @throws \RuntimeException When no interactive terminal is attached.
     */
    public function run(): void
    {
        if ($this->started) {
            throw new LogicException('A TUI instance can only run once.');
        }

        if (!$this->tui->getTerminal()->isVirtual() && (!stream_isatty(STDIN) || !stream_isatty(STDOUT))) {
            throw new RuntimeException('AiTui requires an interactive TTY.');
        }

        $this->started = true;
        $this->tui->addStyleSheet(Theme::styleSheet());
        $this->tui->add($this->mainView);
        $this->tui->addListener($this->mainView->handleInput(...));

        $this->mainView->focus();
        $this->mainView->syncHistory();
        $this->mainView->startNextTurn();

        try {
            $this->tui->run();
        } finally {
            $this->mainView->stop();
        }
    }
}
