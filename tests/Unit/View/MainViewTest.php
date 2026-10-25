<?php
declare(strict_types=1);

namespace Crustum\AiTui\Test\Unit\View;

use Crustum\AiTui\View\MainView;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui as SymfonyTui;

/**
 * Unit coverage for the main view turn state and render requests.
 */
final class MainViewTest extends TestCase
{
    public function testBusyFlagTracksTurn(): void
    {
        $view = new MainView();

        self::assertFalse($view->isBusy());
        (new ReflectionProperty(MainView::class, 'busy'))->setValue($view, true);
        self::assertTrue($view->isBusy());
        $view->finishTurn();
        self::assertFalse($view->isBusy());
    }

    public function testQueueTaskBuffersTrimmedTextForNextTurn(): void
    {
        $view = new MainView();
        $view->onInput(static function (): void {
        });
        (new ReflectionProperty(MainView::class, 'busy'))->setValue($view, true);

        $view->queueTask('  do the thing  ', '  /task  ');

        self::assertSame(
            ['do the thing'],
            (new ReflectionProperty(MainView::class, 'pending'))->getValue($view),
        );
        self::assertSame(
            [true],
            (new ReflectionProperty(MainView::class, 'pendingIsTask'))->getValue($view),
        );
        self::assertSame(
            ['/task'],
            (new ReflectionProperty(MainView::class, 'pendingOrigin'))->getValue($view),
        );
    }

    public function testQueueTaskIgnoresBlankTextWithoutListenerOrAfterStop(): void
    {
        $view = new MainView();
        $view->onInput(static function (): void {
        });
        (new ReflectionProperty(MainView::class, 'busy'))->setValue($view, true);

        $view->queueTask('   ');

        $idle = new MainView();
        $idle->queueTask('task');

        $view->stop();
        $view->queueTask('task');

        $pending = new ReflectionProperty(MainView::class, 'pending');
        self::assertSame([], $pending->getValue($view));
        self::assertSame([], $pending->getValue($idle));
    }

    public function testStreamedTextRequestsRenderPerChunk(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $host = new SymfonyTui(terminal: $terminal);
        $view = new MainView();
        $host->add($view);
        $host->tick();

        $terminal->clearOutput();

        (new ReflectionProperty(MainView::class, 'busy'))->setValue($view, true);
        $view->appendResponse('Hello.');
        $host->tick();

        self::assertStringContainsString('Hello.', $terminal->consumeOutput());
    }
}
