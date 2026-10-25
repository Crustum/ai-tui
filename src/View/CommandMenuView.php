<?php
declare(strict_types=1);

namespace Crustum\AiTui\View;

use Closure;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use function array_slice;
use function count;
use function intdiv;
use function max;
use function min;
use function preg_match;
use function str_replace;
use function str_starts_with;

/**
 * Slash-command completion menu shown above the composer.
 */
final class CommandMenuView extends AbstractWidget
{
    /**
     * Mounted commands supplier.
     *
     * @var (\Closure(): list<\Crustum\AiInteraction\InteractionCommand\CommandInterface>)|null
     */
    private ?Closure $commands = null;

    /**
     * Commands matching the current composer text.
     *
     * @var list<\Crustum\AiInteraction\InteractionCommand\CommandInterface>
     */
    private array $matches = [];

    /**
     * Index of the highlighted match.
     *
     * @var int
     */
    private int $selected = 0;

    /**
     * Builds the hidden menu.
     */
    public function __construct()
    {
        $this->dismiss();
    }

    /**
     * Registers the mounted commands supplier.
     *
     * @param \Closure(): list<\Crustum\AiInteraction\InteractionCommand\CommandInterface> $commands Mounted commands supplier
     * @return void
     */
    public function onCommands(Closure $commands): void
    {
        $this->commands = $commands;
    }

    /**
     * Refreshes the matches for the given composer text.
     *
     * @param string $text Composer text
     * @return void
     */
    public function update(string $text): void
    {
        $this->matches = [];
        $this->selected = 0;
        if ($this->commands instanceof Closure && preg_match('~^/[A-Za-z0-9_-]*$~D', $text) === 1) {
            foreach (($this->commands)() as $command) {
                if (str_starts_with($command->name(), $text)) {
                    $this->matches[] = $command;
                    if ($command->name() === $text) {
                        $this->selected = count($this->matches) - 1;
                    }
                }
            }
        }

        $this->setStyle(new Style(hidden: $this->matches === []));
    }

    /**
     * Returns the highlighted command name.
     *
     * @return string|null
     */
    public function selectedName(): ?string
    {
        return ($this->matches[$this->selected] ?? null)?->name();
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
     * Clears the matches and hides the menu.
     *
     * @return void
     */
    public function dismiss(): void
    {
        $this->matches = [];
        $this->setStyle(new Style(hidden: true));
    }

    /**
     * Renders the visible matches with descriptions.
     *
     * @param \Symfony\Component\Tui\Render\RenderContext $context Render context
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        $columns = max(1, $context->getColumns());
        $limit = min(5, max(1, intdiv($this->getContext()?->getTerminalRows() ?? 24, 3)));
        $offset = max(0, $this->selected - $limit + 1);
        $visible = array_slice($this->matches, $offset, $limit);
        $nameWidth = 0;
        foreach ($this->matches as $command) {
            $nameWidth = max($nameWidth, AnsiUtils::visibleWidth($command->name()));
        }

        $nameWidth = min($nameWidth, max(1, intdiv($columns, 2) - 4));
        $lines = [];
        foreach ($visible as $index => $command) {
            $selected = $index + $offset === $this->selected;
            $name = AnsiUtils::truncateToWidth($command->name(), $nameWidth, '', true);
            $description = str_replace(["\r", "\n", "\t"], ' ', StringUtils::stripControlBytes($command->describe()));
            $line = $this->applyElement($selected ? 'selected' : 'name', ($selected ? '❯ ' : '  ') . $name)
                . '  ' . $this->applyElement('description', $description);
            $lines[] = AnsiUtils::truncateToWidth($line, $columns, '');
        }

        return $lines;
    }
}
