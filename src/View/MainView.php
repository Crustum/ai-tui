<?php
declare(strict_types=1);

namespace Crustum\AiTui\View;

use Closure;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\ToolResult;
use Crustum\AiInteraction\InteractionCommand\CommandInput;
use Crustum\AiInteraction\InteractionCommand\Selection;
use LogicException;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Throwable;
use function array_map;
use function array_merge;
use function array_shift;
use function count;
use function trim;

/**
 * Chat screen wiring history, header and composer into turns.
 */
final class MainView extends ContainerWidget
{
    /**
     * Conversation history.
     *
     * @var \Crustum\AiTui\View\HistoryView
     */
    private readonly HistoryView $history;

    /**
     * Chat header.
     *
     * @var \Crustum\AiTui\View\HeaderView
     */
    private readonly HeaderView $header;

    /**
     * Prompt composer.
     *
     * @var \Crustum\AiTui\View\ComposerView
     */
    private readonly ComposerView $composer;

    /**
     * Screen keybindings.
     *
     * @var \Symfony\Component\Tui\Input\Keybindings
     */
    private readonly Keybindings $keys;

    /**
     * Prompt submission listener.
     *
     * @var (\Closure(string|\Crustum\AiInteraction\InteractionCommand\CommandInput): void)|null
     */
    private ?Closure $onInput = null;

    /**
     * Conversation history supplier.
     *
     * @var (\Closure(): iterable<\Crustum\Ai\Messages\Message>)|null
     */
    private ?Closure $onHistorySync = null;

    /**
     * Escape listener stopping the turn in progress.
     *
     * @var (\Closure(): bool)|(\Closure(): void)|null
     */
    private ?Closure $onEscape = null;

    /**
     * Whether a turn is in progress.
     *
     * @var bool
     */
    private bool $busy = false;

    /**
     * Whether the session was stopped.
     *
     * @var bool
     */
    private bool $stopped = false;

    /**
     * Prompts queued while busy.
     *
     * @var list<string>
     */
    private array $pending = [];

    /**
     * Task flags parallel to queued prompts.
     *
     * Entries queued from turn outcomes run without attributing their text
     * to the user.
     *
     * @var list<bool>
     */
    private array $pendingIsTask = [];

    /**
     * Task origin labels parallel to queued prompts.
     *
     * Null runs the task silently; a label renders as its user line.
     *
     * @var list<string|null>
     */
    private array $pendingOrigin = [];

    /**
     * Open selection requests.
     *
     * @var list<\Crustum\AiInteraction\InteractionCommand\Selection>
     */
    private array $selections = [];

    /**
     * Out-of-turn answer handlers keyed by selection identity.
     *
     * Questions asked through askQuestion resolve here instead of starting a
     * turn, so a suspended host flow can collect the answer directly.
     *
     * @var array<int, \Closure(string|null): void>
     */
    private array $questionHandlers = [];

    /**
     * Open free-text question, null when none is waiting for an answer.
     *
     * Only one text question is open at a time: host question flows are
     * sequential, and the composer holds a single draft.
     *
     * @var \Crustum\AiTui\View\TextQuestion|null
     */
    private ?TextQuestion $textQuestion = null;

    /**
     * Answer handler for the open free-text question.
     *
     * @var (\Closure(string|null): void)|null
     */
    private ?Closure $textHandler = null;

    /**
     * Confirmed selection invocations awaiting a turn.
     *
     * @var list<\Crustum\AiInteraction\InteractionCommand\CommandInput>
     */
    private array $selectionResponses = [];

    /**
     * Builds the screen with history, header and composer.
     */
    public function __construct()
    {
        $this->history = new HistoryView();
        $this->header = new HeaderView();
        $this->composer = new ComposerView();
        $this->keys = new Keybindings([
            'stop' => [Key::ESCAPE],
            'quit' => ['ctrl+c'],
            'previous' => [Key::UP],
            'next' => [Key::DOWN],
            'confirm' => [Key::ENTER],
        ]);
        $this->setStyle(new Style(gap: 1));
        $this->add($this->header)->add($this->history)->add($this->composer);
        $this->composer->onSubmit($this->submit(...));
    }

