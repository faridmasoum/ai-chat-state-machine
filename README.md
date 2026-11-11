# StepChat

[![Latest Version](https://img.shields.io/packagist/v/faridm/stepchat.svg?style=flat-square)](https://packagist.org/packages/faridm/stepchat)
[![Tests](https://img.shields.io/github/actions/workflow/status/faridmasoum/stepchat/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/faridmasoum/stepchat/actions)
[![Total Downloads](https://img.shields.io/packagist/dt/faridm/stepchat.svg?style=flat-square)](https://packagist.org/packages/faridm/stepchat)
[![PHP Version](https://img.shields.io/packagist/php-v/faridm/stepchat.svg?style=flat-square)](https://packagist.org/packages/faridm/stepchat)
[![License](https://img.shields.io/packagist/l/faridm/stepchat.svg?style=flat-square)](LICENSE)

**Conversational forms that always finish, always validate, and never hallucinate your data.**

Build step-by-step chat experiences (lead capture, support triage, onboarding, quotes, surveys) in plain PHP. Your server owns the conversation: it decides the next question and validates every answer. An AI model is an *optional* helper for messy free text, never the source of truth. Add a polished chat widget to any website with **one `<script>` tag**.

<p align="center">
  <img src="docs/widget-en.png" alt="English widget" width="330">
  &nbsp;&nbsp;
  <img src="docs/widget-ar.png" alt="Arabic (RTL) widget" width="330">
</p>

- [Why this instead of a normal chat service?](#why-this-instead-of-a-normal-chat-service)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Add the chat widget to your website](#add-the-chat-widget-to-your-website)
- [Widget reference](#widget-reference)
- [Defining flows](#defining-flows)
- [Plugging in an AI model](#plugging-in-an-ai-model-optional)
- [Laravel](#laravel)
- [HTTP protocol](#http-protocol)
- [Testing](#testing) · [Contributing](#contributing) · [License](#license)

## Why this instead of a normal chat service?

Hosted chat tools and "just prompt an LLM" bots are great at open-ended conversation. They are a poor fit when you need a **specific set of validated answers** at the end of the conversation. This package is built for that job.

| | This package | Prompt-only LLM chatbot | Hosted chat SaaS (Intercom, Tidio, ...) |
|---|---|---|---|
| **Collected data is guaranteed valid** | Yes: every answer passes your rules before it is stored | No: the model may skip, invent or mis-format fields | Depends on the product's form blocks |
| **Always reaches the end** | Yes: the server picks the next step, so the flow cannot wander | No: conversations drift | Mostly |
| **Deterministic & unit-testable** | Yes: plain PHP, same input gives same output | No: output varies per run | No access to the logic |
| **Works with no AI at all** | Yes, and costs nothing per message | No | n/a |
| **AI cost** | Only when your rules cannot parse a message | Every message | Per seat / per conversation |
| **Your data stays on your server** | Yes: self-hosted, you own the storage | Sent to a model provider | Stored by the vendor |
| **Vendor lock-in** | None: MIT, any model or none | Provider specific | High |
| **Resume after reload / device hiccup** | Built in | You build it | Yes |
| **Safe against double-submit & stale tabs** | Built in (step mismatch protection) | You build it | Varies |
| **Edit an earlier answer** | Built in, with dependent steps re-opened | Awkward | Rare |
| **Multilingual with real RTL** | Built in: Arabic, Persian, Hebrew, Urdu, ... | Model dependent | Varies |
| **Front-end** | One script tag, no build step, no dependencies | You build it | Included (their branding) |

**The idea in one line: the engine decides, the AI assists.** Because the step-by-step logic never depends on a model call, a conversation that involves AI stays reproducible and testable.

## Installation

```bash
composer require faridm/stepchat
```

Requires PHP 8.0 or newer. There are no hard dependencies. The Laravel integration is used automatically when Laravel is present.

## Quick start

A complete flow in a handful of lines, using the built-in step presets:

```php
use FaridM\StepChat\Flow;
use FaridM\StepChat\FlowEngine;
use FaridM\StepChat\Steps;
use FaridM\StepChat\Support\ArraySession;
use FaridM\StepChat\Turn;

$flow = Flow::make()
    ->add(Steps::text('name', 'What is your name?'))
    ->add(Steps::email('email', 'And your email?'))
    ->add(Steps::choice('plan', 'Which plan?', ['starter' => 'Starter', 'pro' => 'Pro']))
    ->add(Steps::phone('phone', 'Phone number (optional)')->optional())
    ->onComplete(function (array $answers) {
        // $answers = ['name' => 'Farid', 'email' => '...', 'plan' => 'pro', 'phone' => null]
        // Save to your database, send an email, call your CRM...
    });

$engine  = new FlowEngine($flow);
$session = new ArraySession();
$engine->start($session);

$engine->ui($session);            // ['type' => 'text', 'message' => 'What is your name?', ...]

$engine->handleTurn($session, new Turn('name', Turn::TEXT, 'Farid'));
$result = $engine->handleTurn($session, new Turn('email', Turn::TEXT, 'nope'));
$result->isInvalid();             // true
$result->error;                   // "That does not look like an email address."

// ... answer the remaining steps, then:
$engine->ui($session);            // ['type' => 'review', 'items' => [...]]  summary screen
$engine->handleTurn($session, new Turn('done', Turn::SUBMIT))->isCompleted(); // true, onComplete fired
```

`ArraySession` can be saved anywhere via `toArray()` / `ArraySession::fromArray()` (cache, database, cookie).

## Add the chat widget to your website

The widget is optional. It is a single dependency-free JavaScript file (Shadow DOM, so your site's CSS cannot break it and it cannot break your CSS). Pick the path that matches your stack.

### Laravel: three steps

```bash
composer require faridm/stepchat
php artisan stepchat:install        # publishes config + script, scaffolds app/Chat/LeadFlow.php
```

1. Register the flow in `config/stepchat.php`:
   ```php
   'widget' => ['flows' => ['lead' => App\Chat\LeadFlow::class]],
   ```
2. Enable it in `.env`:
   ```
   STEPCHAT_WIDGET=true
   ```
3. Add one line to your layout, just before `</body>`:
   ```blade
   @stepChatWidget
   ```

Done. The route, caching of conversations, CSRF handling and the script tag are all taken care of. Options per call: `@stepChatWidget('lead', ['lang' => 'fa', 'color' => '#0ea5e9'])` or `<x-stepchat-widget flow="lead" lang="ar" />`.

Save the result by listening for the event:

```php
use FaridM\StepChat\Laravel\FlowCompleted;

Event::listen(FlowCompleted::class, fn ($e) => Lead::create($e->answers));
```

### Plain PHP (any framework or none)

`endpoint.php`: the entire backend:

```php
require __DIR__ . '/vendor/autoload.php';

use FaridM\StepChat\Flow;
use FaridM\StepChat\Http\ChatEndpoint;
use FaridM\StepChat\Http\FlowRegistry;
use FaridM\StepChat\Steps;
use FaridM\StepChat\Support\NativeSessionStore;

$flows = (new FlowRegistry())->register('lead', fn () => Flow::make()
    ->add(Steps::text('name', 'What is your name?'))
    ->add(Steps::email('email', 'Your email?'))
    ->onComplete(fn (array $answers) => mail('you@example.com', 'New lead', print_r($answers, true))));

(new ChatEndpoint($flows, new NativeSessionStore()))->serve();
```

Then on any page:

```html
<script src="/path/to/stepchat-widget.js"
        data-endpoint="/endpoint.php" data-flow="lead" defer></script>
```

A runnable version lives in [`examples/plain-php`](examples/plain-php): run `php -S localhost:8000` and open `/examples/plain-php/index.html`.

### Any site, via CDN

Copy the file from `resources/dist/stepchat-widget.js` or load it straight from GitHub through jsDelivr:

```html
<script src="https://cdn.jsdelivr.net/gh/faridmasoum/stepchat@v1.0.1/resources/dist/stepchat-widget.js"
        data-endpoint="https://your-api.example.com/chat" data-flow="lead" defer></script>
```

The endpoint can be anything that speaks the [HTTP protocol](#http-protocol) (for cross-origin use, send the usual CORS headers).

## Widget reference

### Script-tag attributes

| Attribute | Default | Description |
|---|---|---|
| `data-endpoint` | *required* | URL the widget POSTs to. |
| `data-flow` | `""` | Flow name, sent with every request. |
| `data-lang` | `auto` | Language code (`en`, `fa`, `ar`, `he`, `ur`, ...) or `auto` to use `<html lang>` / the browser language. |
| `data-dir` | `auto` | `ltr`, `rtl`, or `auto` (detected from the language). |
| `data-language-switcher` | off | Show a language dropdown in the header. |
| `data-languages` | all built-in | Comma list limiting the switcher, e.g. `en,fa,ar`. |
| `data-theme` | `auto` | `light`, `dark`, or `auto` (follows the visitor's OS). |
| `data-color` | `#4f46e5` | Brand colour. |
| `data-title` | translated | Header title. |
| `data-position` | `end` | `end` (bottom-right in LTR, bottom-left in RTL) or `start`. |
| `data-open` | `false` | Open the panel on load. |
| `data-mode` / `data-target` | floating | `data-mode="inline" data-target="#chat"` embeds the chat inside an element instead of a floating bubble. |

### JavaScript API

```html
<script src="stepchat-widget.js"></script>
<script>
  const chat = StepChatWidget.mount({
    endpoint: '/endpoint.php',
    flow: 'lead',
    lang: 'auto',
    languages: ['en', 'ar', 'fa'],          // enables the language switcher
    theme: 'auto',
    onComplete: (answers) => console.log(answers),
  });

  chat.open();  chat.close();
  chat.setLanguage('ar');   // switch at any time; the server re-localises the questions
  chat.restart();
  chat.destroy();
</script>
```

Events fire on the widget element and bubble to `document`: `acw:step` (`detail.step`, `detail.progress`) and `acw:complete` (`detail.summary`). Use them for analytics:

```js
document.addEventListener('acw:complete', (e) => gtag('event', 'lead', { flow: e.detail.flow }));
```

### Languages, RTL and switching

- **Built in:** English, Persian (fa), Arabic (ar), Hebrew (he), Urdu (ur), German, French, Spanish, Turkish.
- **Every right-to-left language is mirrored automatically** (Arabic, Persian, Hebrew, Urdu, Pashto, Sindhi, Kurdish (Sorani), Uyghur, Yiddish, Dhivehi and more): layout, bubbles, icons and the launcher position flip. Detection uses the browser's `Intl.Locale` with a built-in fallback list, and `data-dir` always wins if you need to override it.
- **Switch at runtime** with the dropdown (`data-language-switcher`) or `chat.setLanguage('fa')`. The widget sends the language to your server as `locale`, so your flow can return questions in that language too.
- **Add any language** (UI strings fall back to English for missing keys):

```js
StepChatWidget.addLocale('sv', {
  title: 'Chatt', send: 'Skicka', skip: 'Hoppa över', edit: 'Redigera',
  submit: 'Skicka in', placeholder: 'Skriv ditt svar…',
  done: 'Tack! Dina svar har skickats.',
}, { name: 'Svenska', dir: 'ltr' });

StepChatWidget.addLocale('ckb', { title: 'گفتوگۆ' }, { name: 'کوردی', dir: 'rtl' });
```

Localising the *questions* happens on your server, where you build the flow. The `$locale` argument is the language the visitor picked:

```php
$flows->register('lead', function (?string $locale): Flow {
    $q = $locale === 'fa' ? 'نام شما چیست؟' : 'What is your name?';
    return Flow::make()->add(Steps::text('name', $q));
});
```

### Theming

Override CSS custom properties from the page (they pierce the Shadow DOM boundary):

```css
[data-acw] { --acw-primary: #0ea5e9; --acw-radius: 12px; --acw-font: "Vazirmatn", system-ui; }
```

Available: `--acw-primary`, `--acw-on-primary`, `--acw-bg`, `--acw-fg`, `--acw-muted`, `--acw-border`, `--acw-bot`, `--acw-user`, `--acw-radius`, `--acw-font`, `--acw-z`.

### Built for real users

Keyboard navigation and focus management, `aria-live` announcements, `Escape` to close, reduced-motion support, full-screen layout on phones, dark mode, and text is always inserted as text (never HTML), so flow content cannot inject markup.

## Defining flows

### Step presets

| Preset | UI | Stores |
|---|---|---|
| `Steps::text($key, $label, $options)` | text / textarea (`multiline`), `min`, `max`, `pattern`, `placeholder` | string |
| `Steps::email($key, $label)` | email input | string |
| `Steps::phone($key, $label)` | tel input | string |
| `Steps::number($key, $label, $min, $max)` | number input | int / float |
| `Steps::choice($key, $label, $options)` | buttons | the chosen value |
| `Steps::multiChoice($key, $label, $options, $min, $max)` | multi-select | list of values |
| `Steps::confirm($key, $label)` | Yes / No | bool |

Every preset returns a `StepDefinition`, so you can chain `->optional()`, `->applies()`, `->invalidates()` and friends:

```php
Flow::make()
    ->add(Steps::choice('type', 'Are you a company?', ['yes' => 'Yes', 'no' => 'No']))
    ->add(Steps::text('company', 'Company name')
        ->applies(fn ($state) => $state->answer('type') === 'yes'))   // branching
    ->add(Steps::phone('phone', 'Phone')->optional(true, null));       // skippable
```

### Custom steps

For anything the presets don't cover, write your own rules. The engine never needs a model for this:

```php
use FaridM\StepChat\StepDefinition;
use FaridM\StepChat\StepResult;
use FaridM\StepChat\Turn;

StepDefinition::make('budget', 'What is your monthly budget (USD)?')
    ->ui(fn ($state) => ['type' => 'text', 'inputType' => 'number', 'min' => 100])
    ->parse(function (Turn $turn): StepResult {
        $n = (int) $turn->text();
        return $n >= 100 ? StepResult::ok($n) : StepResult::invalid('The minimum budget is 100 USD.');
    })
    ->display(fn ($value) => '$' . $value);   // how it appears on the review screen
```

### Branching, editing, interrupts

- **Branching:** `->applies(fn (ChatState $s) => ...)` decides whether a step is asked at all.
- **Editing:** the review screen lets users change any earlier answer. If `->invalidates(['city'])` is set on that step and the answer changes, the dependent steps are cleared and re-asked.
- **Interrupts / hand-off:** see [Hand-off interrupt](#hand-off-interrupt).
- **Errors you can catch:** `StepMismatchException` (stale tab or replayed request) and `InvalidTurnException` (unknown step, or an edit/submit that is not allowed here). The HTTP layer already turns them into proper responses.

## Step options

Every option below is optional and chainable on `StepDefinition` (or on any `Steps::*` preset). A flow that uses none of them behaves like a plain v1 flow.

| Option | What it does |
|---|---|
| `question(string\|Closure(ChatState): string)` | The question asked in the conversation. `label` stays the short name on the review screen; `ui()['message']` uses `question()` and falls back to `label`. |
| `editable(bool)` | Non-editable steps cannot be targeted by edit turns, corrections or go-back; review items expose `editable`. |
| `allowsEdits()` | EDIT turns are accepted while this step is current (besides from the review/done screen). |
| `afterApply(fn(ChatState, $value))` | Runs after **any** commit of the step: direct answer, prefill, AI correction, interrupt. |
| `onChange(fn(ChatState, $old, $new))` | Runs only when an existing answer changes to a different value, after `invalidates` cleared dependents. |
| `resetsFlow()` | Changing an existing answer clears every other answer (the form restarts). |
| `confirmation()` | The answer only confirms earlier ones (review, consent). It is cleared automatically whenever another answer changes, an edit starts or a go-back happens, so it is asked again. |
| `revalidate(false)` | The invariant checker keeps the stored answer even if `validate` fails later. |
| `sensitive()` / `sensitiveUnless(fn(Turn): bool)` | Secrets: never written to the transcript (shows `Messages::secretEntered()`), never passed to the interpreter or change detector. `sensitiveUnless` marks an escape input (e.g. a "continue as buyer" button) as not secret. Stored secrets are also hidden from the state copy given to an interpreter. |
| `interpretable(false)` | The interpreter and change detector are never consulted on this step (verbatim free text). |
| `requiredMessage(string)` | Error for a skip on a required step (default: `Messages::required()`). |
| `echo(fn(Turn, ChatState): string)` | How the user's turn appears in the transcript. Default: choice → option label, list → labels joined with `, `, skip → `Messages::skipped()`, text as typed. |
| `acknowledge(fn($value, ChatState, bool $skipped): ?string)` | Text shown after the answer is accepted, before the next question. |
| `prefillUsing(fn($raw, ChatState): StepResult)` | How a value for this step found in another step's input is parsed. Default: AI suggestions go through the step's own `parse` (plus `validate`); app-supplied prefill goes through `validate`. |
| `section(string)` | Group on the review screen. |
| `with($key, $value)` / `attribute($key, $default)` | Free attribute bag for your own data. |

`StepResult::invalid($error, $hint)` and `unrecognised($error, $hint)` accept a hint (e.g. a partially picked multi-select). It is stored on the state (`ChatState::hint($step)`), so the step's `ui` closure can re-render with it, and cleared when the step is committed or cleared.

```php
StepDefinition::make('features', 'Features')
    ->ui(fn (ChatState $s) => ['type' => 'multi', 'options' => $opts, 'selected' => $s->hint('features')['selected'] ?? []])
    ->parse(fn (Turn $t) => count($t->list()) >= 2
        ? StepResult::ok($t->list())
        : StepResult::invalid('Pick at least two.', ['selected' => $t->list()]));
```

## Transcript and messages

The engine can write the conversation itself, not only a UI payload. Implement `Contracts\Transcript` (default `NullTranscript`) to persist it:

```php
interface Transcript {
    public function assistant(ChatSession $s, string $step, string $text, string $kind, array $ui = []): void; // question|error|info|ack|review|done|ended
    public function user(ChatSession $s, string $step, Turn $turn, string $echo): void;
    public function event(ChatSession $s, string $step, string $text): void; // internal log, never shown to the user
}
```

- `start()` writes the optional `Flow::greeting(string|Closure)`, then the first question.
- Every ask writes **one** assistant message: lead-ins (acknowledgement, AI reply, error, "going back") joined with a blank line, then the question. The current UI payload is passed along.
- Every turn writes the user echo first (sensitive values are masked).
- Events are written for edits, go-backs, corrections, invariant recovery, submission outcome and the turn limit.

`ArraySession` implements `Contracts\StoresMessages` and `ChatEndpoint` returns the messages written since a cursor (see [HTTP protocol](#http-protocol)), so the bundled widget shows acknowledgements, side answers and errors too.

### Messages (i18n)

Every user-facing string the engine writes comes from `Contracts\Messages`. `Support\EnglishMessages` is the default; extend it or implement the interface for another language or wording:

```php
final class FrenchMessages extends EnglishMessages
{
    public function required(): string { return 'Cette étape est obligatoire.'; }
    public function goingBack(string $current): string { return $current === '' ? "D'accord, modifions cela." : "D'accord, c'était « $current »."; }
}
$engine = new FlowEngine($flow, messages: new FrenchMessages());
```

The catalogue: `required`, `incomplete`, `fallback`, `skipped`, `secretEntered`, `turnLimitReached`, `goingBack`, `updated`, `submissionFailed`, `done`, `handoffSaved`, `handoffSkipped`, `handoffWithContact`, `reviewPrompt`, `reviewConfirmLabel`, `submitLabel`. With `ChatEndpoint` pass a factory that receives the widget locale: `messages: fn (?string $locale) => new FrenchMessages()`. While you keep the default English messages, the widget shows its own translated review/done texts.

## Review, confirmation and consent

`FlowEngine::review($state)` returns `{type: 'review', items | sections, confirmLabel}`; items are `{step, label, value, editable}`. When any applicable step has `section()`, you get `sections: [{title, items}]` in flow order (empty sections dropped). `Flow::reviewUsing(fn (array $review, ChatState $s): array)` lets you add read-only rows or notes.

A normal step may use the review as its UI and be a confirmation step answered with a choice:

```php
StepDefinition::make('review', 'Review')
    ->confirmation()->allowsEdits()
    ->ui(fn (ChatState $s) => $engine->review($s))
    ->parse(fn (Turn $t) => $t->value === 'confirm' ? StepResult::ok(true) : StepResult::unrecognised('Please confirm.'));
```

`Flow::submitStep('consent')` names the step whose answer is given by a `SUBMIT` turn with value `true` (anything else throws `InvalidTurnException`). Without `submitStep`, `SUBMIT` on the done screen works as in v1. EDIT turns are accepted only from the done screen or from steps flagged `allowsEdits()`; the target must be editable and applicable, or a confirmation step (e.g. going from consent back to the review). Editing clears confirmation answers and starts a new epoch.

## Go back and change detection

`Contracts\ChangeDetector::detect($text, $currentStep, $state): ?string` is an optional, deterministic hook ("actually it's a Toyota", "go back") that returns the step to reopen; it needs no AI and `Flow::revisitable($state, $current)` lists valid targets. `FlowEngine::goBack($session, $state, $from, $target, $leadIns, $source)` clears confirmation answers and hints, starts a new epoch, reopens the target (lead-in `Messages::goingBack(...)`) and fires `ConversationListener::wentBack()`. **Later answers stay**; only `invalidates` dependents are cleared if the new value differs.

## Hand-off interrupt

For a side question nobody can answer ("can you call me?"), register an out-of-band step and a policy:

```php
$flow->interrupt(Steps::phone('callback', 'Your number?')->optional()->afterApply(fn (ChatState $s, $v) => $s->setMeta('callback', $v)));

final class Callback implements HandoffPolicy {
    public function decide(ChatSession $s, ChatState $st, string $step, array $question): Handoff {
        return Handoff::interrupt('callback');   // or Handoff::message('We will get back to you.')
    }
}
```

Interrupt steps are never walked by `nextStep()`, are not in progress or the review, and their value goes to `afterApply`/`acknowledge` but is **not stored as an answer** (unless `->storesAnswer()`). Skipping an optional interrupt acknowledges with `Messages::handoffSkipped()`; after it the flow resumes with the step re-asked. The default policy shows `Messages::fallback()` and re-asks.

## Prefill rules

Values for other steps (a `StepResult::ok($v, ['model' => 'A4'])` prefill, or AI `answers`) are applied in **flow order** (so `make` is stored before `model` is parsed), only to steps that exist, apply on the current branch, are still unanswered, and pass `prefillUsing`. An existing answer is never overwritten. A prefill that fails `prefillUsing` with a hint stores the hint for that step.

## Invariant checker

On by default (`checkInvariants: false` disables it). After every turn it drops answers of steps that no longer apply, drops stored answers that now fail `validate` (except confirmation steps and `revalidate(false)`), detects a step asked twice in one epoch for a non-repeatable reason (`AskReason::REPEATABLE` lists the repeatable ones), and moves the session to `nextStep()` (asking with reason `recovered`) if it drifted. Violations go to `ConversationListener::invariantViolated()` and a transcript event.

## Deferred (two-phase) completion

Real apps send the result to a CRM *outside* the DB transaction and must survive crashes and retries:

```php
$flow->submitStep('consent')->deferCompletion();          // session implements Contracts\DeferredCompletion

$result = $engine->handleTurn($session, $turn);           // valid SUBMIT => $result->isSubmitting(); onComplete NOT fired
// ... in your own job/transaction: take your lock, send to the CRM ...
$engine->completeSubmission($session, $ok, $errorOrNull); // ok: stores consent, completes, fires onComplete once
                                                          // failure: asks the consent step again with a retry label
```

A second `SUBMIT` while submitting is a no-op, and completing twice fires `onComplete` once. Recommended pattern: `markSubmitting()` persists a timestamp, your worker takes a lock keyed by the session, treats a `submitting` session older than N minutes as stale and retries `completeSubmission()`. `Flow::uiUsing(fn (array $ui, ChatSession $s, ChatState $st): array)` decorates every UI payload (a spinner, a retry label). The UI of the submit step already carries `submitLabel` (retry-aware) and `submitting`.

## Turn limit

At `maxTurns` the engine writes `Messages::turnLimitReached()` **once**, calls `ConversationListener::turnLimitReached($session)` (mark the session abandoned if you like) and returns `TurnResult::ended()`. `ui()` returns `{type: 'ended', text, step}`; the widget renders it with a restart button.

## Listener

Extend `ConversationListener` (every method is a no-op) and override what you need: `answered`, `interpreted`, `wentBack`, `invalid`, `turnLimitReached`, `invariantViolated`, `submitted`, `completed`, `submissionFailed`.

## Wiring

```php
$engine = new FlowEngine(
    $flow,
    interpreter: $interpreter,              // ConversationInterpreter, or a v1 TurnInterpreter (adapted)
    transcript: $transcript,
    messages: $messages,
    changeDetector: $detector,
    handoff: $handoffPolicy,
    listener: $listener,
    interpretationPolicy: $policy,
    maxTurns: 200,
    checkInvariants: true,
);
```

`ChatEndpoint` takes the same optional arguments. With Laravel, set `messages`, `interpreter`, `change_detector`, `handoff`, `interpretation_policy` and `listener` (class names resolved through the container) in `config/stepchat.php`. `handleTurn()` returns a `TurnResult` with status `continue`, `invalid`, `submitting`, `completed` or `ended`.

### UI payload

`FlowEngine::ui($session)` returns what a client should render. The bundled widget renders all of these; you can render them yourself in any front-end:

| `type` | Fields |
|---|---|
| `text` | `message`, `inputType` (`text`/`email`/`tel`/`number`), `placeholder`, `maxLength`, `optional` |
| `textarea` | same as `text` |
| `choice` | `message`, `options: [{value, label}]`, `optional` |
| `multi` | `message`, `options`, `min`, `max` |
| `review` | `items: [{step, label, value, editable}]` or `sections: [{title, items}]`, `confirmLabel`: send a `submit` turn (done screen) or a `confirm` choice (when used as a step), or `edit` turns |
| `consent` | whatever your step's `ui` returns; on the `submitStep` the payload also carries `submitLabel` and `submitting` |
| `done` | the flow is finished |
| `ended` | `text`: the turn limit was reached |

## Plugging in an AI model (optional)

Implement `ConversationInterpreter` to let a model help with messy free text. The AI never decides the next step, and **every value it returns goes through the target step's own deterministic parse** (or `prefillUsing`) before it is stored: AI output is never stored raw.

```php
use FaridM\StepChat\Contracts\ChatSession;
use FaridM\StepChat\Contracts\ConversationInterpreter;

final class LlmInterpreter implements ConversationInterpreter
{
    public function interpret(ChatSession $s, ChatState $state, string $step, string $text): Interpretation
    {
        $r = my_llm_extract($step, $text);   // any provider, plain HTTP
        if ($r === null) {
            return Interpretation::fail();
        }

        return Interpretation::of([
            'answers' => ['fuel' => $r['fuel'], 'year' => $r['year']],   // current or LATER steps (raw strings)
            'corrections' => ['make' => 'BMW'],                          // new values for EARLIER answers
            'revisit' => null,                                           // earlier step the user wants to change, no usable value
            'reply' => 'Got it.',                                        // short lead-in
            'question' => ['text' => 'Is it free?', 'answerable' => true, 'answer' => 'Yes.', 'topic' => 'price'],
            'notes' => ['requests' => ['sunroof']],                      // free app data, passed to the listener
            'intent' => Interpretation::ANSWER,
        ]);
    }
}
```

**When it is consulted:** only on `TEXT` turns, only if the step is `interpretable` and not `sensitive`, and only if an interpreter is configured, and then only when the deterministic parse is `unrecognised`, when an `InterpretationPolicy` says so (default: never; use it for "long text on a choice step may carry more answers"), or when the `ChangeDetector` suspects a change.

**How it is applied, in order:**

1. `answers[current]` runs through the current step's parse as a synthetic text turn, and is used **only if the deterministic parse failed** and no change was detected (a successful deterministic parse always wins). A failed parse with a hint keeps the hint.
2. `ConversationListener::interpreted()` receives the interpretation (absorb `notes` here).
3. `corrections` are parsed (via `prefillUsing`) for earlier, answered, applicable, editable, non-sensitive steps; changes are committed (`invalidates`, `onChange`, `afterApply` run), confirmation steps are cleared, the epoch is bumped, and the lead-in is `Messages::updated(labels)`. If the current answer was not usable (or a change was detected, or the step no longer applies) the flow continues from `nextStep()`.
4. `revisit` (or the detector's target, or corrections that could not be applied) goes back; later answers stay.
5. Still invalid: a side question is answered, or handed off ([Hand-off](#hand-off-interrupt)), and the step re-asked; otherwise the invalid attempt is recorded and the step re-asked with the error (`Messages::fallback()` if the AI call failed).
6. Valid: the answer is committed, later steps are prefilled from the parse result **and** `answers` (see [Prefill rules](#prefill-rules)), and a side question asked next to a valid answer is answered as a lead-in to the next question.

The interpreter and detector receive a copy of the state without sensitive answers. A **v1 `TurnInterpreter` still works**: it is wrapped by `Support\LegacyInterpreterAdapter` (value → `answers[current]`, prefill → `answers`). Without an interpreter the package makes zero network calls and costs nothing to run.

## Laravel

Everything is opt-in; the package works in Laravel 8 to 12 through auto-discovery.

```bash
php artisan vendor:publish --tag=stepchat-config   # config/stepchat.php
php artisan vendor:publish --tag=stepchat-assets   # public/vendor/stepchat/stepchat-widget.js
php artisan stepchat:install                                      # both of the above + a sample flow
```

Without publishing the assets, `@stepChatWidget` loads the script from the jsDelivr CDN. Conversations are stored in your cache (`cache_store`, `session_ttl` in the config), so they survive page reloads on any cache driver.

### Persisting conversations in your database

To keep sessions in Eloquent instead (for your own back-office, analytics or hand-over to a human), use the trait:

```php
use FaridM\StepChat\Laravel\HasChatState;

class ChatSession extends Model
{
    use HasChatState;

    protected $casts = ['chat_state' => 'array', 'completed_at' => 'datetime'];
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

The model then satisfies `Contracts\ChatSession` and can be passed straight into `FlowEngine`.

## HTTP protocol

The widget and `ChatEndpoint` speak a tiny JSON protocol, so you can also build your own client (React Native, Flutter, ...).

`POST` JSON to the endpoint:

```json
{ "flow": "lead", "locale": "ar", "session": "<id or null>", "cursor": 0, "action": "turn",
  "turn": { "step": "email", "type": "text", "value": "a@b.com" } }
```

| `action` | Meaning |
|---|---|
| `start` | Begin, or resume if `session` is known. |
| `state` | Current state (used after a language change). |
| `restart` | Throw the session away and begin again. |
| `turn` | Send an answer. `turn.type`: `text`, `choice`, `list`, `skip`, `edit`, `submit`. |

Response:

```json
{ "session": "…", "status": "continue | invalid | submitting | completed | ended", "step": "plan",
  "ui": { "type": "choice", "message": "Which plan?", "options": [{ "value": "pro", "label": "Pro" }] },
  "progress": { "current": 3, "total": 5 },
  "summary": [{ "step": "name", "label": "What is your name?", "value": "Farid" }],
  "error": null,
  "messages": [{ "id": 4, "role": "assistant", "kind": "question", "step": "plan", "text": "Which plan?" }],
  "cursor": 5 }
```

`messages` are the conversation messages written since the request's `cursor` (send back the returned `cursor`; `0` replays everything, which is what a reloaded widget does). Roles are `user` and `assistant`; kinds include `question`, `error`, `info`, `ack`, `review`, `done`, `ended`. Internal events are never sent.

HTTP codes: `200` ok (including validation errors, in `status`/`error`), `400` malformed request, `404` unknown flow, `409` stale client (the body carries the current state to re-sync).

Session ids are 128-bit random values stored server-side; the browser keeps its id in `localStorage` so a reload continues where the visitor left off.

## Testing

```bash
composer install
composer test        # PHPUnit
composer analyse     # PHPStan level 6
composer format      # PHP-CS-Fixer
```

Because flows are plain PHP, you can unit-test an entire conversation with `ArraySession` and no mocks:

```php
public function test_rejects_a_bad_email(): void
{
    $engine = new FlowEngine(Flow::make()->add(Steps::email('email', 'Your email?')));
    $session = new ArraySession();
    $engine->start($session);

    $result = $engine->handleTurn($session, new Turn('email', Turn::TEXT, 'bad'));

    $this->assertTrue($result->isInvalid());
    $this->assertSame('email', $session->currentStep());   // did not advance
}
```

## Contributing

Issues and pull requests are welcome; see [CONTRIBUTING.md](CONTRIBUTING.md). Please report security issues privately as described in [SECURITY.md](SECURITY.md). Release notes are in [CHANGELOG.md](CHANGELOG.md).

## License

MIT © Farid Masoum. See [LICENSE](LICENSE).
