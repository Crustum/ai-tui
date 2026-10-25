<?php
declare(strict_types=1);

namespace Crustum\AiTui\View;

use Closure;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\EditorWidget;
use function array_values;
use function count;
use function explode;
use function max;
use function str_contains;

/**
 * Multiline prompt editor with submission-history recall.
 */
final class ComposerInputView extends EditorWidget
{
    /**
     * History entries supplier wired by the composer.
     *
     * @var (\Closure(): (list<string>|null))|null
     */
    private ?Closure $history = null;

    /**
     * Listener run when a recalled entry replaces the draft.
     *
     * @var (\Closure(): void)|null
     */
    private ?Closure $onRecall = null;

    /**
     * Entries of the active recall session.
     *
     * @var list<string>
     */
    private array $entries = [];

    /**
     * Position inside the active recall session.
     *
     * @var int|null
     */
    private ?int $position = null;

    /**
     * Draft preserved while a recall session is active.
     *
     * @var string
     */
    private string $draft = '';

    /**
     * Builds the editor with composer height and color.
     */
    public function __construct()
    {
        parent::__construct();
        $this->setMinVisibleLines(1)->setMaxVisibleLines(5)->setStyle(new Style(color: Theme::SECONDARY));
    }

    /**
     * Replaces the editor text and resets any recall session.
     *
     * @param string $text Editor text
     * @return static
     */
    public function setText(string $text): static
    {
        $this->position = null;
        $this->entries = [];
        $changed = $text !== $this->getText();
        parent::setText($text);
        if ($changed) {
            $this->dispatch(new ChangeEvent($this, $this->getText()));
        }

        return $this;
    }

    /**
     * Registers the history entries supplier.
     *
     * @param \Closure(): (list<string>|null) $history History entries supplier
     * @return void
     */
    public function onInputHistory(Closure $history): void
    {
        $this->history = $history;
    }

    /**
     * Registers the recall listener.
     *
     * @param \Closure(): void $listener Recall listener
     * @return void
     */
    public function onRecall(Closure $listener): void
    {
        $this->onRecall = $listener;
    }

    /**
     * Handles raw terminal input with history recall shortcuts.
     *
     * @param string $data Raw terminal input
     * @return void
     */
    public function handleInput(string $data): void
    {
        $keys = $this->getKeybindings();
        $singleLine = !str_contains($this->getText(), "\n");
        if ($this->history instanceof Closure) {
            if ($keys->matches($data, 'history_previous') || ($singleLine && $keys->matches($data, 'cursor_up'))) {
                if ($this->recall(-1)) {
                    return;
                }
            }

            if ($keys->matches($data, 'history_next') || ($singleLine && $keys->matches($data, 'cursor_down'))) {
                if ($this->recall(1)) {
                    return;
                }
            }
        }

        $before = $this->getText();
        parent::handleInput($data);
        if ($this->getText() !== $before) {
            $this->position = null;
            $this->entries = [];
        }
    }

    /**
     * Moves inside the recall session and replaces the editor text.
     *
     * @param int $direction Recall direction
     * @return bool
     */
    private function recall(int $direction): bool
    {
        if ($this->position === null) {
            $entries = $this->history instanceof Closure ? ($this->history)() : null;
            if ($entries === null) {
                return false;
            }

            if ($direction > 0) {
                return true;
            }

            $this->entries = $entries;
            if ($this->entries === []) {
                return true;
            }

            $this->draft = $this->getText();
            $this->position = count($this->entries);
        }

        $this->position = max(0, $this->position + $direction);
        $text = $this->entries[$this->position] ?? $this->draft;
        parent::setText($text);
        foreach (explode("\n", $text) as $line) {
            parent::handleInput("\x1b[B");
        }

        $this->dispatch(new ChangeEvent($this, $this->getText()));
        if ($this->position >= count($this->entries)) {
            $this->position = null;
            $this->entries = [];
        } else {
            $this->onRecall?->__invoke();
        }

        return true;
    }

    /**
     * Returns the editor keybindings extended with history shortcuts.
     *
     * @return array<string, string[]>
     */
    protected static function getDefaultKeybindings(): array
    {
        return parent::getDefaultKeybindings() + [
            'history_previous' => ['ctrl+p'],
            'history_next' => ['ctrl+n'],
        ];
    }

    /**
     * Renders the editor with prompt and frame decorations.
     *
     * @param \Symfony\Component\Tui\Render\RenderContext $context Render context
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        $columns = $context->getColumns();
        $lines = array_values(parent::render($context->withColumns(max(1, $columns - 2))));
        $last = count($lines) - 1;
        $prompt = (new Style(color: Theme::PROMPT))->apply('❯')
            . (new Style(color: Theme::SECONDARY))->getAnsiRestore() . ' ';
        $border = (new Style(color: Theme::BORDER))->apply('──');
        foreach ($lines as $index => $line) {
            $prefix = $index === 0 || $index === $last ? $border : ($index === 1 ? $prompt : '  ');
            $lines[$index] = AnsiUtils::truncateToWidth($prefix . $line, max(1, $columns), '');
        }

        return $lines;
    }
}
