<?php
declare(strict_types=1);

namespace Crustum\AiTui\View;

use Crustum\AiInteraction\InteractionCommand\CommandInput;
use Crustum\AiInteraction\InteractionCommand\Selection;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\ParentInterface;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use Symfony\Component\Tui\Widget\WidgetContext;
use function array_slice;
use function count;
use function intdiv;
use function max;
use function mb_strtolower;
use function min;
use function str_contains;
use function str_repeat;
use function str_replace;
use function trim;

/**
 * Framed option picker with live search shown above the composer.
 */
final class SelectionView extends AbstractWidget implements ParentInterface
{
    /**
     * Active selection request.
     *
     * @var \Crustum\AiInteraction\InteractionCommand\Selection|null
     */
    private ?Selection $request = null;

    /**
     * Index of the highlighted match.
     *
     * @var int
     */
    private int $selected = 0;

    /**
     * Search field filtering the options.
     *
     * @var \Symfony\Component\Tui\Widget\InputWidget
     */
    private readonly InputWidget $search;

    /**
     * Options matching the current search value.
     *
     * @var list<\Crustum\AiInteraction\InteractionCommand\SelectionOption>
     */
    private array $matches = [];

    /**
     * Builds the hidden picker with its search field.
     */
    public function __construct()
    {
        $this->search = (new InputWidget())->setPrompt('⌕ ');
        $this->search->onChange(fn(ChangeEvent $event) => $this->filter());
        $this->show(null);
    }

    /**
     * Shows the given request or hides the picker.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\Selection|null $request Selection request
     * @return void
     */
    public function show(?Selection $request): void
    {
        $wasVisible = $this->request instanceof Selection;
        $this->request = $request;
        if (!$wasVisible && $request instanceof Selection) {
            $this->getContext()?->attachChild($this, $this->search);
        } elseif ($wasVisible && !($request instanceof Selection) && $this->getContext() instanceof WidgetContext) {
            $this->getContext()->detachChild($this->search);
            $this->search->onChange(fn(ChangeEvent $event) => $this->filter());
        }

        $this->search->setValue('');
        $this->filter();
        $this->setStyle(new Style(hidden: !($request instanceof Selection)));
    }

    /**
     * Returns nested widgets rendered inside the picker.
     *
     * @return list<\Symfony\Component\Tui\Widget\AbstractWidget>
     */
    public function all(): array
    {
        return $this->request instanceof Selection ? [$this->search] : [];
    }

    /**
     * Returns the search field.
     *
     * @return \Symfony\Component\Tui\Widget\InputWidget
     */
    public function search(): InputWidget
    {
        return $this->search;
    }

    /**
     * Forwards raw terminal input to the search field.
     *
     * @param string $data Raw terminal input
     * @return void
     */
    public function handleInput(string $data): void
    {
        $this->search->handleInput($data);
        $this->invalidate();
    }

    /**
     * Refreshes the matches for the current search value.
     *
     * @return void
     */
    private function filter(): void
    {
        $this->matches = [];
        $this->selected = 0;
        $query = mb_strtolower(trim($this->search->getValue()));
        if (!($this->request instanceof Selection)) {
            $this->invalidate();

            return;
        }

        foreach ($this->request->options as $option) {
            $text = $this->singleLine($option->label . ' ' . ($option->description ?? ''));
            if ($query === '' || str_contains(mb_strtolower($text), $query)) {
                $this->matches[] = $option;
            }
        }

        $this->invalidate();
    }

    /**
     * Moves the highlight inside the matches.
     *
     * @param int $direction Highlight direction
     * @return void
     */
    public function move(int $direction): void
    {
        $total = count($this->matches);
        if ($total === 0) {
            return;
        }

        $this->selected = ($this->selected + $direction + $total) % $total;
        $this->invalidate();
    }

    /**
     * Returns the invocation for the highlighted option.
     *
     * @return \Crustum\AiInteraction\InteractionCommand\CommandInput|null
     */
    public function selectedInput(): ?CommandInput
    {
        if (!($this->request instanceof Selection) || $this->matches === []) {
            return null;
        }

        return new CommandInput($this->request->command, $this->matches[$this->selected]->value);
    }

    /**
     * Renders the framed picker with search and options.
     *
     * @param \Symfony\Component\Tui\Render\RenderContext $context Render context
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        if (!($this->request instanceof Selection)) {
            return [];
        }

        $columns = max(1, $context->getColumns());
        $indent = $columns >= 12 ? '  ' : '';
        $total = count($this->matches);
        $position = $total === 0 ? 0 : $this->selected + 1;
        $lines = [$this->applyElement('separator', str_repeat('─', $columns)), ''];
        $lines[] = $this->line('title', $indent . $this->singleLine($this->request->prompt)
            . " ({$position}/{$total})", $columns);
        if ($this->request->description !== null) {
            $lines[] = $this->line('description', $indent . $this->singleLine($this->request->description), $columns);
        }

        $lines[] = '';
        $searchWidth = max(1, $columns - AnsiUtils::visibleWidth($indent) - 2);
        $lines[] = $this->line('border', $indent . '╭' . str_repeat('─', $searchWidth) . '╮', $columns);
        if ($this->search->getValue() === '') {
            $search = '⌕ ' . ($this->search->isFocused() ? AnsiUtils::cursorMarker() : '') . 'Search…';
            $search = $this->applyElement('description', AnsiUtils::truncateToWidth($search, $searchWidth, '', true));
        } else {
            $search = $this->applyElement('search', $this->search->render($context->withColumns($searchWidth))[0]);
        }

        $lines[] = AnsiUtils::truncateToWidth(
            $this->applyElement('border', $indent . '│') . $search . $this->applyElement('border', '│'),
            $columns,
            '',
        );
        $lines[] = $this->line('border', $indent . '╰' . str_repeat('─', $searchWidth) . '╯', $columns);
        $lines[] = '';
        $terminalRows = $this->getContext()?->getTerminalRows() ?? max(18, $context->getRows());
        $budget = max(12, intdiv($terminalRows, 2));
        $limit = min(5, max(1, intdiv($budget - count($lines) - 2, 3)));
        $offset = max(0, $this->selected - $limit + 1);
        foreach (array_slice($this->matches, $offset, $limit) as $index => $option) {
            $selected = $index + $offset === $this->selected;
            $lines[] = $this->line(
                $selected ? 'selected' : 'label',
                $indent . ($selected ? '❯ ' : '  ') . $this->singleLine($option->label),
                $columns,
            );
            if ($option->description !== null) {
                $lines[] = $this->line(
                    'description',
                    $indent . '  ' . $this->singleLine($option->description),
                    $columns,
                );
            }

            $lines[] = '';
        }

        if ($this->matches === []) {
            $lines[] = $this->line('description', $indent . '  No matching options.', $columns);
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * Renders one picker line with its element style.
     *
     * @param string $element Stylesheet element name
     * @param string $text Line text
     * @param int $columns Available columns
     * @return string
     */
    private function line(string $element, string $text, int $columns): string
    {
        return $this->applyElement($element, AnsiUtils::truncateToWidth($text, $columns, ''));
    }

    /**
     * Collapses control bytes and whitespace runs into single spaces.
     *
     * @param string $text Raw text
     * @return string
     */
    private function singleLine(string $text): string
    {
        return str_replace(["\r", "\n", "\t"], ' ', StringUtils::stripControlBytes($text));
    }
}
