<?php
declare(strict_types=1);

namespace Crustum\AiTui\Test\Unit\View;

use Crustum\AiInteraction\InteractionCommand\Selection;
use Crustum\AiInteraction\InteractionCommand\SelectionOption;
use Crustum\AiTui\View\ComposerView;
use Crustum\AiTui\View\SelectionView;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use function array_map;
use function count;
use function implode;
use function str_repeat;

/**
 * Unit coverage for the selection picker search and rendering.
 */
final class SelectionViewTest extends TestCase
{
    public function testLongSelectionScrollsAndFitsNarrowTerminals(): void
    {
        $selection = new SelectionView();
        $options = [];
        for ($index = 0; $index < 12; ++$index) {
            $options[] = new SelectionOption('value-' . $index, 'Option ' . $index, str_repeat('Description ', 20));
        }

        $selection->show(new Selection('/choose', 'Choose an option', $options));
        for ($index = 0; $index < 9; ++$index) {
            $selection->move(1);
        }

        foreach ([50, 20, 2, 1] as $columns) {
            $lines = $selection->render(new RenderContext($columns, 10));
            self::assertLessThanOrEqual(12, count($lines));
            foreach ($lines as $line) {
                self::assertLessThanOrEqual($columns, AnsiUtils::visibleWidth($line));
            }
        }

        $screen = AnsiUtils::stripAnsiCodes(implode("\n", $selection->render(new RenderContext(50, 10))));
        self::assertStringContainsString('Choose an option (10/12)', $screen);
        self::assertStringContainsString('❯ Option 9', $screen);
        self::assertStringNotContainsString('Option 0', $screen);
        self::assertSame('value-9', $selection->selectedInput()?->value);
        for ($index = 0; $index < 3; ++$index) {
            $selection->move(1);
        }

        self::assertSame('value-0', $selection->selectedInput()?->value);
        $selection->move(-1);
        self::assertSame('value-11', $selection->selectedInput()?->value);
    }

    public function testSearchFiltersLabelsAndDescriptionsAndKeepsOriginalValues(): void
    {
        $selection = new SelectionView();
        $selection->show(new Selection('/choose', 'Choose an option', [
            new SelectionOption('first', 'English', 'First language'),
            new SelectionOption("  opaque\nvalue  ", 'Italian', 'Lingua italiana'),
        ]));
        $selection->handleInput('ITAL');

        $lines = $selection->render(new RenderContext(60, 40));
        $screen = AnsiUtils::stripAnsiCodes(implode("\n", $lines));
        self::assertStringContainsString('❯ Italian', $screen);
        self::assertStringNotContainsString('English', $screen);
        self::assertSame("  opaque\nvalue  ", $selection->selectedInput()?->value);
        $selection->handleInput("\x15");
        $selection->handleInput('First language');
        self::assertSame('first', $selection->selectedInput()?->value);
        $selection->handleInput(' missing');
        self::assertNull($selection->selectedInput());
        self::assertStringContainsString(
            'No matching options.',
            implode("\n", $selection->render(new RenderContext(60, 40))),
        );
        $selection->handleInput("\x15");
        self::assertSame('first', $selection->selectedInput()?->value);
        $selection->show(null);
        $selection->show(new Selection('/other', 'New request', [new SelectionOption('new', 'New option')]));
        self::assertSame('', $selection->search()->getValue());
        self::assertSame('new', $selection->selectedInput()?->value);
    }

    public function testSelectorUsesAFramedSearchAndSeparateDescriptionRows(): void
    {
        $selection = new SelectionView();
        $selection->show(new Selection('/resume', 'Resume session', [
            new SelectionOption('session-key', 'A long session title', '2 minutes ago · 347.5KB'),
        ]));
        $lines = $selection->render(new RenderContext(60, 40));
        $lines = array_map(AnsiUtils::stripAnsiCodes(...), $lines);
        self::assertSame(str_repeat('─', 60), $lines[0]);
        self::assertStringContainsString('Resume session', $lines[2]);
        self::assertStringStartsWith('  ╭', $lines[4]);
        self::assertStringContainsString('Search…', $lines[5]);
        self::assertStringStartsWith('  ╰', $lines[6]);
        self::assertSame('  ❯ A long session title', $lines[8]);
        self::assertSame('    2 minutes ago · 347.5KB', $lines[9]);
    }

    public function testSelectionTextCannotInjectTerminalControls(): void
    {
        $selection = new SelectionView();
        $selection->show(new Selection('/choose', "Prompt\nnext\033[2J", [
            new SelectionOption("  raw\nvalue  ", "Label\nnext\033[2J", "Description\tnext\033[2J"),
        ], "Help\rnext\033[2J"));
        $lines = $selection->render(new RenderContext(80, 20));
        foreach ($lines as $line) {
            self::assertStringNotContainsString("\n", $line);
            self::assertStringNotContainsString("\033[2J", $line);
            self::assertStringNotContainsString("\t", $line);
        }

        self::assertSame("  raw\nvalue  ", $selection->selectedInput()?->value);
        $selection->show(null);
        self::assertNull($selection->selectedInput());
        self::assertSame([], $selection->render(new RenderContext(80, 20)));
    }

    public function testComposerRestoresDraftAfterSelectionCloses(): void
    {
        $composer = new ComposerView();
        $composer->input()->setText('My draft');
        $renderer = new Renderer();
        $context = new RenderContext(50, 20);
        $composer->showSelection(new Selection('/choose', 'Choose an option', [
            new SelectionOption('value', 'Option'),
        ]));
        $screen = AnsiUtils::stripAnsiCodes(implode("\n", $renderer->renderWidget($composer, $context)));
        self::assertStringContainsString('Choose an option', $screen);
        self::assertStringNotContainsString('My draft', $screen);
        $composer->showSelection(null);
        $screen = AnsiUtils::stripAnsiCodes(implode("\n", $renderer->renderWidget($composer, $context)));
        self::assertStringContainsString('❯ My draft', $screen);
        self::assertStringNotContainsString('Choose an option', $screen);
    }
}
