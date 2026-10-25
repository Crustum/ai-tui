<?php
declare(strict_types=1);

namespace Crustum\AiTui\Terminal;

use Closure;
use FFI;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Throwable;
use function chr;
use function extension_loaded;
use function function_exists;
use function min;
use const PHP_OS_FAMILY;
use const STDOUT;

/**
 * Console terminal with per-key input on Windows.
 *
 * The default terminal enables raw mode through `stty`, which does not exist
 * on Windows, leaving the console line-buffered: keystrokes reach the loop
 * only after Enter, and the blocking read freezes rendering meanwhile. This
 * implementation drives the Win32 console directly through FFI instead:
 * console mode is switched to raw-ish (no echo, no line buffering, window
 * input enabled), and a loop poll translates console input records into the
 * byte sequences the key parser expects. Output and dimensions delegate to
 * the default terminal. When FFI is unavailable or standard input is not a
 * console, construction still succeeds and starting falls back to the
 * default terminal behavior.
 */
final class WindowsConsoleTerminal implements TerminalInterface
{
    /**
     * Win32 standard input handle identifier.
     */
    private const int STD_INPUT_HANDLE = -10;

    /**
     * Console input record type for key events.
     */
    private const int EVENT_KEY = 0x0001;

    /**
     * Console input record type for buffer resize events.
     */
    private const int EVENT_RESIZE = 0x0004;

    /**
     * Console mode flag for input echo.
     */
    private const int MODE_ECHO = 0x0004;

    /**
     * Console mode flag for line buffered input.
     */
    private const int MODE_LINE = 0x0002;

    /**
     * Console mode flag for processed control input.
     */
    private const int MODE_PROCESSED = 0x0001;

    /**
     * Console mode flag delivering resize events.
     */
    private const int MODE_WINDOW_INPUT = 0x0008;

    /**
     * Seconds between console input polls.
     */
    private const float POLL_INTERVAL = 0.02;

    /**
     * Input records consumed per poll.
     */
    private const int READ_BATCH = 128;

    /**
     * Virtual key code for the up arrow.
     */
    private const int VK_UP = 0x26;

    /**
     * Virtual key code for the down arrow.
     */
    private const int VK_DOWN = 0x28;

    /**
     * Virtual key code for the left arrow.
     */
    private const int VK_LEFT = 0x25;

    /**
     * Virtual key code for the right arrow.
     */
    private const int VK_RIGHT = 0x27;

    /**
     * Virtual key code for the home key.
     */
    private const int VK_HOME = 0x24;

    /**
     * Virtual key code for the end key.
     */
    private const int VK_END = 0x23;

    /**
     * Virtual key code for the insert key.
     */
    private const int VK_INSERT = 0x2D;

    /**
     * Virtual key code for the delete key.
     */
    private const int VK_DELETE = 0x2E;

    /**
     * Virtual key code for the page up key.
     */
    private const int VK_PAGE_UP = 0x21;

    /**
     * Virtual key code for the page down key.
     */
    private const int VK_PAGE_DOWN = 0x22;

    /**
     * Virtual key code for the first function key.
     */
    private const int VK_F1 = 0x70;

    /**
     * Byte sequences emitted for special keys without a character.
     *
     * @var array<int, string>
     */
    private const array SPECIAL_SEQUENCES = [
        self::VK_UP => "\x1b[A",
        self::VK_DOWN => "\x1b[B",
        self::VK_LEFT => "\x1b[D",
        self::VK_RIGHT => "\x1b[C",
        self::VK_HOME => "\x1b[H",
        self::VK_END => "\x1b[F",
        self::VK_INSERT => "\x1b[2~",
        self::VK_DELETE => "\x1b[3~",
        self::VK_PAGE_UP => "\x1b[5~",
        self::VK_PAGE_DOWN => "\x1b[6~",
        self::VK_F1 => "\x1bOP",
        self::VK_F1 + 1 => "\x1bOQ",
        self::VK_F1 + 2 => "\x1bOR",
        self::VK_F1 + 3 => "\x1bOS",
        self::VK_F1 + 4 => "\x1b[15~",
        self::VK_F1 + 5 => "\x1b[17~",
        self::VK_F1 + 6 => "\x1b[18~",
        self::VK_F1 + 7 => "\x1b[19~",
        self::VK_F1 + 8 => "\x1b[20~",
        self::VK_F1 + 9 => "\x1b[21~",
        self::VK_F1 + 10 => "\x1b[23~",
        self::VK_F1 + 11 => "\x1b[24~",
    ];

