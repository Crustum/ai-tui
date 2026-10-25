<?php
declare(strict_types=1);

namespace Crustum\AiTui\Test\Feature\Support;

use Crustum\Ai\Contracts\Agent;
use Crustum\AiTui\Command\AiTuiCommand;

/**
 * Host terminal command with a canned answering agent.
 */
final class CannedAiTuiCommand extends AiTuiCommand
{
    /**
     * Builds the stub answering agent.
     *
     * @param class-string<\Crustum\Ai\Contracts\Agent> $class Agent class name.
     * @return \Crustum\Ai\Contracts\Agent Stub agent.
     */
    protected function makeAgent(string $class): Agent
    {
        return new StubTuiAgent();
    }
}
