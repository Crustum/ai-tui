<?php
declare(strict_types=1);

namespace Crustum\AiTui;

use Crustum\Ai\Streaming\Event\Error as StreamError;
use Crustum\Ai\Streaming\Event\ReasoningDelta;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\ToolCall as StreamToolCall;
use Crustum\Ai\Streaming\Event\ToolResult as StreamToolResult;
use Crustum\AiInteraction\InteractionCommand\CommandInput;
use Crustum\AiInteraction\InteractionCommand\TurnConversation;
use Crustum\AiInteraction\InteractionCommand\TurnNotice;
use Crustum\AiInteraction\InteractionCommand\TurnResult;
use Crustum\AiInteraction\InteractionCommand\TurnRunner as InteractionTurnRunner;
use Crustum\AiInteraction\InteractionCommand\TurnSelection;
use Crustum\AiInteraction\InteractionCommand\TurnStopped;
use Crustum\AiInteraction\InteractionCommand\TurnTask;
use Crustum\AiTui\View\MainView;
use Crustum\AiTui\View\MessageKind;
use Generator;
use Revolt\EventLoop;
use Symfony\Component\Tui\Tui as SymfonyTui;
use Throwable;

/**
 * Maps turn items onto the chat screen inside the terminal loop.
 *
 * Driver stream events pass through untouched; interaction outcomes drive
 * notices, selection, task queueing and history sync. The terminal turn state
 * arrives via the generator return after the stream drains.
 */
final class TurnRunner
{
    /**
     * Interaction turn primitive.
     *
     * @var \Crustum\AiInteraction\InteractionCommand\TurnRunner
     */
    private InteractionTurnRunner $turns;

    /**
     * Chat screen receiving mapped items.
     *
     * @var \Crustum\AiTui\View\MainView
     */
    private MainView $mainView;

    /**
     * Terminal host owning the render loop.
     *
     * @var \Symfony\Component\Tui\Tui
     */
    private SymfonyTui $tui;

    /**
     * Creates a view loop over interaction turns.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\TurnRunner $turns Interaction turn primitive.
     * @param \Crustum\AiTui\View\MainView $mainView Chat screen receiving mapped items.
     * @param \Symfony\Component\Tui\Tui $tui Terminal host owning the render loop.
     */
    public function __construct(
        InteractionTurnRunner $turns,
        MainView $mainView,
        SymfonyTui $tui,
    ) {
        $this->turns = $turns;
        $this->mainView = $mainView;
        $this->tui = $tui;
    }

    /**
     * Queues one turn for the terminal loop.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CommandInput|string $input Submitted input.
     * @return void
     */
    public function run(string|CommandInput $input): void
    {
        EventLoop::queue(function () use ($input): void {
            if ($this->tui->isRunning()) {
                $this->doRun($input);
            }
        });
    }

    /**
     * Drains one turn, mapping each item onto the screen.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CommandInput|string $input Submitted input.
     * @return void
     */
    private function doRun(string|CommandInput $input): void
    {
        try {
            $this->tui->processRender();
            $stream = $this->turns->run($input);
            foreach ($stream as $item) {
                if (!$this->tui->isRunning()) {
                    return;
                }

                $this->apply($item);
                $this->tui->processRender();
                $suspension = EventLoop::getSuspension();
                EventLoop::delay(0, static function () use ($suspension): void {
                    $suspension->resume();
                });
                $suspension->suspend();
                if (!$this->tui->isRunning()) {
                    return;
                }
            }

            $result = $stream instanceof Generator ? $stream->getReturn() : null;
            $stopped = $result instanceof TurnResult ? $result->stopped : $this->turns->wasInterrupted();
            if ($stopped) {
                $this->mainView->notify('Response stopped.');
            }
        } catch (Throwable $throwable) {
            $this->mainView->notify($throwable->getMessage(), MessageKind::Error);
        } finally {
            $this->mainView->finishTurn();
        }
    }

    /**
     * Maps one turn item onto the screen.
     *
     * Approval pauses surface through the inline turn notice, so the terminal
     * result needs no second notification. A stopped outcome ends the session:
     * leave commands stop through the adapter, and a stop with no visible
     * session end would leave the terminal waiting on a dead host. Reasoning
     * accumulates on a muted thinking line instead of the answer.
     *
     * @param \Crustum\Ai\Streaming\Event\TextDelta|\Crustum\Ai\Streaming\Event\ReasoningDelta|\Crustum\Ai\Streaming\Event\ToolCall|\Crustum\Ai\Streaming\Event\ToolResult|\Crustum\Ai\Streaming\Event\Error|\Crustum\AiInteraction\InteractionCommand\TurnNotice|\Crustum\AiInteraction\InteractionCommand\TurnSelection|\Crustum\AiInteraction\InteractionCommand\TurnTask|\Crustum\AiInteraction\InteractionCommand\TurnConversation|object $item Turn item.
     * @return void
     */
    private function apply(object $item): void
    {
        if ($item instanceof TextDelta) {
            $this->mainView->appendResponse($item->delta);
        } elseif ($item instanceof ReasoningDelta) {
            $this->mainView->appendThinking($item->delta);
        } elseif ($item instanceof StreamToolCall) {
            $this->mainView->showToolCall($item->toolCall);
        } elseif ($item instanceof StreamToolResult) {
            $this->mainView->showToolResult($item->toolResult);
        } elseif ($item instanceof TurnNotice) {
            $this->mainView->notify(
                $item->text,
                $item->kind === 'error' ? MessageKind::Error : MessageKind::Notice,
            );
        } elseif ($item instanceof TurnSelection) {
            $this->mainView->showSelection($item->request);
        } elseif ($item instanceof TurnTask) {
            $this->mainView->queueTask($item->text, $item->origin);
        } elseif ($item instanceof TurnConversation) {
            $this->mainView->syncHistory();
        } elseif ($item instanceof TurnStopped) {
            $this->mainView->stop();
        } elseif ($item instanceof StreamError) {
            $this->mainView->notify($item->message, MessageKind::Error);
        }
    }
}
