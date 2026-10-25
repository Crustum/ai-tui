<?php
declare(strict_types=1);

namespace Crustum\AiTui\Test\Unit\View;

use Crustum\Ai\Messages\UserMessage;
use Crustum\AiInteraction\InteractionCommand\HelpCommand;
use Crustum\AiTui\View\ComposerView;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use function count;
use function implode;
use function str_repeat;

/**
 * Unit coverage for the composer menu, recall and queue preview.
 */
final class ComposerViewTest extends TestCase
{
    public function testRepeatedUpRecallsCommandsPastExit(): void
    {
        $composer = new ComposerView();
        $composer->onCommands(static fn(): array => [new HelpCommand(), new HelpCommand('/exit')]);
        $composer->onInputHistory(static fn(): array => [new UserMessage('/help'), new UserMessage('/exit')]);
        foreach (['/exit', '/help', '/help'] as $expected) {
            if (!$composer->handleCommandInput("\x1b[A")) {
                $composer->input()->handleInput("\x1b[A");
            }

            self::assertSame($expected, $composer->input()->getText());
        }

        foreach (['/exit', ''] as $expected) {
            if (!$composer->handleCommandInput("\x1b[B")) {
                $composer->input()->handleInput("\x1b[B");
            }

            self::assertSame($expected, $composer->input()->getText());
        }
    }

    public function testEditingRecalledCommandsOpensCompletionAgain(): void
    {
        $composer = new ComposerView();
        $composer->onCommands(static fn(): array => [new HelpCommand('/exit'), new HelpCommand('/extra')]);
        $composer->onInputHistory(static fn(): array => [new UserMessage('/exit')]);
        $composer->input()->handleInput("\x1b[A");
        self::assertFalse($composer->handleCommandInput("\t"));
        $composer->input()->handleInput("\x7f");
        $composer->input()->handleInput("\x7f");
        self::assertSame('/ex', $composer->input()->getText());
        self::assertTrue($composer->handleCommandInput("\x1b[B"));
        self::assertTrue($composer->handleCommandInput("\t"));
        self::assertSame('/extra ', $composer->input()->getText());
    }

    public function testCommandMenuKeepsArrowPriorityWhenHistoryIsEnabled(): void
    {
        $composer = new ComposerView();
        $composer->input()->onInputHistory(static fn(): array => ['Previous input']);
        $composer->onCommands(static fn(): array => [new HelpCommand('/first'), new HelpCommand('/second')]);
        $composer->input()->setText('/');
        self::assertTrue($composer->handleCommandInput("\x1b[B"));
        self::assertSame('/', $composer->input()->getText());
        self::assertTrue($composer->handleCommandInput("\t"));
        self::assertSame('/second ', $composer->input()->getText());
        self::assertFalse($composer->handleCommandInput("\x1b[A"));
        $composer->input()->handleInput("\x1b[A");
        self::assertSame('Previous input', $composer->input()->getText());
    }

    public function testCommandMenuScrollsAndFitsNarrowTerminals(): void
    {
        $composer = new ComposerView();
        $commands = [];
        for ($index = 0; $index < 8; ++$index) {
            $commands[] = new HelpCommand('/command-' . $index);
        }

        $composer->onCommands(static fn(): array => $commands);
        $renderer = new Renderer();
        $context = new RenderContext(30, 18);
        $composer->input()->setText('/');
        for ($index = 0; $index < 6; ++$index) {
            self::assertTrue($composer->handleCommandInput("\x1b[B"));
        }

        $lines = $renderer->renderWidget($composer, $context);
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(30, AnsiUtils::visibleWidth($line));
        }

        $screen = AnsiUtils::stripAnsiCodes(implode("\n", $lines));
        self::assertStringNotContainsString('/command-0', $screen);
        self::assertStringContainsString('❯ /command-6', $screen);
        self::assertTrue($composer->handleCommandInput("\t"));
        self::assertSame('/command-6 ', $composer->input()->getText());
        self::assertFalse($composer->handleCommandInput("\x1b[B"));
        $composer->input()->setText('/');
        self::assertTrue($composer->handleCommandInput("\x1b[A"));
        self::assertTrue($composer->handleCommandInput("\t"));
        self::assertSame('/command-7 ', $composer->input()->getText());
    }

    public function testCommandArgumentsMultilineAndUnknownNamesKeepNormalSubmission(): void
    {
        $composer = new ComposerView();
        $composer->onCommands(static fn(): array => [new HelpCommand()]);

        $submitted = [];
        $composer->onSubmit(function (SubmitEvent $event) use (&$submitted): void {
            $submitted[] = $event->getValue();
        });
        foreach (['/unknown', '/help argument', "/help\nMore text", 'A /help message'] as $text) {
            $composer->input()->setText($text);
            self::assertFalse($composer->handleCommandInput("\r"));
            $composer->input()->handleInput("\r");
        }

        self::assertSame(['/unknown', '/help argument', "/help\nMore text", 'A /help message'], $submitted);
        $submitted = [];
        $composer->input()->setText('/he');
        self::assertTrue($composer->handleCommandInput("\r"));
        self::assertSame(['/help'], $submitted);
        self::assertFalse($composer->handleCommandInput("\r"));
    }

    public function testExactCommandNameWinsOverEarlierPrefixMatches(): void
    {
        $composer = new ComposerView();
        $composer->onCommands(static fn(): array => [new HelpCommand('/helper'), new HelpCommand('/help')]);

        $submitted = null;
        $composer->onSubmit(function (SubmitEvent $event) use (&$submitted): void {
            $submitted = $event->getValue();
        });
        $composer->input()->setText('/help');
        self::assertTrue($composer->handleCommandInput("\r"));
        self::assertSame('/help', $submitted);
        $composer->onCommands(static fn(): array => []);
        $composer->input()->setText('/');
        self::assertFalse($composer->handleCommandInput("\r"));
    }

    public function testQueuePreviewStaysCompactAndFitsANarrowTerminal(): void
    {
        $composer = new ComposerView();
        $renderer = new Renderer();
        $context = new RenderContext(30, 18);
        $before = $renderer->renderWidget($composer, $context);
        $composer->setPendingMessages([
            "First\nsecond line",
            str_repeat('Long message ', 20),
            "Third\033]0;title\007 message",
            'Fourth message',
            'Fifth message',
        ]);
        $lines = $renderer->renderWidget($composer, $context);
        $screen = AnsiUtils::stripAnsiCodes(implode("\n", $lines));
        self::assertStringContainsString('• Messages queued', $screen);
        self::assertStringContainsString('↳ First second line', $screen);
        self::assertStringContainsString('↳ Long message', $screen);
        self::assertStringContainsString('↳ Third]0;title message', $screen);
        self::assertStringNotContainsString('Fourth message', $screen);
        self::assertStringContainsString('… +2 more', $screen);
        self::assertStringNotContainsString("\033]0;title", implode("\n", $lines));
        self::assertSame(count($before) + 7, count($lines));
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(30, AnsiUtils::visibleWidth($line));
        }

        $composer->setPendingMessages([]);
        self::assertSame($before, $renderer->renderWidget($composer, $context));
    }
}
