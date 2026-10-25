<?php
declare(strict_types=1);

namespace Crustum\AiTui\View;

use Closure;
use Crustum\AiInteraction\InteractionCommand\CommandInput;
use Crustum\AiInteraction\InteractionCommand\Selection;
use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use function array_slice;
use function count;
use function implode;
use function str_replace;
use function trim;

/**
 * Prompt composer with command menu, selection panel and queue preview.
 */
final class ComposerView extends ContainerWidget
{
    /**
     * Multiline prompt editor.
     *
     * @var \Crustum\AiTui\View\ComposerInputView
     */
    private readonly ComposerInputView $input;

    /**
     * Slash-command completion menu.
     *
     * @var \Crustum\AiTui\View\CommandMenuView
     */
    private readonly CommandMenuView $commands;

    /**
     * Option picker for selection requests.
     *
     * @var \Crustum\AiTui\View\SelectionView
     */
    private readonly SelectionView $selection;

    /**
     * Free-text question label shown above the composer.
     *
     * @var \Symfony\Component\Tui\Widget\TextWidget
     */
    private readonly TextWidget $question;

    /**
     * Status line shown when idle.
     *
     * @var \Symfony\Component\Tui\Widget\TextWidget
     */
    private readonly TextWidget $hint;

    /**
     * Composer keybindings.
     *
     * @var \Symfony\Component\Tui\Input\Keybindings
     */
    private readonly Keybindings $keys;

    /**
     * Ready indicator shown when idle.
     *
     * @var \Symfony\Component\Tui\Widget\TextWidget
     */
    private readonly TextWidget $status;

    /**
     * Working indicator shown during turns.
     *
     * @var \Crustum\AiTui\View\WorkingView
     */
    private readonly WorkingView $working;

    /**
     * Queued messages block.
     *
     * @var \Symfony\Component\Tui\Widget\ContainerWidget
     */
    private readonly ContainerWidget $queue;

    /**
     * Queued messages title.
     *
     * @var \Symfony\Component\Tui\Widget\TextWidget
     */
    private readonly TextWidget $queueTitle;

    /**
     * Queued messages preview.
     *
     * @var \Symfony\Component\Tui\Widget\TextWidget
     */
    private readonly TextWidget $queueMessages;

    /**
     * Builds the composer with its panels and indicators.
     */
    public function __construct()
    {
        $this->input = new ComposerInputView();
        $this->commands = new CommandMenuView();
        $this->selection = new SelectionView();
        $this->question = (new TextWidget(''))->setStyle(new Style(color: Theme::ACCENT, hidden: true));
        $this->keys = new Keybindings([
            'previous' => [Key::UP],
            'next' => [Key::DOWN],
            'complete' => [Key::TAB],
            'execute' => [Key::ENTER],
            'dismiss' => [Key::ESCAPE],
        ]);
        $this->input->onChange(fn(ChangeEvent $event) => $this->updateCommands());
        $this->input->onRecall($this->dismissCommands(...));

        $this->status = (new TextWidget('✻ Ready'))->setStyle(new Style(color: Theme::MUTED));
        $this->working = (new WorkingView())->setStyle(new Style(hidden: true));
        $this->queueTitle = (new TextWidget(truncate: true))->setStyle(new Style(color: Theme::TEXT));
        $this->queueMessages = (new TextWidget(truncate: true))->setStyle(new Style(color: Theme::MUTED));
        $this->queue = (new ContainerWidget())->setStyle((new Style(hidden: true))->withPadding([1, 0]));
        $this->queue->add($this->queueTitle)->add($this->queueMessages);
        $this->add($this->status)->add($this->working)->add($this->queue)
            ->add($this->commands)->add($this->selection)->add($this->question)->add($this->input);
        $this->hint = (new TextWidget('Enter send · Shift+Enter newline · Ctrl+C exit', truncate: true))
            ->setStyle(new Style(color: Theme::MUTED));
        $this->add($this->hint);
    }

