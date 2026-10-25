<?php
declare(strict_types=1);

namespace Crustum\AiTui\View;

use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use function trim;

/**
 * Chat header with an optional banner, title and description.
 */
final class HeaderView extends ContainerWidget
{
    /**
     * Banner widget rendered above the title.
     *
     * @var \Symfony\Component\Tui\Widget\TextWidget
     */
    private readonly TextWidget $figlet;

    /**
     * Chat title widget.
     *
     * @var \Symfony\Component\Tui\Widget\TextWidget
     */
    private readonly TextWidget $title;

    /**
     * Chat description widget.
     *
     * @var \Symfony\Component\Tui\Widget\TextWidget
     */
    private readonly TextWidget $description;

    /**
     * Builds the header with default title and description widgets.
     */
    public function __construct()
    {
        $this->figlet = new TextWidget();
        $this->title = (new TextWidget('✳ AiTui'))->setStyle(new Style(color: Theme::ACCENT, bold: true));
        $this->description = (new TextWidget('A conversation with your agent'))
            ->setStyle(new Style(color: Theme::MUTED));
        $this->add($this->figlet)->add($this->title)->add($this->description);
    }

    /**
     * Replaces the banner text and its vertical spacing.
     *
     * @param string $text Banner text
     * @param string $font Banner font name
     * @return void
     */
    public function setFiglet(string $text, string $font = 'standard'): void
    {
        $text = StringUtils::stripControlBytes($text);
        $this->figlet->setText($text);
        $this->figlet->setStyle(
            (new Style(color: Theme::ACCENT, font: $font))->withPadding([0, 0, trim($text) === '' ? 0 : 1, 0]),
        );
    }

    /**
     * Replaces the chat title text.
     *
     * @param string $title Chat title
     * @return void
     */
    public function setTitle(string $title): void
    {
        $this->title->setText('✳ ' . StringUtils::stripControlBytes($title));
    }

    /**
     * Replaces the chat description text.
     *
     * @param string $description Chat description
     * @return void
     */
    public function setDescription(string $description): void
    {
        $this->description->setText(StringUtils::stripControlBytes($description));
    }
}
