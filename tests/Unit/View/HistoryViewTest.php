<?php
declare(strict_types=1);

namespace Crustum\AiTui\Test\Unit\View;

use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\Message;
use Crustum\Ai\Messages\MessageRole;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\ToolResult;
use Crustum\AiTui\View\HistoryView;
use Crustum\AiTui\View\MessageKind;
use Crustum\AiTui\View\MessageView;
use Crustum\AiTui\View\Theme;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui as SymfonyTui;
use function count;
use function implode;
use function rtrim;
use function substr_count;

/**
 * Unit coverage for the history rendering and tool blocks.
 */
final class HistoryViewTest extends TestCase
{
    public function testMarkdownUsesTheSharedThemeInsideHistory(): void
    {
        $history = new HistoryView();
        $history->load([new AssistantMessage(
            "# Heading\n\n[Link](https://example.com) and `inline`\n\n> Quote\n\n- Item\n\n"
            . "```php\necho 'hello'; // comment\n```",
        )]);
        $host = new SymfonyTui(styleSheet: Theme::styleSheet(), terminal: new VirtualTerminal(80, 30));
        $host->add($history);

        $screen = implode("\n", (new Renderer(Theme::styleSheet()))->renderWidget($history, new RenderContext(80, 30)));
        self::assertStringContainsString((new Style(color: Theme::ACCENT, bold: true))->apply('Heading'), $screen);
        self::assertStringContainsString((new Style(color: Theme::INFO, underline: true))->apply('Link'), $screen);
        self::assertStringContainsString((new Style(color: Theme::ACCENT))->apply('inline'), $screen);
        self::assertStringContainsString((new Style(color: Theme::SECONDARY, italic: true))->apply('Quote'), $screen);
        self::assertStringContainsString((new Style(color: Theme::ACCENT))->apply('• '), $screen);
        self::assertStringContainsString((new Style(color: Theme::MUTED))->getAnsiRestore() . '// comment', $screen);
        self::assertStringNotContainsString("\033[36m", $screen);
    }

    public function testHistoryGrowsBeyondTheTerminalHeight(): void
    {
        $history = new HistoryView();
        $history->beginTurn('Question');
        $history->appendResponse("Line 1\nLine 2\nLine 3\nLine 4\nLine 5\nLine 6");

        $renderer = new Renderer();
        $context = new RenderContext(40, 3);
        $before = $renderer->renderWidget($history, $context);
        $history->appendResponse("\nLine 7\nLine 8");
        $after = $renderer->renderWidget($history, $context);
        self::assertGreaterThan(count($before), count($after));
        $text = AnsiUtils::stripAnsiCodes(implode("\n", $after));
        self::assertStringContainsString('❯ Question', $text);
        self::assertStringContainsString('Line 1', $text);
        self::assertStringContainsString('Line 8', $text);
        $history->load([new UserMessage('New history')]);
        $text = AnsiUtils::stripAnsiCodes(implode("\n", $renderer->renderWidget($history, $context)));
        self::assertStringContainsString('❯ New history', $text);
        self::assertStringNotContainsString('Line 1', $text);
    }

    public function testAgentMarkdownIsRenderedDuringStreamingAndWhenLoaded(): void
    {
        $markdown = "**Hello** with `code`\n\n- First item\n- Second item\n\n```php\necho 'Hi';\n```";
        $stored = new HistoryView();
        $stored->load([new UserMessage('**Question**'), new AssistantMessage($markdown)]);

        $live = new HistoryView();
        $live->beginTurn('**Question**');
        $live->appendResponse('**Hel');

        $renderer = new Renderer();
        $context = new RenderContext(40, 20);
        self::assertStringContainsString(
            'Hel',
            AnsiUtils::stripAnsiCodes(implode("\n", $renderer->renderWidget($live, $context))),
        );
        $live->appendResponse("lo** with `code`\n\n- First item\n- Second item\n\n```php\necho 'Hi';\n```");
        $screen = implode("\n", $renderer->renderWidget($live, $context));
        self::assertSame(implode("\n", $renderer->renderWidget($stored, $context)), $screen);
        $text = AnsiUtils::stripAnsiCodes($screen);
        self::assertStringContainsString('❯ **Question**', $text);
        self::assertStringContainsString('● Hello with code', $text);
        self::assertStringContainsString('  • First item', $text);
        self::assertStringContainsString("echo 'Hi';", $text);
        self::assertStringNotContainsString('**Hello**', $text);
        self::assertStringNotContainsString('```', $text);
        self::assertStringContainsString("\033[1m", $screen);
        foreach ($renderer->renderWidget($live, $context) as $line) {
            self::assertLessThanOrEqual(40, AnsiUtils::visibleWidth($line));
        }
    }