    /**
     * Kernel32 console functions used for raw-ish input.
     */
    private const string CDEF = '
        typedef short SHORT;
        typedef unsigned short WORD;
        typedef unsigned long DWORD;
        typedef int BOOL;
        typedef void* HANDLE;
        typedef DWORD* LPDWORD;
        typedef struct _KEY_EVENT_RECORD {
            BOOL bKeyDown;
            WORD wRepeatCount;
            WORD wVirtualKeyCode;
            WORD wVirtualScanCode;
            WORD UnicodeChar;
            DWORD dwControlKeyState;
        } KEY_EVENT_RECORD;
        typedef struct _INPUT_RECORD {
            WORD EventType;
            WORD Padding;
            KEY_EVENT_RECORD KeyEvent;
        } INPUT_RECORD;
        HANDLE GetStdHandle(DWORD nStdHandle);
        BOOL GetConsoleMode(HANDLE hConsoleHandle, LPDWORD lpMode);
        BOOL SetConsoleMode(HANDLE hConsoleHandle, DWORD dwMode);
        BOOL PeekConsoleInputW(HANDLE hConsoleInput, INPUT_RECORD *lpBuffer, DWORD nLength, LPDWORD lpNumberOfEventsRead);
        BOOL ReadConsoleInputW(HANDLE hConsoleInput, INPUT_RECORD *lpBuffer, DWORD nLength, LPDWORD lpNumberOfEventsRead);
    ';

    /**
     * Default terminal handling output and dimensions.
     *
     * @var \Symfony\Component\Tui\Terminal\Terminal
     */
    private readonly Terminal $output;

    /**
     * Kernel32 binding, present only while console input is active.
     *
     * @var \FFI|null
     */
    private ?FFI $console = null;

    /**
     * Standard input console handle.
     *
     * @var mixed
     */
    private mixed $inputHandle = null;

    /**
     * Reusable input record batch.
     *
     * @var mixed
     */
    private mixed $records = null;

    /**
     * Reusable event count cell.
     *
     * @var mixed
     */
    private mixed $available = null;

    /**
     * Console mode to restore on stop.
     *
     * @var int
     */
    private int $originalMode = 0;

    /**
     * Whether the console is owned through FFI.
     *
     * @var bool
     */
    private bool $consoleOwned = false;

    /**
     * Whether input lifecycle delegates to the default terminal.
     *
     * @var bool
     */
    private bool $delegated = false;

    /**
     * Whether the terminal runs.
     *
     * @var bool
     */
    private bool $started = false;

    /**
     * Input poll callback identifier.
     *
     * @var string|null
     */
    private ?string $pollCallbackId = null;

    /**
     * Pending UTF-16 high surrogate from a split astral character.
     *
     * @var int|null
     */
    private ?int $pendingHighSurrogate = null;

    /**
     * Input receiver.
     *
     * @var (\Closure(string): void)|null
     */
    private ?Closure $onInput = null;

    /**
     * Resize receiver.
     *
     * @var (\Closure(): void)|null
     */
    private ?Closure $onResize = null;

    /**
     * Builds the terminal for the current platform.
     */
    public function __construct()
    {
        $this->output = new Terminal();
    }