    /**
     * Replaces the chat title.
     *
     * @param string $title Chat title
     * @return void
     */
    public function setTitle(string $title): void
    {
        $this->header->setTitle($title);
        $this->getContext()?->requestRender();
    }

    /**
     * Replaces the chat description.
     *
     * @param string $description Chat description
     * @return void
     */
    public function setDescription(string $description): void
    {
        $this->header->setDescription($description);
        $this->getContext()?->requestRender();
    }

    /**
     * Replaces the header banner.
     *
     * @param string $text Banner text
     * @param string $font Banner font name
     * @return void
     */
    public function setFiglet(string $text, string $font = 'standard'): void
    {
        $this->header->setFiglet($text, $font);
        $this->getContext()?->requestRender();
    }

    /**
     * Reloads the history from the sync supplier.
     *
     * @return void
     */
    public function syncHistory(): void
    {
        $this->history->load($this->onHistorySync instanceof Closure ? ($this->onHistorySync)() : []);
    }

    /**
     * Moves the terminal focus to the active input.
     *
     * @return void
     */
    public function focus(): void
    {
        $this->getContext()?->getFocusManager()->setFocus($this->composer->focusedInput());
    }

    /**
     * Reports whether a turn is in progress.
     *
     * @return bool True while the view waits for a turn to finish.
     */
    public function isBusy(): bool
    {
        return $this->busy;
    }

    /**
     * Handles raw terminal input for selections, menu and stop keys.
     *
     * @param \Symfony\Component\Tui\Event\InputEvent $event Terminal input event
     * @return void
     */
    public function handleInput(InputEvent $event): void
    {
        $data = $event->getData();
        if ($this->keys->matches($data, 'quit')) {
            $event->stopPropagation();
            $this->stop();
        } elseif ($this->selections !== []) {
            $event->stopPropagation();
            if ($this->keys->matches($data, 'previous')) {
                $this->composer->moveSelection(-1);
            } elseif ($this->keys->matches($data, 'next')) {
                $this->composer->moveSelection(1);
            } elseif ($this->keys->matches($data, 'confirm')) {
                $input = $this->composer->selectedInput();
                if ($input instanceof CommandInput) {
                    $this->resolveSelection($input);
                }
            } elseif ($this->keys->matches($data, 'stop')) {
                $this->resolveSelection(null);
            } else {
                $this->composer->handleSelectionInput($data);
            }

            $this->getContext()?->requestRender();
        } elseif ($this->textQuestion instanceof TextQuestion) {
            if ($this->keys->matches($data, 'stop')) {
                $event->stopPropagation();
                $this->resolveTextQuestion(null);
                $this->getContext()?->requestRender();
            }
        } elseif ($this->composer->handleCommandInput($data)) {
            $event->stopPropagation();
        } elseif ($this->keys->matches($data, 'stop') && $this->busy) {
            $this->onEscape?->__invoke();
            $event->stopPropagation();
        }
    }

    /**
     * Submits the prompt or queues it while busy.
     *
     * @param \Symfony\Component\Tui\Event\SubmitEvent $event Submit event
     * @return void
     */
    private function submit(SubmitEvent $event): void
    {
        if ($this->textQuestion instanceof TextQuestion) {
            if ($this->stopped || !($this->onInput instanceof Closure)) {
                return;
            }

            $answer = trim($event->getValue());

            if ($answer === '') {
                if ($this->textQuestion->default === '') {
                    return;
                }

                $answer = $this->textQuestion->default;
            }

            $this->resolveTextQuestion($answer);

            return;
        }

        if ($event->isBlank() || $this->stopped || !($this->onInput instanceof Closure)) {
            return;
        }

        $prompt = trim($event->getValue());
        $this->composer->input()->setText('');
        if ($this->busy) {
            $this->pending[] = $prompt;
            $this->pendingIsTask[] = false;
            $this->updatePendingMessages();
            $this->getContext()?->requestRender();

            return;
        }

        $this->beginTurn($prompt);
    }