    public function testStoredAndStreamedMessagesHaveTheSamePresentation(): void
    {
        $toolCall = new ToolCall(id: 'call-1', name: 'weather', arguments: []);
        $toolResult = new ToolResult(id: 'call-1', name: 'weather', arguments: [], result: 'Sunny');
        $stored = new HistoryView();
        $stored->load([
            new UserMessage('Weather?'),
            new AssistantMessage('Checking', collection([$toolCall])),
            new ToolResultMessage(collection([$toolResult])),
            new AssistantMessage('It is sunny'),
        ]);
        $live = new HistoryView();
        $live->beginTurn('Weather?');
        $live->appendResponse('Checking');
        $live->showToolCall($toolCall);
        $live->showToolResult($toolResult);
        $live->appendResponse('It is sunny');
        $live->finishTurn();

        $renderer = new Renderer();
        $context = new RenderContext(60, 16);
        $screen = implode("\n", $renderer->renderWidget($stored, $context));
        self::assertSame($screen, implode("\n", $renderer->renderWidget($live, $context)));
        $text = AnsiUtils::stripAnsiCodes($screen);
        self::assertStringContainsString('❯ Weather?', $text);
        self::assertStringContainsString('● Checking', $text);
        self::assertStringNotContainsString('◆ weather', $text);
        self::assertSame(1, substr_count($text, '● weather'));
        self::assertStringContainsString('● It is sunny', $text);
    }

    public function testToolParametersAndCompletionUpdateTheSameCall(): void
    {
        $history = new HistoryView();
        $history->showToolCall(new ToolCall(
            id: 'first',
            name: 'read_file',
            arguments: ['path' => 'examples/basic.php', 'start_line' => 1],
        ));
        $history->showToolCall(new ToolCall(id: 'second', name: 'read_file', arguments: ['path' => 'README.md']));

        $renderer = new Renderer();
        $context = new RenderContext(80, 12);
        $pending = AnsiUtils::stripAnsiCodes(implode("\n", $renderer->renderWidget($history, $context)));
        self::assertStringContainsString('● read_file(path: "examples/basic.php", start_line: 1)', $pending);
        $history->showToolResult(new ToolResult(id: 'first', name: 'read_file', arguments: [], result: 'Long output hidden'));
        $completed = implode("\n", $renderer->renderWidget($history, $context));
        $text = AnsiUtils::stripAnsiCodes($completed);
        self::assertSame(2, substr_count($text, 'read_file('));
        self::assertStringContainsString('● read_file(path: "examples/basic.php", start_line: 1)', $text);
        self::assertStringContainsString('● read_file(path: "README.md")', $text);
        self::assertStringNotContainsString('Long output hidden', $text);
        self::assertStringContainsString('38;2;217;154;112', $completed);
        self::assertStringNotContainsString('38;2;145;185;154', $completed);
    }

    public function testNestedToolParametersAndErrorsAreReadable(): void
    {
        $call = new ToolCall(id: 'nested', name: 'search', arguments: [
            'query' => 'acme',
            'filters' => ['extensions' => ['php'], 'limit' => 10],
        ]);
        $history = new HistoryView();
        $history->showToolCall($call);

        $renderer = new Renderer();
        $context = new RenderContext(60, 20);
        $text = AnsiUtils::stripAnsiCodes(implode("\n", $renderer->renderWidget($history, $context)));
        self::assertStringContainsString('● search', $text);
        self::assertStringContainsString('  {', $text);
        self::assertStringContainsString('      "filters": {', $text);
        self::assertStringContainsString('          "limit": 10', $text);
        $history->showToolResult(new ToolResult(
            id: 'nested',
            name: 'search',
            arguments: [],
            result: 'File not found',
            failed: true,
        ));
        $screen = implode("\n", $renderer->renderWidget($history, $context));
        $text = AnsiUtils::stripAnsiCodes($screen);
        self::assertStringContainsString('! search', $text);
        self::assertStringContainsString('  └ File not found', $text);
        self::assertSame(1, substr_count($text, 'search'));
        self::assertStringContainsString('38;2;232;139;139', $screen);
        $history->load([]);
        $history->showToolResult(new ToolResult(
            id: 'nested',
            name: 'search',
            arguments: ['query' => 'new'],
            result: 'Done',
        ));
        $reloaded = AnsiUtils::stripAnsiCodes(implode("\n", $renderer->renderWidget($history, $context)));
        self::assertStringContainsString('● search(query: "new")', $reloaded);
        self::assertStringNotContainsString('filters', $reloaded);
    }

    public function testUserHighlightAndAgentContinuationLines(): void
    {
        $renderer = new Renderer();
        $context = new RenderContext(16, 8);
        $user = implode("\n", $renderer->renderWidget(new MessageView('Hello', MessageKind::User), $context));
        self::assertStringContainsString('48;2;56;56;56', $user);
        $agent = $renderer->renderWidget(new MessageView("First line\nSecond line", MessageKind::Agent), $context);
        self::assertSame('● First line', rtrim(AnsiUtils::stripAnsiCodes($agent[0])));
        self::assertSame('  Second line', rtrim(AnsiUtils::stripAnsiCodes($agent[1])));
        $wrapped = $renderer->renderWidget(new MessageView('One two three four five', MessageKind::Agent), $context);
        self::assertStringStartsWith('  ', AnsiUtils::stripAnsiCodes($wrapped[1]));
    }

    public function testSystemNoticesAndErrorsStayDistinctFromAgentResponses(): void
    {
        $history = new HistoryView();
        $history->load([new Message(MessageRole::ToolResult, 'Rules')]);
        $history->notify('Information');
        $history->notify('Failed', MessageKind::Error);

        $text = AnsiUtils::stripAnsiCodes(implode(
            "\n",
            (new Renderer())->renderWidget($history, new RenderContext(60, 10)),
        ));
        self::assertStringContainsString('· Rules', $text);
        self::assertStringContainsString('ℹ Information', $text);
        self::assertStringContainsString('! Failed', $text);
        self::assertStringNotContainsString('●', $text);
    }
}
