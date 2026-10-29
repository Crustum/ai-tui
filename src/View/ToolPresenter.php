<?php
declare(strict_types=1);

namespace Crustum\AiTui\View;

/**
 * Renders tool invocations as human-readable history instead of raw JSON.
 *
 * Hosts whose tools ask questions out of band (approval prompts, option
 * pickers) implement this to keep the transcript readable: the call block
 * shows the question, the completion shows the answer. Returning null keeps
 * the default argument dump for the call or hides a successful result.
 */
interface ToolPresenter
{
    /**
     * Human-readable call preview replacing the JSON argument dump.
     *
     * @param string $name Tool name.
     * @param array<string, mixed> $arguments Tool arguments.
     * @return string|null Preview text, null for the default rendering.
     */
    public function summarizeCall(string $name, array $arguments): ?string;

    /**
     * Human-readable result line shown when the tool finishes.
     *
     * @param string $name Tool name.
     * @param mixed $result Tool result payload.
     * @return string|null Result text, null to keep the current behavior.
     */
    public function summarizeResult(string $name, mixed $result): ?string;
}