    /**
     * Returns the prompt editor.
     *
     * @return \Crustum\AiTui\View\ComposerInputView
     */
    public function input(): ComposerInputView
    {
        return $this->input;
    }

    /**
     * Shows the given selection request and hides the prompt.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\Selection|null $request Selection request
     * @return void
     */
    public function showSelection(?Selection $request): void
    {
        $this->selection->show($request);
        $hidden = $request instanceof Selection;
        $this->input->setStyle(new Style(color: Theme::SECONDARY, hidden: $hidden));
        $this->dismissCommands();
    }

    /**
     * Shows a free-text question and focuses the prompt editor.
     *
     * The command menu is dismissed: while a text question is open, Enter
     * answers the question instead of running a command. A single-line
     * default is shown in the label and accepted on empty submit, so typing
     * always starts from a clean draft; a multiline default is prefilled
     * into the editor for in-place editing.
     *
     * @param \Crustum\AiTui\View\TextQuestion|null $request Text question request
     * @return void
     */
    public function showQuestion(?TextQuestion $request): void
    {
        if (!$request instanceof TextQuestion) {
            $this->question->setStyle(new Style(color: Theme::ACCENT, hidden: true));
            $this->updateHint();

            return;
        }

        $label = $request->default !== '' && !$request->multiline
            ? $request->prompt . ' [' . $request->default . ']'
            : $request->prompt;
        $this->question->setText($label);
        $this->question->setStyle(new Style(color: Theme::ACCENT, hidden: false));

        $this->input->setText($request->multiline ? $request->default : '');
        $this->dismissCommands();
        $this->updateHint();
    }

    /**
     * Returns the widget that currently accepts typing.
     *
     * @return \Crustum\AiTui\View\ComposerInputView|\Symfony\Component\Tui\Widget\InputWidget
     */
    public function focusedInput(): ComposerInputView|InputWidget
    {
        return $this->selection->getStyle()?->getHidden() === false ? $this->selection->search() : $this->input;
    }

    /**
     * Forwards raw terminal input to the selection panel.
     *
     * @param string $data Raw terminal input
     * @return void
     */
    public function handleSelectionInput(string $data): void
    {
        $this->selection->handleInput($data);
        $this->updateHint();
    }

    /**
     * Moves the selection highlight.
     *
     * @param int $direction Highlight direction
     * @return void
     */
    public function moveSelection(int $direction): void
    {
        $this->selection->move($direction);
    }

    /**
     * Returns the invocation for the highlighted option.
     *
     * @return \Crustum\AiInteraction\InteractionCommand\CommandInput|null
     */
    public function selectedInput(): ?CommandInput
    {
        return $this->selection->selectedInput();
    }

    /**
     * Registers the mounted commands supplier.
     *
     * @param \Closure(): list<\Crustum\AiInteraction\InteractionCommand\CommandInterface> $commands Mounted commands supplier
     * @return void
     */
    public function onCommands(Closure $commands): void
    {
        $this->commands->onCommands($commands);
        $this->updateCommands();
    }

    /**
     * Refreshes the command menu for the current prompt.
     *
     * @return void
     */
    private function updateCommands(): void
    {
        $this->commands->update($this->input->getText());
        $this->updateStatus();
    }

    /**
     * Hides the command menu.
     *
     * @return void
     */
    private function dismissCommands(): void
    {
        $this->commands->dismiss();
        $this->updateStatus();
    }

    /**
     * Refreshes the ready indicator and the hint.
     *
     * @return void
     */
    private function updateStatus(): void
    {
        $hidden = $this->working->getStyle()?->getHidden() === false || $this->commands->selectedName() !== null;
        $this->status->setStyle(new Style(color: Theme::MUTED, hidden: $hidden));
        $this->updateHint();
    }