    /**
     * Queues task text from a turn outcome to run as the next turn.
     *
     * Behaves like a queued prompt: the text shows in the pending preview and
     * runs after the current turn finishes. The text itself is never rendered
     * as chat; an origin label renders as its user line instead.
     *
     * @param string $text Task text to run as the next turn input.
     * @param string|null $origin Task origin label shown instead of the text, null runs silently.
     * @return void
     */
    public function queueTask(string $text, ?string $origin = null): void
    {
        if ($this->stopped || !($this->onInput instanceof Closure)) {
            return;
        }

        $task = trim($text);

        if ($task === '') {
            return;
        }

        $label = $origin !== null && trim($origin) !== '' ? trim($origin) : null;

        $this->pending[] = $task;
        $this->pendingIsTask[] = true;
        $this->pendingOrigin[] = $label;
        $this->updatePendingMessages();
        $this->getContext()?->requestRender();
    }

    /**
     * Starts a turn for the given prompt.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CommandInput|string $prompt Turn prompt
     * @param bool $task Task outcome text, shown without a user line.
     * @param string|null $origin Task origin label shown instead of the text, null runs silently.
     * @return void
     */
    private function beginTurn(string|CommandInput $prompt, bool $task = false, ?string $origin = null): void
    {
        $this->busy = true;
        $this->composer->beginTurn();

        if ($task && $origin !== null) {
            $this->history->beginTurn($origin);
        } elseif ($task) {
            $this->history->beginTaskTurn();
        } else {
            $this->history->beginTurn($this->inputText($prompt));
        }

        $this->getContext()?->requestRender();

        try {
            $this->onInput?->__invoke($prompt);
        } catch (Throwable $throwable) {
            $this->notify($throwable->getMessage(), MessageKind::Error);
            $this->finishTurn();
        }
    }

    /**
     * Stops the session and clears queued work.
     *
     * @return void
     */
    public function stop(): void
    {
        $this->stopped = true;
        $this->pending = [];
        $this->pendingIsTask = [];
        $this->pendingOrigin = [];
        $this->selections = [];
        $this->selectionResponses = [];

        foreach ($this->questionHandlers as $handler) {
            $handler(null);
        }

        $this->questionHandlers = [];

        if ($this->textHandler instanceof Closure) {
            $handler = $this->textHandler;
            $this->textQuestion = null;
            $this->textHandler = null;
            $handler(null);
        }

        $this->composer->showSelection(null);
        $this->composer->showQuestion(null);
        $this->updatePendingMessages();
        $this->getContext()?->stop();
    }

    /**
     * Registers the mounted commands supplier.
     *
     * @param \Closure(): list<\Crustum\AiInteraction\InteractionCommand\CommandInterface> $commands Mounted commands supplier
     * @return void
     */
    public function onCommands(Closure $commands): void
    {
        $this->composer->onCommands($commands);
    }

    /**
     * Registers the prompt submission listener.
     *
     * @param \Closure(string|\Crustum\AiInteraction\InteractionCommand\CommandInput): void $listener Prompt submission listener
     * @return void
     */
    public function onInput(Closure $listener): void
    {
        $this->onInput = $listener;
    }

    /**
     * Registers the conversation history supplier.
     *
     * @param \Closure(): iterable<\Crustum\Ai\Messages\Message> $listener Conversation history supplier
     * @return void
     */
    public function onHistorySync(Closure $listener): void
    {
        $this->onHistorySync = $listener;
    }

