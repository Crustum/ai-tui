<?php
declare(strict_types=1);

namespace Crustum\AiTui\View;

use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\ToolResult;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use function implode;
use function is_array;
use function is_object;
use function json_encode;
use function mb_strlen;
use function mb_substr;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_PARTIAL_OUTPUT_ON_ERROR;
use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * History block describing one tool invocation and its outcome.
 */
final class ToolView extends ContainerWidget
{
    /**
     * Maximum number of argument characters shown in the preview.
     *
     * @var int
     */
    private const int MAX_ARGUMENT_CHARACTERS = 300;

    /**
     * Tool invocation described by this block.
     *
     * @var \Crustum\Ai\Responses\Data\ToolCall
     */
    private readonly ToolCall $tool;

    /**
     * Builds the block with the pending invocation preview.
     *
     * @param \Crustum\Ai\Responses\Data\ToolCall $tool Tool invocation data
     */
    public function __construct(ToolCall $tool)
    {
        $this->tool = $tool;
        $this->display();
    }

    /**
     * Refreshes the block with the finished tool result.
     *
     * @param \Crustum\Ai\Responses\Data\ToolResult $result Finished tool result
     * @return void
     */
    public function complete(ToolResult $result): void
    {
        $this->display($result->error());
    }

    /**
     * Rebuilds block lines for a pending or finished invocation.
     *
     * @param string|null $error Failure message shown below the preview
     * @return void
     */
    private function display(?string $error = null): void
    {
        $this->clear();
        $style = new Style(color: $error !== null ? Theme::ERROR : Theme::ACCENT);
        $symbol = $error !== null ? '! ' : '● ';
        $title = $style->apply($symbol . StringUtils::stripControlBytes($this->tool->name));
        $inputs = $this->tool->arguments;
        $nested = false;
        foreach ($inputs as $value) {
            if (is_array($value) || is_object($value)) {
                $nested = true;
                break;
            }
        }

        $parameterStyle = new Style(color: Theme::SECONDARY);
        if (!$nested && $inputs !== []) {
            $parameters = [];
            foreach ($inputs as $name => $value) {
                $parameters[] = StringUtils::stripControlBytes((string)$name) . ': ' . $this->encode($value);
            }

            $title .= $parameterStyle->apply('(' . $this->preview(implode(', ', $parameters)) . ')');
        }

        $this->add(new TextWidget($title));
        if ($nested) {
            $this->add((new TextWidget($this->preview($this->encode($inputs, pretty: true))))
                ->setStyle(new Style(padding: new Padding(0, 0, 0, 2), color: Theme::SECONDARY)));
        }

        if ($error !== null) {
            $this->add((new TextWidget('└ ' . StringUtils::stripControlBytes($error)))
                ->setStyle(new Style(padding: new Padding(0, 0, 0, 2), color: Theme::ERROR)));
        }
    }

    /**
     * Truncates an argument preview to the display limit.
     *
     * @param string $arguments Encoded argument preview
     * @return string
     */
    private function preview(string $arguments): string
    {
        if (mb_strlen($arguments, 'UTF-8') <= self::MAX_ARGUMENT_CHARACTERS) {
            return $arguments;
        }

        return mb_substr($arguments, 0, self::MAX_ARGUMENT_CHARACTERS - 1, 'UTF-8') . '…';
    }

    /**
     * Encodes a value for the argument preview.
     *
     * @param mixed $value Value to encode
     * @param bool $pretty Whether to format the output across lines
     * @return string
     */
    private function encode(mixed $value, bool $pretty = false): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | ($pretty ? JSON_PRETTY_PRINT : 0));

        return $encoded === false ? '[unavailable]' : StringUtils::stripControlBytes($encoded);
    }
}
