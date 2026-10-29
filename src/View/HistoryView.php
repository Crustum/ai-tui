<?php
declare(strict_types=1);

namespace Crustum\AiTui\View;

use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\MessageRole;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\ToolResult;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Growing conversation history rendered above the composer.
 */
final class HistoryView extends ContainerWidget
{
    /**
     * Agent line of the turn in progress.
     *
     * @var \Crustum\AiTui\View\MessageView|null
     */
    private ?MessageView $response = null;

    /**
     * Thinking line of the turn in progress.
     *
     * @var \Crustum\AiTui\View\MessageView|null
     */
    private ?MessageView $thinking = null;

    /**
     * Tool blocks keyed by tool call identifier.
     *
     * @var array<string, \Crustum\AiTui\View\ToolView>
     */
    private array $tools = [];

    /**
     * Builds the vertically expanding history.
     */
    public function __construct()
    {
        $this->setStyle(new Style(gap: 1));
        $this->expandVertically(true);
    }

    /**
     * Replaces the history with the given messages and tool activity.
     *
     * @param iterable<\Crustum\Ai\Messages\Message> $history Conversation messages
     * @return void
     */
    public function load(iterable $history): void
    {
        $this->response = null;
        $this->thinking = null;
        $this->tools = [];
        $this->clear();
        foreach ($history as $message) {
            $content = $message->content;
            if ($content !== null && $content !== '') {
                $kind = match ($message->role) {
                    MessageRole::User => MessageKind::User,
                    MessageRole::Assistant => MessageKind::Agent,
                    default => MessageKind::System,
                };
                $this->append($content, $kind);
            }

            if ($message instanceof AssistantMessage) {
                foreach ($message->toolCalls as $tool) {
                    $this->showToolCall($tool);
                }
            }

            if ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $result) {
                    $this->showToolResult($result);
                }
            }
        }
    }

    /**
     * Starts a turn with the user prompt and an empty agent line.
     *
     * @param string $prompt User prompt
     * @return void
     */
    public function beginTurn(string $prompt): void
    {
        $this->append($prompt, MessageKind::User);
        $this->beginTaskTurn();
    }

    /**
     * Opens an agent response line without attributing input to the user.
     *
     * Task turns resolved from commands show only their output: the task
     * text itself never becomes chat text.
     *
     * @return void
     */
    public function beginTaskTurn(): void
    {
        $this->thinking = null;
        $this->response = $this->append('', MessageKind::Agent);
    }

    /**
     * Appends reasoning text to the muted thinking line of the turn.
     *
     * @param string $text Reasoning delta text.
     * @return void
     */
    public function appendThinking(string $text): void
    {
        $this->thinking ??= $this->append('', MessageKind::System);
        $this->thinking->setText($this->thinking->getText() . $text);
    }

    /**
     * Appends streamed text to the agent line of the turn.
     *
     * @param string $text Streamed text
     * @return void
     */
    public function appendResponse(string $text): void
    {
        $this->response ??= $this->append('', MessageKind::Agent);
        $this->response->setText($this->response->getText() . $text);
    }

    /**
     * Appends a notice line and closes the turn in progress.
     *
     * @param string $text Notice text
     * @param \Crustum\AiTui\View\MessageKind $kind Notice category
     * @return void
     */
    public function notify(string $text, MessageKind $kind = MessageKind::Notice): void
    {
        $this->finishTurn();
        $this->append($text, $kind);
    }

    /**
     * Shows the pending block for a tool invocation.
     *
     * @param \Crustum\Ai\Responses\Data\ToolCall $tool Tool invocation data.
     * @param string|null $summary Human-readable preview replacing the JSON argument dump.
     * @return void
     */
    public function showToolCall(ToolCall $tool, ?string $summary = null): void
    {
        $this->finishTurn();
        $key = $this->toolKey($tool);
        if (!isset($this->tools[$key])) {
            $this->tools[$key] = new ToolView($tool, $summary);
            $this->add($this->tools[$key]);
        }
    }

    /**
     * Completes the block of a finished tool invocation.
     *
     * @param \Crustum\Ai\Responses\Data\ToolResult $result Finished tool result.
     * @param string|null $summary Human-readable result line shown on completion.
     * @return void
     */
    public function showToolResult(ToolResult $result, ?string $summary = null): void
    {
        $this->finishTurn();
        $key = 'call:' . $result->id;
        if (!isset($this->tools[$key])) {
            $this->tools[$key] = new ToolView(new ToolCall(
                id: $result->id,
                name: $result->name,
                arguments: $result->arguments,
            ));
            $this->add($this->tools[$key]);
        }

        $this->tools[$key]->complete($result, $summary);
    }

    /**
     * Returns the registry key for a tool invocation.
     *
     * @param \Crustum\Ai\Responses\Data\ToolCall $tool Tool invocation data
     * @return string
     */
    private function toolKey(ToolCall $tool): string
    {
        return 'call:' . $tool->id;
    }

    /**
     * Drops the empty agent line and closes the turn in progress.
     *
     * Closing also ends the current reasoning phase: the next reasoning delta
     * opens a fresh block after the tool outcome, so each thinking stays next
     * to the calls it produced instead of piling into one block per turn.
     *
     * @return void
     */
    public function finishTurn(): void
    {
        if ($this->response instanceof MessageView && $this->response->getText() === '') {
            $this->remove($this->response);
        }

        $this->response = null;
        $this->thinking = null;
    }

    /**
     * Appends one history line.
     *
     * @param string $text Line text
     * @param \Crustum\AiTui\View\MessageKind $kind Line category
     * @return \Crustum\AiTui\View\MessageView
     */
    private function append(string $text, MessageKind $kind): MessageView
    {
        $widget = new MessageView($text, $kind);
        $this->add($widget);

        return $widget;
    }
}
