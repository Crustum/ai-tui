<?php
declare(strict_types=1);

namespace Crustum\AiTui\Test\Unit\View;

use Crustum\AiTui\View\WorkingView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use function implode;

/**
 * Unit coverage for the working indicator elapsed-time label.
 */
final class WorkingViewTest extends TestCase
{
    /**
     * Provides elapsed seconds with their expected label.
     *
     * @return iterable<string, array{float, string}>
     */
    public static function elapsedTimes(): iterable
    {
        yield 'under a minute' => [59.99, '59s'];
        yield 'one minute' => [60.0, '1m 0s'];
        yield 'minute and seconds' => [61.0, '1m 1s'];
        yield 'ten minutes' => [600.0, '10m 0s'];
        yield 'under an hour' => [3599.0, '59m 59s'];
        yield 'one hour' => [3600.0, '1h 0m 0s'];
        yield 'hour with minutes and seconds' => [3661.0, '1h 1m 1s'];
        yield 'multiple hours' => [90061.0, '25h 1m 1s'];
    }

    #[DataProvider('elapsedTimes')]
    public function testElapsedTimeUsesMinutesAndHours(float $seconds, string $expected): void
    {
        $working = new WorkingView();
        $working->start();
        $working->tick($seconds);
        self::assertSame('Working (' . $expected . ')', $working->getMessage());
        self::assertStringContainsString(
            'Working (' . $expected . ')',
            AnsiUtils::stripAnsiCodes(implode("\n", $working->render(new RenderContext(50, 1)))),
        );
        $working->stop();
        $working->start();
        self::assertSame('Working (0s)', $working->getMessage());
        $working->stop();
    }

    public function testElapsedSecondsAdvanceAndResetForEachTurn(): void
    {
        $working = new WorkingView();
        $context = new RenderContext(50, 1);
        $working->start();
        self::assertStringContainsString('Working (0s)', implode("\n", $working->render($context)));
        $working->tick(0.99);
        self::assertSame('Working (0s)', $working->getMessage());
        $working->tick(0.02);
        self::assertSame('Working (1s)', $working->getMessage());
        $working->tick(10.0);
        self::assertStringContainsString(
            'Working (11s)',
            AnsiUtils::stripAnsiCodes(implode("\n", $working->render($context))),
        );
        self::assertStringNotContainsString('esc', $working->getMessage());
        $working->start();
        self::assertSame('Working (11s)', $working->getMessage());
        $working->stop();
        self::assertFalse($working->tick(5.0));
        self::assertSame([], $working->render($context));
        $working->start();
        self::assertSame('Working (0s)', $working->getMessage());
        $working->tick(1.1);
        self::assertSame('Working (1s)', $working->getMessage());
        $working->stop();
    }
}
