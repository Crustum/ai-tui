<?php
declare(strict_types=1);

namespace Crustum\AiTui\View;

use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Tempest\Highlight\TerminalTheme;
use Tempest\Highlight\Themes\EscapesTerminalTheme;
use Tempest\Highlight\Tokens\TokenType;
use Tempest\Highlight\Tokens\TokenTypeEnum;

/**
 * Shared terminal palette, widget stylesheet and code highlight theme.
 */
final class Theme implements TerminalTheme
{
    use EscapesTerminalTheme;

    /**
     * Primary text color.
     */
    public const string TEXT = '#eeeeee';

    /**
     * Secondary text color.
     */
    public const string SECONDARY = '#bbbbbb';

    /**
     * Muted text color.
     */
    public const string MUTED = '#999999';

    /**
     * Border color.
     */
    public const string BORDER = '#888888';

    /**
     * Prompt color.
     */
    public const string PROMPT = '#ffffff';

    /**
     * Background color of user messages.
     */
    public const string USER_BACKGROUND = '#383838';

    /**
     * Accent color for headings, selections and spinners.
     */
    public const string ACCENT = '#d99a70';

    /**
     * Success color for tool results and types.
     */
    public const string SUCCESS = '#91b99a';

    /**
     * Informational color for links and notices.
     */
    public const string INFO = '#9caee0';

    /**
     * Error color for failures.
     */
    public const string ERROR = '#e88b8b';

    /**
     * Builds the widget stylesheet shared by every chat view.
     *
     * @return \Symfony\Component\Tui\Style\StyleSheet
     */
    public static function styleSheet(): StyleSheet
    {
        return new StyleSheet([
            ComposerInputView::class . '::frame' => new Style(color: self::BORDER),
            CommandMenuView::class . '::selected' => new Style(color: self::ACCENT, bold: true),
            CommandMenuView::class . '::name' => new Style(color: self::TEXT),
            CommandMenuView::class . '::description' => new Style(color: self::MUTED),
            SelectionView::class . '::separator' => new Style(color: self::ACCENT),
            SelectionView::class . '::border' => new Style(color: self::BORDER),
            SelectionView::class . '::search' => new Style(color: self::TEXT),
            SelectionView::class . '::title' => new Style(color: self::ACCENT, bold: true),
            SelectionView::class . '::selected' => new Style(color: self::ACCENT, bold: true),
            SelectionView::class . '::label' => new Style(color: self::TEXT),
            SelectionView::class . '::description' => new Style(color: self::MUTED),
            WorkingView::class . '::spinner' => new Style(color: self::ACCENT),
            WorkingView::class . '::message' => new Style(color: self::ACCENT),
            MarkdownWidget::class . '::heading' => new Style(color: self::ACCENT, bold: true),
            MarkdownWidget::class . '::link' => new Style(color: self::INFO, underline: true),
            MarkdownWidget::class . '::link-url' => new Style(color: self::MUTED),
            MarkdownWidget::class . '::code' => new Style(color: self::ACCENT),
            MarkdownWidget::class . '::code-block-border' => new Style(color: self::BORDER),
            MarkdownWidget::class . '::quote' => new Style(color: self::SECONDARY, italic: true),
            MarkdownWidget::class . '::quote-border' => new Style(color: self::BORDER),
            MarkdownWidget::class . '::hr' => new Style(color: self::BORDER),
            MarkdownWidget::class . '::list-bullet' => new Style(color: self::ACCENT),
            MarkdownWidget::class . '::bold' => new Style(bold: true),
            MarkdownWidget::class . '::italic' => new Style(italic: true),
            MarkdownWidget::class . '::strikethrough' => new Style(strikethrough: true),
        ]);
    }

    /**
     * Returns the opening escape sequence for a highlighted token.
     *
     * @param \Tempest\Highlight\Tokens\TokenType $tokenType Highlighted token type
     * @return string
     */
    public function before(TokenType $tokenType): string
    {
        $color = match ($tokenType) {
            TokenTypeEnum::KEYWORD, TokenTypeEnum::VALUE => self::ACCENT,
            TokenTypeEnum::TYPE => self::SUCCESS,
            TokenTypeEnum::PROPERTY,
            TokenTypeEnum::VARIABLE,
            TokenTypeEnum::GENERIC,
            TokenTypeEnum::ATTRIBUTE => self::INFO,
            TokenTypeEnum::COMMENT => self::MUTED,
            default => self::TEXT,
        };

        return (new Style(color: $color))->getAnsiRestore();
    }

    /**
     * Returns the closing escape sequence for a highlighted token.
     *
     * @param \Tempest\Highlight\Tokens\TokenType $tokenType Highlighted token type
     * @return string
     */
    public function after(TokenType $tokenType): string
    {
        return (new Style(color: self::TEXT))->getAnsiRestore();
    }
}