    /**
     * Creates the terminal fitting the current platform.
     *
     * @return \Symfony\Component\Tui\Terminal\TerminalInterface Platform terminal.
     */
    public static function create(): TerminalInterface
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return new Terminal();
        }

        return new self();
    }

    /**
     * Translates one console key record into an input sequence.
     *
     * @param int $virtualKeyCode Win32 virtual key code.
     * @param int $unicodeChar UTF-16 code unit, zero for special keys.
     * @param bool $keyDown True for key press records.
     * @return string|null Byte sequence, or null when the record produces no input.
     */
    public static function translateKey(int $virtualKeyCode, int $unicodeChar, bool $keyDown): ?string
    {
        if (!$keyDown) {
            return null;
        }

        if ($unicodeChar !== 0) {
            return match ($unicodeChar) {
                0x03 => "\x03",
                0x08 => "\x7f",
                0x09 => "\t",
                0x0D => "\r",
                0x1B => "\x1b",
                default => $unicodeChar < 0x20 ? chr($unicodeChar) : self::encodeUtf8($unicodeChar),
            };
        }

        return self::SPECIAL_SEQUENCES[$virtualKeyCode] ?? null;
    }

    /**
     * Encodes one code point as UTF-8 bytes.
     *
     * @param int $codepoint Unicode code point.
     * @return string|null UTF-8 bytes, or null for surrogates and out-of-range values.
     */
    private static function encodeUtf8(int $codepoint): ?string
    {
        if ($codepoint < 0x80) {
            return chr($codepoint);
        }

        if ($codepoint < 0x800) {
            return chr(0xC0 | ($codepoint >> 6)) . chr(0x80 | ($codepoint & 0x3F));
        }

        if ($codepoint < 0x10000) {
            if ($codepoint >= 0xD800 && $codepoint <= 0xDFFF) {
                return null;
            }

            return chr(0xE0 | ($codepoint >> 12))
                . chr(0x80 | (($codepoint >> 6) & 0x3F))
                . chr(0x80 | ($codepoint & 0x3F));
        }

        if ($codepoint <= 0x10FFFF) {
            return chr(0xF0 | ($codepoint >> 18))
                . chr(0x80 | (($codepoint >> 12) & 0x3F))
                . chr(0x80 | (($codepoint >> 6) & 0x3F))
                . chr(0x80 | ($codepoint & 0x3F));
        }

        return null;
    }

    /**
     * Starts the terminal with input and resize handlers.
     *
     * @param callable(string): void $onInput Called when input is received.
     * @param callable(): void $onResize Called when the terminal is resized.
     * @param callable(): void $onKittyProtocolActivated Called when Kitty keyboard protocol is detected.
     * @return void
     */
    public function start(callable $onInput, callable $onResize, callable $onKittyProtocolActivated): void
    {
        if ($this->started) {
            return;
        }

        $this->onInput = $onInput(...);
        $this->onResize = $onResize(...);
        $this->started = true;

        if ($this->enableConsoleInput()) {
            return;
        }

        $this->delegated = true;
        $this->output->start($onInput, $onResize, $onKittyProtocolActivated);
    }

    /**
     * Stops the terminal and restores the console mode.
     *
     * @return void
     */
    public function stop(): void
    {
        if (!$this->started) {
            return;
        }

        $this->started = false;

        if ($this->pollCallbackId !== null) {
            EventLoop::cancel($this->pollCallbackId);
            $this->pollCallbackId = null;
        }

        if ($this->consoleOwned && $this->inputHandle !== null) {
            try {
                $this->kernel('SetConsoleMode', $this->inputHandle, $this->originalMode);
            } catch (Throwable) {
            }
        }

        if ($this->delegated) {
            $this->output->stop();
            $this->delegated = false;
        }

        $this->console = null;
        $this->inputHandle = null;
        $this->records = null;
        $this->available = null;
        $this->consoleOwned = false;
        $this->pendingHighSurrogate = null;
        $this->onInput = null;
        $this->onResize = null;
    }

    /**
     * Writes data to the terminal output.
     *
     * @param string $data Output bytes.
     * @return void
     */
    public function write(string $data): void
    {
        $this->output->write($data);
    }

    /**
     * Returns the terminal width in columns.
     *
     * @return int Terminal width in columns.
     */
    public function getColumns(): int
    {
        return $this->output->getColumns();
    }

    /**
     * Returns the terminal height in rows.
     *
     * @return int Terminal height in rows.
     */
    public function getRows(): int
    {
        return $this->output->getRows();
    }

    /**
     * Reports whether the Kitty keyboard protocol is active.
     *
     * @return bool Always false, the console has no Kitty support.
     */
    public function isKittyProtocolActive(): bool
    {
        return $this->output->isKittyProtocolActive();
    }

    /**
     * Moves the cursor up or down by the given line count.
     *
     * @param int $lines Lines to move, negative moves up.
     * @return void
     */
    public function moveBy(int $lines): void
    {
        $this->output->moveBy($lines);
    }

    /**
     * Hides the terminal cursor.
     *
     * @return void
     */
    public function hideCursor(): void
    {
        $this->output->hideCursor();
    }

    /**
     * Shows the terminal cursor.
     *
     * @return void
     */
    public function showCursor(): void
    {
        $this->output->showCursor();
    }

    /**
     * Clears the current line.
     *
     * @return void
     */
    public function clearLine(): void
    {
        $this->output->clearLine();
    }

    /**
     * Clears from the cursor to the end of the screen.
     *
     * @return void
     */
    public function clearFromCursor(): void
    {
        $this->output->clearFromCursor();
    }

    /**
     * Clears the entire screen and moves the cursor home.
     *
     * @return void
     */
    public function clearScreen(): void
    {
        $this->output->clearScreen();
    }

    /**
     * Sets the terminal window title.
     *
     * @param string $title Window title.
     * @return void
     */
    public function setTitle(string $title): void
    {
        $this->output->setTitle($title);
    }

    /**
     * Rings the terminal bell.
     *
     * @return void
     */
    public function bell(): void
    {
        $this->output->bell();
    }

    /**
     * Reports whether this is a virtual terminal.
     *
     * @return bool Always false, this terminal drives a real console.
     */
    public function isVirtual(): bool
    {
        return false;
    }

    /**
     * Switches the console to raw-ish mode and starts the input poll.
     *
     * @return bool True when the console is owned through FFI.
     */
    private function enableConsoleInput(): bool
    {
        if (PHP_OS_FAMILY !== 'Windows' || !extension_loaded('ffi')) {
            return false;
        }

        try {
            $this->console = FFI::cdef(self::CDEF, 'kernel32.dll');
        } catch (Throwable) {
            $this->console = null;

            return false;
        }

        $handle = $this->kernel('GetStdHandle', self::STD_INPUT_HANDLE);
        $mode = $this->console->new('DWORD[1]');

        if (!$this->kernel('GetConsoleMode', $handle, FFI::addr($mode[0]))) {
            $this->console = null;

            return false;
        }

        $raw = ((int)$mode[0] | self::MODE_WINDOW_INPUT)
            & ~(self::MODE_ECHO | self::MODE_LINE | self::MODE_PROCESSED);

        if (!$this->kernel('SetConsoleMode', $handle, $raw)) {
            $this->console = null;

            return false;
        }

        if (function_exists('sapi_windows_vt100_support')) {
            sapi_windows_vt100_support(STDOUT, true);
        }

        $this->inputHandle = $handle;
        $this->records = $this->console->new('INPUT_RECORD[' . self::READ_BATCH . ']');
        $this->available = $this->console->new('DWORD[1]');
        $this->originalMode = (int)$mode[0];
        $this->consoleOwned = true;
        $this->pollCallbackId = EventLoop::repeat(self::POLL_INTERVAL, $this->pump(...));

        return true;
    }

    /**
     * Invokes one kernel32 console function.
     *
     * C functions arrive through FFI as magic methods that static analysis
     * cannot resolve, so every call goes through this single choke point
     * using the documented dynamic calling convention.
     *
     * @param string $function Kernel32 function name.
     * @param mixed ...$args Function arguments.
     * @return mixed Function result.
     */
    private function kernel(string $function, mixed ...$args): mixed
    {
        if (!$this->console instanceof FFI) {
            return false;
        }

        return $this->console->$function(...$args);
    }

    /**
     * Reads pending console records without blocking and forwards input.
     *
     * @return void
     */
    private function pump(): void
    {
        if (!$this->started || !$this->consoleOwned || !$this->console instanceof FFI || $this->inputHandle === null) {
            return;
        }

        if (!$this->kernel('PeekConsoleInputW', $this->inputHandle, $this->records, self::READ_BATCH, FFI::addr($this->available[0]))) {
            return;
        }

        $count = min((int)$this->available[0], self::READ_BATCH);

        if ($count <= 0) {
            return;
        }

        if (!$this->kernel('ReadConsoleInputW', $this->inputHandle, $this->records, $count, FFI::addr($this->available[0]))) {
            return;
        }

        $text = '';
        $resized = false;

        for ($index = 0; $index < $count; $index++) {
            $record = $this->records[$index];
            $type = (int)$record->EventType;

            if ($type === self::EVENT_RESIZE) {
                $resized = true;

                continue;
            }

            if ($type !== self::EVENT_KEY) {
                continue;
            }

            $key = $record->KeyEvent;
            $sequence = $this->translateRecord((int)$key->wVirtualKeyCode, (int)$key->UnicodeChar, (bool)$key->bKeyDown);

            if ($sequence !== null && $sequence !== '') {
                $text .= $sequence;
            }
        }

        if ($resized) {
            ($this->onResize)();
        }

        if ($text !== '') {
            ($this->onInput)($text);
        }
    }

    /**
     * Translates one console key record, combining split surrogate pairs.
     *
     * @param int $virtualKeyCode Win32 virtual key code.
     * @param int $unicodeChar UTF-16 code unit, zero for special keys.
     * @param bool $keyDown True for key press records.
     * @return string|null Byte sequence, or null when the record produces no input.
     */
    private function translateRecord(int $virtualKeyCode, int $unicodeChar, bool $keyDown): ?string
    {
        if (!$keyDown) {
            return null;
        }

        if ($unicodeChar >= 0xD800 && $unicodeChar <= 0xDBFF) {
            $this->pendingHighSurrogate = $unicodeChar;

            return null;
        }

        if ($unicodeChar >= 0xDC00 && $unicodeChar <= 0xDFFF && $this->pendingHighSurrogate !== null) {
            $high = $this->pendingHighSurrogate;
            $this->pendingHighSurrogate = null;

            return self::encodeUtf8(0x10000 + ($high - 0xD800 << 10) + $unicodeChar - 0xDC00);
        }

        $this->pendingHighSurrogate = null;

        return self::translateKey($virtualKeyCode, $unicodeChar, true);
    }
}