    /**
     * Registers the user message history supplier for recall.
     *
     * @param \Closure(): (list<\Crustum\Ai\Messages\UserMessage>|null) $listener User message history supplier
     * @return void
     */
    public function onInputHistory(Closure $listener): void
    {
        $this->composer->onInputHistory($listener);
    }

    /**
     * Registers the escape listener.
     *
     * @param (\Closure(): bool)|(\Closure(): void) $listener Escape listener
     * @return void
     */
    public function onEscape(Closure $listener): void
    {
        $this->onEscape = $listener;
    }

    /**
     * Appends streamed text to the turn in progress.
     *
     * @param string $text Streamed text
     * @return void
     * @throws \LogicException When no turn is in progress
     */
    public function appendResponse(string $text): void
    {
        if (!$this->busy) {
            throw new LogicException('No turn is in progress.');
        }

        $this->history->appendResponse($text);
        $this->getContext()?->requestRender();
    }

    /**
     * Appends reasoning text to the muted thinking line of the turn.
     *
     * @param string $text Reasoning delta text
     * @return void
     * @throws \LogicException When no turn is in progress
     */
    public function appendThinking(string $text): void
    {
        if (!$this->busy) {
            throw new LogicException('No turn is in progress.');
        }

        $this->history->appendThinking($text);
        $this->getContext()?->requestRender();
    }

    /**
     * Appends a notice line to the turn in progress.
     *
     * @param string $text Notice text
     * @param \Crustum\AiTui\View\MessageKind $kind Notice category
     * @return void
     * @throws \LogicException When no turn is in progress
     */
    public function notify(string $text, MessageKind $kind = MessageKind::Notice): void
    {
        if (!$this->busy) {
            throw new LogicException('No turn is in progress.');
        }

        $this->history->notify($text, $kind);
        $this->getContext()?->requestRender();
    }

    /**
     * Shows the pending block for a tool invocation.
     *
     * @param \Crustum\Ai\Responses\Data\ToolCall $tool Tool invocation data
     * @return void
     * @throws \LogicException When no turn is in progress
     */
    public function showToolCall(ToolCall $tool): void
    {
        if (!$this->busy) {
            throw new LogicException('No turn is in progress.');
        }

        $this->history->showToolCall($tool);
        $this->getContext()?->requestRender();
    }

    /**
     * Completes the block of a finished tool invocation.
     *
     * @param \Crustum\Ai\Responses\Data\ToolResult $result Finished tool result
     * @return void
     * @throws \LogicException When no turn is in progress
     */
    public function showToolResult(ToolResult $result): void
    {
        if (!$this->busy) {
            throw new LogicException('No turn is in progress.');
        }

        $this->history->showToolResult($result);
        $this->getContext()?->requestRender();
    }

    /**
     * Opens a selection request on the composer.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\Selection $request Selection request
     * @return void
     */
    public function showSelection(Selection $request): void
    {
        if ($this->stopped) {
            return;
        }

        $this->selections[] = $request;
        if (count($this->selections) === 1) {
            $this->composer->showSelection($request);
            $this->focus();
        }

        $this->getContext()?->requestRender();
    }

    /**
     * Opens a question answered out of band instead of as a turn.
     *
     * The widget is the same selection picker, but resolving it invokes the
     * handler with the chosen value instead of dispatching a command, so a
     * suspended host flow — an approval prompt, a policy question — collects
     * the answer without starting a turn. Dismissing answers null.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\Selection $request Selection request
     * @param \Closure(string|null): void $onAnswer Answer handler, null when dismissed.
     * @return void
     */
    public function askQuestion(Selection $request, Closure $onAnswer): void
    {
        if ($this->stopped) {
            $onAnswer(null);

            return;
        }

        $this->questionHandlers[spl_object_id($request)] = $onAnswer;
        $this->showSelection($request);
    }