    /**
     * Refreshes the hint for the current composer state.
     *
     * @return void
     */
    private function updateHint(): void
    {
        if ($this->question->getStyle()?->getHidden() === false) {
            $this->hint->setText('Enter answer · Shift+Enter newline · Esc cancel · Ctrl+C exit');

            return;
        }

        if ($this->selection->getStyle()?->getHidden() === false) {
            $this->hint->setText($this->selection->selectedInput() instanceof CommandInput
                ? '↑/↓ choose · Enter confirm · Type to search · Esc cancel · Ctrl+C exit'
                : 'Type to search · Esc cancel · Ctrl+C exit');

            return;
        }

        $isWorking = $this->working->isRunning();
        if ($this->commands->selectedName() !== null) {
            $this->hint->setText($isWorking
                ? '↑/↓ choose · Tab complete · Enter queue · Esc close · Ctrl+C exit'
                : '↑/↓ choose · Tab complete · Enter run · Esc close · Ctrl+C exit');

            return;
        }

        $this->hint->setText($isWorking
            ? 'Enter queue · Shift+Enter newline · Ctrl+C exit'
            : 'Enter send · Shift+Enter newline · Ctrl+C exit');
    }

    /**
     * Registers the user message history supplier for recall.
     *
     * @param \Closure(): (list<\Crustum\Ai\Messages\UserMessage>|null) $history User message history supplier
     * @return void
     */
    public function onInputHistory(Closure $history): void
    {
        $this->input->onInputHistory(static function () use ($history): ?array {
            $messages = $history();
            if ($messages === null) {
                return null;
            }

            $entries = [];
            foreach ($messages as $message) {
                $text = $message->content;
                if ($text !== null && trim($text) !== '') {
                    $entries[] = $text;
                }
            }

            return $entries;
        });
    }

    /**
     * Handles raw terminal input while the command menu is open.
     *
     * @param string $data Raw terminal input
     * @return bool
     */
    public function handleCommandInput(string $data): bool
    {
        $name = $this->commands->selectedName();
        if ($name === null) {
            return false;
        }

        if ($this->keys->matches($data, 'previous')) {
            $this->commands->move(-1);
        } elseif ($this->keys->matches($data, 'next')) {
            $this->commands->move(1);
        } elseif ($this->keys->matches($data, 'dismiss')) {
            $this->dismissCommands();
        } elseif ($this->keys->matches($data, 'complete')) {
            $this->input->setText($name . ' ');
            $this->input->handleInput("\x1b[F");
        } elseif ($this->keys->matches($data, 'execute')) {
            $this->input->setText($name);
            $this->dismissCommands();
            $this->input->handleInput($data);
        } else {
            return false;
        }

        $this->getContext()?->requestRender();

        return true;
    }

    /**
     * Registers the submit listener.
     *
     * @param \Closure(\Symfony\Component\Tui\Event\SubmitEvent): void $listener Submit listener
     * @return void
     */
    public function onSubmit(Closure $listener): void
    {
        $this->input->onSubmit($listener);
    }

    /**
     * Refreshes the queued messages preview.
     *
     * @param list<string> $messages Queued messages
     * @return void
     */
    public function setPendingMessages(array $messages): void
    {
        $total = count($messages);
        $this->queueTitle->setText("• Messages queued for the next turns ({$total})");
        $lines = [];
        foreach (array_slice($messages, 0, 3) as $message) {
            $preview = str_replace(["\r\n", "\r", "\n", "\t"], ' ', StringUtils::stripControlBytes($message));
            $lines[] = '  ↳ ' . $preview;
        }

        if ($total > 3) {
            $lines[] = '  … +' . ($total - 3) . ' more';
        }

        $this->queueMessages->setText(implode("\n", $lines));
        $this->queue->setStyle((new Style(hidden: $total === 0))->withPadding([1, 0]));
    }

    /**
     * Shows the working indicator for a new turn.
     *
     * @return void
     */
    public function beginTurn(): void
    {
        $this->working->setStyle(new Style(hidden: false));
        $this->working->start();
        $this->updateStatus();
    }

    /**
     * Hides the working indicator at the end of a turn.
     *
     * @return void
     */
    public function finishTurn(): void
    {
        $this->working->stop();
        $this->working->setStyle(new Style(hidden: true));
        $this->updateStatus();
    }
}
