<?php
declare(strict_types=1);

namespace Crustum\AiTui\View;

/**
 * Terminal message categories rendered by the chat views.
 */
enum MessageKind
{
    /**
     * Message authored by the terminal user.
     */
    case User;

    /**
     * Message streamed by the agent.
     */
    case Agent;

    /**
     * Announcement of a tool invocation requested by the agent.
     */
    case ToolCall;

    /**
     * Outcome of a finished tool invocation.
     */
    case ToolResult;

    /**
     * Session lifecycle note.
     */
    case System;

    /**
     * Informational note shown inside the history.
     */
    case Notice;

    /**
     * Failure note shown inside the history.
     */
    case Error;
}