    /**
     * Opens a free-text question answered out of band instead of as a turn.
     *
     * The composer shows the prompt and keeps its draft editor: Enter answers
     * with the typed text, Escape dismisses with null. Like askQuestion this
     * never starts a turn, so a suspended host flow collects the answer
     * directly. Command completion is bypassed while the question is open.
     *
     * @param \Crustum\AiTui\View\TextQuestion $request Text question request
     * @param \Closure(string|null): void $onAnswer Answer handler, null when dismissed.
     * @return void
     */
    public function askText(TextQuestion $request, Closure $onAnswer): void
    {
        if ($this->stopped) {
            $onAnswer(null);

            return;
        }

        $this->textQuestion = $request;
        $this->textHandler = $onAnswer;
        $this->composer->showQuestion($request);
        $this->focus();
        $this->getContext()?->requestRender();
    }

    /**
     * Resolves the open free-text question without starting a turn.
     *
     * @param string|null $answer Answer text, null when dismissed.
     * @return void
     */
    private function resolveTextQuestion(?string $answer): void
    {
        $handler = $this->textHandler;
        $this->textQuestion = null;
        $this->textHandler = null;
        $this->composer->showQuestion(null);
        $this->composer->input()->setText('');
        $this->focus();

        if ($handler instanceof Closure) {
            $handler($answer);
        }

        $this->updatePendingMessages();
    }

    /**
     * Resolves the open selection and starts the next turn.
     *
     * Selections asked as questions resolve through their answer handler
     * instead: no turn starts, the suspended host flow continues.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CommandInput|null $input Confirmed invocation
     * @return void
     */
    private function resolveSelection(?CommandInput $input): void
    {
        $request = array_shift($this->selections);
        $handler = $request === null ? null : ($this->questionHandlers[spl_object_id($request)] ?? null);

        if ($request !== null) {
            unset($this->questionHandlers[spl_object_id($request)]);
        }

        $this->composer->showSelection($this->selections[0] ?? null);
        $this->focus();

        if ($handler instanceof Closure) {
            $handler($input?->value);
            $this->updatePendingMessages();

            return;
        }

        if ($input instanceof CommandInput) {
            $this->selectionResponses[] = $input;
        }

        $this->updatePendingMessages();
        $this->startNextTurn();
    }

    /**
     * Starts the next queued turn when idle.
     *
     * Hosts queue the first task before the loop starts; the run method
     * kicks it here so pre-queued work does not wait for typed input.
     *
     * @return void
     */
    public function startNextTurn(): void
    {
        if ($this->busy || $this->stopped) {
            return;
        }

        $task = false;
        $origin = null;

        if ($this->selectionResponses !== []) {
            $input = array_shift($this->selectionResponses);
        } elseif ($this->selections === [] && $this->pending !== []) {
            $input = array_shift($this->pending);
            $task = array_shift($this->pendingIsTask) ?? false;
            $origin = array_shift($this->pendingOrigin);
        } else {
            return;
        }

        $this->updatePendingMessages();
        $this->beginTurn($input, $task, $origin);
    }

    /**
     * Refreshes the queued messages preview.
     *
     * @return void
     */
    private function updatePendingMessages(): void
    {
        $this->composer->setPendingMessages(array_merge(
            array_map($this->inputText(...), $this->selectionResponses),
            $this->pending,
        ));
    }

    /**
     * Renders a turn prompt as history text.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CommandInput|string $input Turn prompt
     * @return string
     */
    private function inputText(string|CommandInput $input): string
    {
        return $input instanceof CommandInput
            ? $input->identifier . ($input->value === '' ? '' : ' ' . $input->value)
            : $input;
    }

    /**
     * Finishes the turn in progress and starts queued work.
     *
     * @return void
     */
    public function finishTurn(): void
    {
        $this->history->finishTurn();
        $this->composer->finishTurn();
        $this->busy = false;
        $this->startNextTurn();
        $this->focus();
        $this->getContext()?->requestRender();
    }
}
