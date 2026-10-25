# CakePHP AiTui Plugin

- [Introduction](#introduction)
- [Quickstart](#quickstart)
- [Installation](#installation)
    - [Installing the Plugin](#installing-the-plugin)
    - [Conversation Storage](#conversation-storage)
- [Requirements](#requirements)
- [The Chat Screen](#the-chat-screen)
- [Usage](#usage)
    - [Wiring the Terminal Host](#wiring-the-terminal-host)
    - [Booting the Session](#booting-the-session)
    - [Custom Commands](#custom-commands)
- [Sessions and Conversations](#sessions-and-conversations)
- [Slash Commands](#slash-commands)
- [Selection Panel](#selection-panel)
- [Input Recall](#input-recall)
- [Stopping Responses](#stopping-responses)
- [Tool Calls](#tool-calls)
- [Key Bindings](#key-bindings)
- [Host Terminal Command](#host-terminal-command)

<a name="introduction"></a>
## Introduction

The **AiTui** plugin is a terminal chat screen for driver agents. The conversation
appears above a multiline composer fixed at the bottom, with a working indicator
above the input. Turns run through `Crustum\AiInteraction` contracts — slash
commands, selections, input history, stop flags, sessions — while this plugin owns
rendering, the chunk loop and terminal input. Each `Tui` instance runs once.

The screen streams agent answers token by token, renders tool invocations inline,
queues inputs submitted mid-turn, completes `/` commands from a menu, opens an
option panel when a command asks for a selection, and recalls earlier inputs with
the arrow keys. Conversations persist in the driver store, so restarting the
screen continues the latest conversation instead of starting blank.

<a name="quickstart"></a>
## Quickstart

Install the plugin, migrate the conversation tables, then chat with any agent
class:

```bash
composer require crustum/ai-tui
bin/cake plugin load Crustum/AiTui
bin/cake manifest install --plugin Crustum/Ai
bin/cake manifest install --plugin Crustum/AiInteraction
bin/cake migrations migrate
bin/cake ai tui --agent="App\Ai\Agents\Assistant" --user="PASTE-YOUR-USER-UUID"
```

Type a question and press Enter. Type `/` to see the slash commands, `/resume`
to return to an earlier conversation, `/clear` to start a fresh one.

> [!NOTE]
> Stored conversations require the driver's `agent_conversations` tables (see
> [Conversation Storage](#conversation-storage)). Without them the screen still
> runs, but nothing persists between restarts.

<a name="installation"></a>
## Installation

<a name="installing-the-plugin"></a>
### Installing the Plugin

Install via Composer:

```bash
composer require crustum/ai-tui
```

> [!NOTE]
> This plugin should be registered in your `config/plugins.php` file.

```bash
bin/cake plugin load Crustum/AiTui
```

> [!TIP]
> **After the plugin registers itself**, it's recommended to install the package
> metadata with the manifest system:

```bash
bin/cake manifest install --plugin Crustum/AiTui
```

Alternatively, you can load the plugin in your `Application.php`:

```php
// In src/Application.php
public function bootstrap(): void
{
    parent::bootstrap();

    $this->addPlugin('Crustum/AiTui');
}
```

The plugin ships no configuration file — the host command takes everything it
needs from CLI options, and programmatic hosts pass their dependencies
explicitly (see [Usage](#usage)).

<a name="conversation-storage"></a>
### Conversation Storage

Conversation rows live in the driver store (`agent_conversations` and
`agent_conversation_messages` tables). Both manifests copy migrations into the
application, then migrations run at the app level:

```bash
bin/cake manifest install --plugin Crustum/Ai
bin/cake manifest install --plugin Crustum/AiInteraction
bin/cake migrations migrate
```

The screen boots a session on startup: it continues the participant's latest
conversation when there is one, otherwise it starts a fresh conversation. Both
agent turns and history sync read the same session identifier, so nothing
drifts apart after `/clear` or `/resume`.

<a name="requirements"></a>
## Requirements

- PHP 8.4.1 or later.
- An interactive terminal for both standard input and standard output.
- An agent class implementing `Crustum\Ai\Contracts\Agent`, passed as
  `--agent` (or constructed by the programmatic host).
- For continuity between restarts, an agent implementing
  `Crustum\Ai\Contracts\RemembersConversations` and the migrated conversation
  tables. Agents without it still chat, but every process start begins blank
  and `/resume` reports that the agent cannot continue conversations.

<a name="the-chat-screen"></a>
## The Chat Screen

From top to bottom the screen shows an optional figlet banner, the title and
description header, the growing conversation history, the working indicator
while a turn runs, and the multiline composer with its hint line:

```bash
✳ AiTui
A conversation with your agent

● streamed answer text
● weather(path: "examples/basic.php")
❯ user question

✻ Ready
────────────────────────────────────────────────────────────
❯ draft input
────────────────────────────────────────────────────────────
Enter send · Shift+Enter newline · Ctrl+C exit
```

History lines carry a kind prefix: `❯` for user input, `●` for agent text,
`● name(...)` for tool invocations, `ℹ` for notices, `!` for errors, `·` for
system notes. Reasoning streams onto a muted `·` thinking line ahead of the
answer. Inputs submitted while a turn runs appear under a
`Messages queued for the next turns` preview and run in order once the turn
finishes. Commands resolving to task input join the same queue instead of
rendering: the text runs as the next turn. When the command names its origin,
the turn shows a `❯ /review` line naming where the task came from; without an
origin the turn runs silently.

<a name="usage"></a>
## Usage

<a name="wiring-the-terminal-host"></a>
### Wiring the Terminal Host

Build the interaction stack, hand it to `Tui`, and run. The example below is
the same wiring the `ai tui` command performs:

```php
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Storage\DatabaseConversationStore;
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
use Crustum\AiInteraction\InteractionCommand\TurnRunner;
use Crustum\AiInteraction\Interruption\StopSignal;
use Crustum\AiInteraction\Session\Session;
use Crustum\AiInteraction\Storage\InMemoryStorage;
use Crustum\AiTui\Tui;

$agent = new Assistant($participant);
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
    static function ($agent, UserMessage $prompt): void {
        $agent->prompt($prompt);
    },
    new ConfigurationStore(new InMemoryStorage(), $userId),
    'users',
    $userId,
    $participant,
);
$history = new InputHistory(new InMemoryStorage());
$session = new Session($adapter);
$session->boot();
$turns = new TurnRunner(
    new CommandInterceptor($commands),
    $adapter,
    $agent,
    $history,
    new StopSignal(new InMemoryStorage(), 'ai-tui-' . $userId),
);

$tui = new Tui(
    $turns,
    $commands,
    $history,
    static function () use ($store, $adapter): iterable {
        $conversationId = $adapter->conversationId();

        if (!is_string($conversationId) || $conversationId === '') {
            return [];
        }

        return (new ConversationMessages($store, $conversationId))->messages();
    },
);
$tui
    ->setTitle('Filesystem Agent')
    ->setDescription('Explore, read and edit files')
    ->run();
```

Customize the header through fluent setters; `setFiglet($text, $font)` adds a
banner above the title (`standard` by default, `big`, `small`, `slant` and `mini`
included; pass an empty string to hide it). The screen loads display history at
startup and sends inputs through the turn primitive. The host configures
commands and history before passing them in.

<a name="booting-the-session"></a>
### Booting the Session

Always boot a `Session` over the adapter before running. Boot rebinds a
preselected conversation, continues the participant's latest conversation, or
starts a fresh one — on the adapter and the agent together:

```php
$session = new Session($adapter);
$session->boot();
```

Without boot the adapter holds no identifier: history sync stays empty, restarts
never continue, and `/clear` moves only one side. The history-sync supplier
must resolve the identifier at call time (as above), because `/clear` and
`/resume` switch it mid-session.

<a name="custom-commands"></a>
### Custom Commands

Mount extra commands on the shared `Commands` collection — they appear in the
`/` menu next to the built-ins:

```php
use Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface;
use Crustum\AiInteraction\InteractionCommand\CommandInterface;

final class StatusCommand implements CommandInterface
{
    public function name(): string
    {
        return '/status';
    }

    public function describe(): string
    {
        return 'Shows the workspace status.';
    }

    public function run(CommandAdapterInterface $adapter, string $value): void
    {
        $adapter->notify('Workspace is clean.');
    }
}

$commands->addCommand([new StatusCommand()]);
```

Use `$adapter->notify()` for information, `$adapter->warn()` for warnings,
`$adapter->error()` for failures, and `$adapter->requestSelection()` to open
the option panel. Command identifiers must start with a slash.

<a name="sessions-and-conversations"></a>
## Sessions and Conversations

One session owns one conversation identifier, applied to the adapter and the
agent together:

| Action | Behavior |
| --- | --- |
| Startup (`Session::boot()`) | Rebinds a preselected conversation, continues the latest one, or starts fresh. |
| Plain input | Streams through the agent; the driver middleware persists the turn under the session identifier. |
| `/clear` | Starts a fresh conversation on both sides, leaving the stored one intact. |
| `/resume` | Lists stored conversations (or jumps by key) and continues the chosen one on both sides. |
| Restart | Continues the latest conversation — history sync reloads it at startup. |

> [!NOTE]
> Resuming requires an agent implementing `RemembersConversations`. Other agents
> keep answering, but continuity and `/resume` stay unavailable.

<a name="slash-commands"></a>
## Slash Commands

Type `/` to show the mounted commands with their descriptions. Keep typing to
filter by name. Use ↑/↓ to select, Tab to complete and add arguments, Enter to run
the selected command, and Esc to close the menu while keeping the draft.
Commands submitted during a turn join the input queue. Custom commands mounted on
the shared `Commands` collection appear in the same menu; the host owns the
registry.

Built-in commands mounted by the host command:

| Command | Description |
| --- | --- |
| `/help` | Lists what can be typed here. |
| `/clear` | Starts a new conversation, leaving the current one stored. |
| `/resume` | Lets you choose a stored conversation to resume. |
| `/exit` | Stops the interaction. |

<a name="selection-panel"></a>
## Selection Panel

Commands requesting a selection (for example `/resume` without a key) swap the
composer for an option panel. The panel shows a framed search field with each
option's description below its label. Type to filter, use ↑/↓ to choose, Enter to
confirm or Esc to cancel. Confirmation submits a `CommandInput` with the request's
command and the option's original value; cancellation submits nothing. The draft
is restored when the selector closes, queued messages wait for the resolution,
and the selected command runs before them.

<a name="input-recall"></a>
## Input Recall

Inputs are shared across the user's turns and restarts while staying isolated
from other users. Use ↑/↓ to recall single-line inputs, or Ctrl+P/Ctrl+N for
multiline inputs. Moving past the newest entry restores the draft; editing a
recalled input starts a new draft. Arrow keys keep their normal editing behavior
in multiline inputs and their selection behavior in menus. Attachments are not
restored — the composer recalls text only.

<a name="stopping-responses"></a>
## Stopping Responses

Press Esc during a response to request a stop; the text received so far stays in
place. Esc first closes an open command menu or cancels a selection — press it
with those closed to stop the response. Stops are cooperative per-turn flags:
the chunk loop observes them between events, and the flag clears on every turn.

<a name="tool-calls"></a>
## Tool Calls

When the agent invokes a tool, the history shows a pending block with the tool
name and arguments (`● weather(path: "examples/basic.php")`). Long or nested
arguments render as an indented JSON block. Completion collapses the block back
to the call summary — result payloads stay hidden unless the call fails, in
which case the block turns red with the failure note. Each invocation is tracked
by its tool call identifier, so parallel calls never mix.

<a name="key-bindings"></a>
## Key Bindings

| Keys | Context | Action |
| --- | --- | --- |
| `Enter` | Composer | Send (or queue while busy) |
| `Shift+Enter` | Composer | Newline |
| `Ctrl+C` | Everywhere | Exit |
| `Esc` | Response running | Stop the response |
| `Esc` | Menu / selection open | Close menu / cancel selection |
| `↑` / `↓` | Single-line composer | Recall input history |
| `Ctrl+P` / `Ctrl+N` | Composer | Recall previous / next input |
| `↑` / `↓` | Command menu / selection | Move highlight |
| `Tab` | Command menu | Complete the command |
| `Enter` | Command menu | Run the selected command |
| `Enter` | Selection panel | Confirm the highlighted option |

<a name="host-terminal-command"></a>
## Host Terminal Command

`bin/cake ai tui` runs the chat screen with a readline-free terminal loop:

```bash
bin/cake ai tui --agent="App\Ai\Agents\Assistant" --user="123e4567-e89b-12d3-a456-426614174000" --type=users --title="Helper"
```

| Option | Description |
| --- | --- |
| `--agent`, `-a` | Agent class name answering prompts (required). |
| `--user`, `-u` | Participant uuid scoping history and conversations (required, must exist in your users table). |
| `--type`, `-t` | Participant type (default: `console`). |
| `--title` | Chat screen title (default: `AiTui`). |
| `--description` | Chat screen description (default: empty). |

A non-interactive terminal aborts with an error. The command boots a session
before the first render, so restarts continue the latest conversation.
