<?php
declare(strict_types=1);

namespace Crustum\AiTui\Test\Unit\Terminal;

use Crustum\AiTui\Terminal\WindowsConsoleTerminal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use const PHP_OS_FAMILY;

/**
 * Unit coverage for the Windows console input translation.
 */
final class WindowsConsoleTerminalTest extends TestCase
{
    /**
     * Lists key records with their expected sequences.
     *
     * @return array<string, array{int, int, bool, string|null}> Translation cases.
     */
    public static function keys(): array
    {
        return [
            'letter' => [0x41, 0x0061, true, 'a'],
            'digit' => [0x31, 0x0031, true, '1'],
            'enter' => [0x0D, 0x0D, true, "\r"],
            'backspace' => [0x08, 0x08, true, "\x7f"],
            'tab' => [0x09, 0x09, true, "\t"],
            'escape' => [0x1B, 0x1B, true, "\x1b"],
            'ctrl+c' => [0x43, 0x03, true, "\x03"],
            'ctrl+a' => [0x41, 0x01, true, "\x01"],
            'cyrillic' => [0x0424, 0x0424, true, 'Ф'],
            'astral character' => [0x00, 0x1F600, true, '😀'],
            'up' => [0x26, 0x00, true, "\x1b[A"],
            'down' => [0x28, 0x00, true, "\x1b[B"],
            'left' => [0x25, 0x00, true, "\x1b[D"],
            'right' => [0x27, 0x00, true, "\x1b[C"],
            'home' => [0x24, 0x00, true, "\x1b[H"],
            'end' => [0x23, 0x00, true, "\x1b[F"],
            'insert' => [0x2D, 0x00, true, "\x1b[2~"],
            'delete' => [0x2E, 0x00, true, "\x1b[3~"],
            'page up' => [0x21, 0x00, true, "\x1b[5~"],
            'page down' => [0x22, 0x00, true, "\x1b[6~"],
            'f1' => [0x70, 0x00, true, "\x1bOP"],
            'f5' => [0x74, 0x00, true, "\x1b[15~"],
            'f12' => [0x7B, 0x00, true, "\x1b[24~"],
            'key release produces nothing' => [0x41, 0x0061, false, null],
            'unknown special key produces nothing' => [0xFF, 0x00, true, null],
        ];
    }

    /**
     * Translates console key records into parser sequences.
     *
     * @param int $virtualKeyCode Win32 virtual key code.
     * @param int $unicodeChar UTF-16 code unit.
     * @param bool $keyDown True for key press records.
     * @param string|null $expected Expected byte sequence.
     * @return void
     */
    #[DataProvider('keys')]
    public function testTranslateKey(int $virtualKeyCode, int $unicodeChar, bool $keyDown, ?string $expected): void
    {
        self::assertSame($expected, WindowsConsoleTerminal::translateKey($virtualKeyCode, $unicodeChar, $keyDown));
    }

    public function testFactoryBuildsPlatformTerminal(): void
    {
        $terminal = WindowsConsoleTerminal::create();

        self::assertInstanceOf(TerminalInterface::class, $terminal);

        if (PHP_OS_FAMILY === 'Windows') {
            self::assertInstanceOf(WindowsConsoleTerminal::class, $terminal);
        } else {
            self::assertInstanceOf(Terminal::class, $terminal);
        }
    }

    public function testConsoleTerminalReportsRealConsole(): void
    {
        $terminal = new WindowsConsoleTerminal();

        self::assertFalse($terminal->isVirtual());
        self::assertFalse($terminal->isKittyProtocolActive());
        self::assertGreaterThan(0, $terminal->getColumns());
        self::assertGreaterThan(0, $terminal->getRows());
    }
}
