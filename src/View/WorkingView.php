<?php
declare(strict_types=1);

namespace Crustum\AiTui\View;

use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\LoaderWidget;
use function array_slice;
use function array_values;
use function hrtime;
use function intdiv;
use function max;

/**
 * Spinner with an elapsed-time label shown while the agent works.
 */
final class WorkingView extends LoaderWidget
{
    /**
     * Seconds accumulated while the indicator runs.
     *
     * @var float
     */
    private float $elapsed = 0.0;

    /**
     * Monotonic timestamp of the previous tick in seconds.
     *
     * @var float
     */
    private float $lastTick = 0.0;

    /**
     * Builds the indicator in its idle state.
     */
    public function __construct()
    {
        parent::__construct('Working (0s)');
    }

    /**
     * Starts the indicator and resets the elapsed time.
     *
     * @return void
     */
    public function start(): void
    {
        if ($this->isRunning()) {
            return;
        }

        $this->elapsed = 0.0;
        $this->lastTick = hrtime(true) / 1e9;
        $this->setMessage('Working (0s)');
        parent::start();
    }

    /**
     * Advances the spinner and refreshes the elapsed-time label.
     *
     * @param float|null $deltaTime Seconds to add instead of wall-clock time
     * @return bool
     */
    public function tick(?float $deltaTime = null): bool
    {
        if (!$this->isRunning()) {
            return false;
        }

        $now = hrtime(true) / 1e9;
        $this->elapsed += max(0.0, $deltaTime ?? $now - $this->lastTick);
        $this->lastTick = $now;
        $message = 'Working (' . $this->elapsedTime() . ')';
        $changed = $message !== $this->getMessage();
        $this->setMessage($message);

        return parent::tick($deltaTime) || $changed;
    }

    /**
     * Formats the accumulated time as seconds, minutes or hours.
     *
     * @return string
     */
    private function elapsedTime(): string
    {
        $seconds = (int)$this->elapsed;
        if ($seconds < 60) {
            return $seconds . 's';
        }

        $minutes = intdiv($seconds, 60);
        $remaining = $minutes % 60 . 'm ' . $seconds % 60 . 's';

        return $minutes < 60 ? $remaining : intdiv($minutes, 60) . 'h ' . $remaining;
    }

    /**
     * Renders the spinner line without the loader hint row.
     *
     * @param \Symfony\Component\Tui\Render\RenderContext $context Render context
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        return array_values(array_slice(parent::render($context), 1));
    }
}
