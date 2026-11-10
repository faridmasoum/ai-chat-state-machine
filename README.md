# AI Chat State Machine

A small, deterministic state machine for building step-by-step conversational
flows — lead-capture forms, support bots, onboarding wizards, AI chat
assistants — in plain PHP. Framework-agnostic at its core, with optional
Laravel glue.

The key idea: **the engine decides, the AI assists.** You declare an ordered
list of steps with their own validation rules. The engine always tries your
deterministic rules first. An AI/LLM-backed interpreter (your choice of
provider — the package has no opinion) only gets a turn when your rules
genuinely can't classify the input, and even then it's only trusted to fill
in what your code couldn't place. This keeps a conversation that touches an
AI model fully reproducible and testable, because the actual step-by-step
logic never depends on a model call.

## Requirements

- PHP 8.0 or newer (tested on 8.0–8.4)
- No hard dependency on any framework
- Optional: Laravel 8, 9, 10, 11 or 12 for the bundled service provider / Eloquent trait

## Installation

```bash
composer require farid/ai-chat-state-machine
```

That's it — the core classes are usable immediately in any PHP project. If
you're on Laravel, the service provider auto-registers via package
discovery; nothing else to configure.

## Quick start (framework-agnostic)

```php
use Farid\AiChatStateMachine\Flow;
use Farid\AiChatStateMachine\FlowEngine;
use Farid\AiChatStateMachine\StepDefinition;
use Farid\AiChatStateMachine\StepResult;
use Farid\AiChatStateMachine\Support\ArraySession;
use Farid\AiChatStateMachine\Turn;

$flow = Flow::make()
    ->add(
        StepDefinition::make('name', 'Your name')
            ->parse(function (Turn $turn): StepResult {
                $value = $turn->text();

                return $value === ''
                    ? StepResult::invalid('A name is required.')
                    : StepResult::ok($value);
            })
    )
    ->add(
        StepDefinition::make('email', 'Your email')
            ->parse(function (Turn $turn): StepResult {
                $value = $turn->text();

                return filter_var($value, FILTER_VALIDATE_EMAIL)
                    ? StepResult::ok($value)
                    : StepResult::invalid('That does not look like an email address.');
            })
    );

$engine = new FlowEngine($flow);

$session = new ArraySession();
$engine->start($session);

echo $session->currentStep(); // "name"

$engine->handleTurn($session, new Turn('name', Turn::TEXT, 'Farid'));
echo $session->currentStep(); // "email"

$result = $engine->handleTurn($session, new Turn('email', Turn::TEXT, 'not-an-email'));
echo $result->isInvalid() ? $result->error : 'ok'; // "That does not look like an email address."
```

`ArraySession` is a tiny in-memory session you can serialise yourself
(`$session->toArray()` / `ArraySession::fromArray($data)`) into a cache key,
a cookie, a database row — whatever you already use.

## Core concepts

| Class            | Role                                                                             |
|------------------|-----------------------------------------------------------------------------------|
| `Flow`           | Ordered collection of `StepDefinition`s.                                          |
| `StepDefinition` | One step: when it applies, how it's rendered, how an answer is parsed/validated.  |
| `ChatState`      | The JSON-serialisable state of one conversation (answers, meta, epoch, edits).    |
| `Turn`           | One user action: a choice, free text, a list, a skip, an edit, or a submit.       |
| `StepResult`     | Outcome of parsing a turn against a step's rules.                                 |
| `FlowEngine`     | Orchestrates everything: decides the next step, applies turns, tracks progress.   |
| `ChatSession`    | Interface for whatever persists a conversation (you implement this).             |

### Declaring a step

```php
StepDefinition::make('country', 'Where are you based?')
    ->applies(fn ($state) => true)               // branch logic; defaults to always
    ->ui(fn ($state) => ['type' => 'choice', 'options' => ['IR', 'US', 'DE']])
    ->parse(function (Turn $turn, $state): StepResult {
        return in_array($turn->text(), ['IR', 'US', 'DE'], true)
            ? StepResult::ok($turn->text())
            : StepResult::invalid('Pick one of the listed countries.');
    })
    ->invalidates(['city'])                        // cleared if the answer changes
    ->optional(false)                               // or ->optionalWhen(fn ($state) => ...)
    ->display(fn ($value, $state) => $value);       // human-readable, e.g. for a review screen
```

