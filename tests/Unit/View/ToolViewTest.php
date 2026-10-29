<?php
declare(strict_types=1);

namespace Crustum\AiTui\Test\Unit\View;

use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\ToolResult;
use Crustum\AiTui\View\ToolView;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use function array_map;
use function implode;
use function mb_strlen;
use function rtrim;
use function str_repeat;

/**
 * Unit coverage for the tool invocation preview limits.
 */
final class ToolViewTest extends TestCase
{
    public function testLongWriteArgumentsHaveABoundedUnicodePreview(): void
    {
        $inputs = ['path' => 'example.txt', 'content' => str_repeat('è', 500) . 'HIDDEN_TAIL'];
        $tool = new ToolCall(id: 'write-1', name: 'write', arguments: $inputs);
        $view = new ToolView($tool);
        $text = $this->render($view);

        self::assertStringContainsString('● write(path: "example.txt", content: "è', $text);
        self::assertStringEndsWith('…)', $text);
        self::assertSame(300 + mb_strlen('● write()'), mb_strlen($text));
        self::assertStringNotContainsString('HIDDEN_TAIL', $text);
        self::assertSame($inputs, $tool->arguments);
    }

    public function testNestedArgumentsUseTheSamePreviewLimit(): void
    {
        $view = new ToolView(new ToolCall(
            id: 'write-2',
            name: 'write',
            arguments: ['files' => ['example.txt' => str_repeat('x', 500) . 'HIDDEN_TAIL']],
        ));
        $text = $this->render($view);

        self::assertStringContainsString('● write', $text);
        self::assertStringContainsString('"files": {', $text);
        self::assertStringEndsWith('…', $text);
        self::assertLessThan(340, mb_strlen($text));
        self::assertStringNotContainsString('HIDDEN_TAIL', $text);
    }

    public function testCallSummaryReplacesTheJsonDump(): void
    {
        $view = new ToolView(
            new ToolCall(id: 'ask-1', name: 'AskUser', arguments: [
                'question' => 'How to proceed?',
                'options' => [['label' => 'First', 'description' => 'Details here']],
            ]),
            "How to proceed?\n1) First — Details here",
        );
        $text = $this->render($view);

        self::assertStringContainsString('● AskUser', $text);
        self::assertStringContainsString('How to proceed?', $text);
        self::assertStringContainsString('1) First', $text);
        self::assertStringNotContainsString('"question"', $text);
        self::assertStringNotContainsString('"options"', $text);
    }

    public function testResultSummaryAppendsTheAnswer(): void
    {
        $view = new ToolView(
            new ToolCall(id: 'ask-1', name: 'AskUser', arguments: ['question' => 'How to proceed?']),
            'How to proceed?',
        );
        $view->complete(
            new ToolResult(id: 'ask-1', name: 'AskUser', arguments: [], result: 'First option'),
            'Chose: First option',
        );
        $text = $this->render($view);

        self::assertStringContainsString('How to proceed?', $text);
        self::assertStringContainsString('└ Chose: First option', $text);
    }

    /**
     * Renders the view without terminal escape sequences.
     *
     * @param \Crustum\AiTui\View\ToolView $view View under test
     * @return string
     */
    private function render(ToolView $view): string
    {
        $lines = (new Renderer())->renderWidget($view, new RenderContext(1000, 20));

        return implode("\n", array_map(
            static fn(string $line): string => rtrim(AnsiUtils::stripAnsiCodes($line)),
            $lines,
        ));
    }
}
