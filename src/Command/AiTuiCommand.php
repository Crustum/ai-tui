<?php
declare(strict_types=1);

namespace Crustum\AiTui\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\RemembersConversations;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Storage\DatabaseConversationStore;
use Crustum\AiInteraction\Agent\SessionTitleAgent;
use Crustum\AiInteraction\Configuration\ConfigurationStore;
use Crustum\AiInteraction\InputHistory\InputHistory;
use Crustum\AiInteraction\InteractionCommand\CakeCommandAdapter;
use Crustum\AiInteraction\InteractionCommand\ClearCommand;
use Crustum\AiInteraction\InteractionCommand\CommandInterceptor;
use Crustum\AiInteraction\InteractionCommand\Commands;
use Crustum\AiInteraction\InteractionCommand\ConversationMessages;
use Crustum\AiInteraction\InteractionCommand\HelpCommand;
use Crustum\AiInteraction\InteractionCommand\LeaveCommand;
use Crustum\AiInteraction\InteractionCommand\ResumeCommand;
use Crustum\AiInteraction\InteractionCommand\TurnRunner as InteractionTurnRunner;
use Crustum\AiInteraction\Interruption\StopSignal;
use Crustum\AiInteraction\Session\Session;
use Crustum\AiInteraction\Storage\InMemoryStorage;
use Crustum\AiInteraction\Storage\StorageInterface;
use Crustum\AiTui\Tui;
use function is_string;
use function is_subclass_of;
use function preg_match;
use function stream_isatty;
use const STDIN;

/**
 * Host terminal command running the chat screen.
 */
class AiTuiCommand extends Command
{
    /**
     * Returns the command name.
     *
     * @return string Command name.
     */
    public static function defaultName(): string
    {
        return 'ai tui';
    }

    /**
     * Builds the option parser.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser Option parser.
     * @return \Cake\Console\ConsoleOptionParser Configured parser.
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Chat with an agent inside an interactive terminal screen.')
            ->addOption('agent', [
                'short' => 'a',
                'help' => 'Agent class name answering prompts.',
                'required' => true,
            ])
            ->addOption('user', [
                'short' => 'u',
                'help' => 'Participant uuid scoping history and conversations.',
            ])
            ->addOption('type', [
                'short' => 't',
                'help' => 'Participant type.',
                'default' => 'console',
            ])
            ->addOption('title', [
                'help' => 'Chat screen title.',
                'default' => 'AiTui',
            ])
            ->addOption('description', [
                'help' => 'Chat screen description.',
                'default' => '',
            ])
            ->addOption('titles', [
                'boolean' => true,
                'help' => 'Generate conversation titles from the first turns.',
                'default' => false,
            ]);
    }

    /**
     * Runs the terminal screen.
     *
     * @param \Cake\Console\Arguments $args Command arguments.
     * @param \Cake\Console\ConsoleIo $io Console input-output.
     * @return int|null Exit code.
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $agentClass = $args->getOption('agent');

        if (!is_string($agentClass) || $agentClass === '' || !is_subclass_of($agentClass, Agent::class)) {
            $io->error('An answering agent class name is required.');

            return static::CODE_ERROR;
        }

        $userId = $args->getOption('user');

        if (!is_string($userId) || $userId === '') {
            $io->error('A participant user uuid is required. Pass --user with your user identifier.');

            return static::CODE_ERROR;
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $userId) !== 1) {
            $io->error('The participant identifier must be a uuid matching your users table.');

            return static::CODE_ERROR;
        }

        if (!stream_isatty(STDIN)) {
            $io->error('AiTui requires an interactive TTY.');

            return static::CODE_ERROR;
        }

        $participantType = $args->getOption('type');
        $participantType = is_string($participantType) && $participantType !== '' ? $participantType : 'console';

        $participant = (object)['id' => $userId];

        $agent = $this->makeAgent($agentClass);

        if ($agent instanceof RemembersConversations) {
            $agent = $agent->forUser($participant);
        }

        $store = new DatabaseConversationStore();
        $commands = (new Commands())->addCommand([
            new ClearCommand(),
            new HelpCommand(),
            new LeaveCommand(),
            new ResumeCommand(),
        ]);
        $adapter = new CakeCommandAdapter(
            $agent,
            $commands,
            $store,
            static function (Agent $agent, UserMessage $prompt): void {
                $agent->prompt($prompt);
            },
            new ConfigurationStore(new InMemoryStorage(), $userId),
            $participantType,
            $userId,
            $participant,
        );
        $interceptor = new CommandInterceptor($commands);
        $history = new InputHistory($this->storage());
        $session = new Session($adapter);
        $session->boot();

        $turns = new InteractionTurnRunner(
            $interceptor,
            $adapter,
            $agent,
            $history,
            new StopSignal($this->storage(), 'ai-tui-' . $userId),
            null,
            $this->titleAgent((bool)$args->getOption('titles')),
        );
        $messages = static function () use ($store, $adapter): iterable {
            $conversationId = $adapter->conversationId();

            if (!is_string($conversationId) || $conversationId === '') {
                return [];
            }

            return (new ConversationMessages($store, $conversationId))->messages();
        };
        $tui = new Tui($turns, $commands, $history, $messages);

        $title = $args->getOption('title');

        if (is_string($title) && $title !== '') {
            $tui->setTitle($title);
        }

        $description = $args->getOption('description');

        if (is_string($description) && $description !== '') {
            $tui->setDescription($description);
        }

        $tui->run();

        return static::CODE_SUCCESS;
    }

    /**
     * Builds the title agent when naming is enabled.
     *
     * @param bool $enabled True when conversation titles should be generated.
     * @return \Crustum\Ai\Contracts\Agent|null Title agent or null when disabled.
     */
    protected function titleAgent(bool $enabled): ?Agent
    {
        return $enabled ? new SessionTitleAgent() : null;
    }

    /**
     * Builds the answering agent.
     *
     * @param class-string<\Crustum\Ai\Contracts\Agent> $class Agent class name.
     * @return \Crustum\Ai\Contracts\Agent Answering agent.
     */
    protected function makeAgent(string $class): Agent
    {
        return new $class();
    }

    /**
     * Resolves turn storage.
     *
     * @return \Crustum\AiInteraction\Storage\StorageInterface Turn storage.
     */
    protected function storage(): StorageInterface
    {
        return new InMemoryStorage();
    }
}