Branching, required-vs-optional, "going back and editing an earlier answer
re-opens dependent steps" — all of it is plain closures over `ChatState`,
not a separate DSL to learn.

### Handling a turn

```php
$result = $engine->handleTurn($session, $turn); // TurnResult

$result->isInvalid();   // true -> show $result->error
$result->isCompleted(); // true -> every step is satisfied, flow finished
// otherwise -> ask $engine->ui($session) for the next prompt
```

`FlowEngine::handleTurn()` throws:

- `StepMismatchException` — the turn targets a step other than the
  session's current one (stale UI, double submit, replayed request).
- `InvalidTurnException` — the current step key, or an edit target, is not
  part of the flow at all.

### Editing a previous answer

Send an `edit` turn naming the step to revisit:

```php
$engine->handleTurn($session, new Turn($session->currentStep(), Turn::EDIT, 'country'));
```

The engine jumps back to that step. If it's in another step's `invalidates`
list and the answer actually changes, the dependent step is cleared and will
be asked again.

### Pausing for a side question (interrupts)

Sometimes the conversation needs to detour — e.g. confirm a phone number
before resuming the main flow — without losing track of where to come back
to:

```php
$state->startInterrupt('confirm_phone', /* resume at */ $session->currentStep());
```

`FlowEngine::nextStep()` honours the interrupt until it's answered, then
`endInterrupt()` returns control to the step named as the resume point.

### Plugging in an AI interpreter

Implement one interface to let a free-text model fill gaps your
deterministic rules leave as `StepResult::unrecognised(...)`:

```php
use Farid\AiChatStateMachine\Contracts\TurnInterpreter;
use Farid\AiChatStateMachine\Interpretation;

class MyLlmInterpreter implements TurnInterpreter
{
    public function interpret(ChatState $state, string $step, string $text): Interpretation
    {
        // Call whatever model/provider you use, map its output onto the step.
        $value = /* ... */;

        return $value !== null ? Interpretation::ok($value) : Interpretation::fail();
    }
}

$engine = new FlowEngine($flow, new MyLlmInterpreter());
```

The interpreter is only consulted when a step's `parse` closure returns
`StepResult::unrecognised()` (rather than `invalid()`), and only on `Turn::TEXT`
turns — your deterministic logic is always the source of truth.

## Laravel integration

The package has no hard dependency on `illuminate/*`, so it installs and
works in any PHP project. In a Laravel app, the service provider
auto-registers via `composer.json`'s `extra.laravel.providers` and lets you
publish a config file:

```bash
php artisan vendor:publish --tag=ai-chat-state-machine-config
```

To persist sessions on an Eloquent model, add the trait and a couple of
columns:

```php
use Farid\AiChatStateMachine\Laravel\HasChatState;
use Illuminate\Database\Eloquent\Model;

class ChatSession extends Model
{
    use HasChatState;

    protected $casts = [
        'chat_state' => 'array',
        'completed_at' => 'datetime',
    ];
}
```

```php
Schema::create('chat_sessions', function (Blueprint $table) {
    $table->id();
    $table->string('current_step');
    $table->json('chat_state')->nullable();
    $table->unsignedInteger('turn_count')->default(0);
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();
});
```

That model now satisfies `Farid\AiChatStateMachine\Contracts\ChatSession`
and can be passed straight into `FlowEngine`.

## Testing

```bash
composer install
vendor/bin/phpunit
```

## Design notes

- **No framework lock-in.** `Flow`, `StepDefinition`, `ChatState`, `Turn`,
  `StepResult`, `TurnResult` and `FlowEngine` are plain PHP with zero
  external dependencies. Laravel support is additive, in its own namespace.
- **Serialisable by design.** `ChatState::toArray()` / `fromArray()` round-trip
  through JSON cleanly, so a conversation survives a redeploy, a queue hop,
  or a page reload without any custom (de)serialisation code.
- **Idempotent-friendly.** Re-sending the same valid turn for the step the
  session is already past raises `StepMismatchException` instead of silently
  re-applying it, which makes double-submits and client retries safe to
  detect.

## License

MIT © Farid — see [LICENSE](LICENSE).
