# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/).

## [2.1.1] - 2026-11-11

### Fixed
- A deterministic correction (`deterministicCorrections`) no longer silently clears other answers. It is only applied when committing it would not clear anything: it is skipped when the target step has `resetsFlow`, or when any step in its `invalidates` list currently has an answer. It then falls back to the normal path (interpreter, else go back and ask), so the user re-picks the value and the old answers stay until then.

### Added
- `StepDefinition::confirmChanges(bool = true)`: a detected change to this step always takes the go-back path, never a deterministic correction. `StepDefinition::wouldClearOthers($state)` exposes the check.

## [2.1.0] - 2026-11-11

Backward compatible: every default equals the 2.0.1 behaviour.

### Added
- **Meta-only interpretation.** `StepDefinition::interpretable()` accepts a mode: `INTERPRET_FULL` (default, `true`), `INTERPRET_META`, `INTERPRET_NONE` (`false`). On a meta step the value is always the user's own text (`answers[current]`, AI prefill and AI corrections never touch it); applied corrections, a revisit, a detected change or a side question mean the text is not committed. The interpreter is only consulted there when the `InterpretationPolicy` says so or a change was detected. `StepDefinition::interpretMode()`.
- **Interrupt steps understand free text.** Typed text on an interrupt goes through the ChangeDetector and the interpreter (same rules, interpret mode and `sensitive` respected); a side question is answered and the interrupt asked again (never handed off again); a correction or revisit ends the interrupt.
- `StepResult::skip(?string $ack = null)`: a parse can decide the user declined; handled like a SKIP turn (invalid on a required step).
- `StepResult::retry($error, $hint = [])`: an invalid answer that is not counted as an attempt (the listener's `invalid()` still fires).
- **Deterministic corrections** (opt-in `deterministicCorrections` option on `FlowEngine` and `ChatEndpoint`, Laravel config `deterministic_corrections`): when the ChangeDetector names a target, the text is first parsed by the target step; a valid, different value is committed as a correction (transcript event source `detector`, `corrected()`, `Messages::updated()`) without calling the interpreter.
- `ConversationListener::unclear($session, $step, $text, $reason)` (`ai_failed`, `ai_unhelpful`, `not_interpretable`; never for sensitive steps) and `ConversationListener::interpretationTimed($session, $step, $ms)`.
- README: "Understanding every message" (the escalation order and how to use `InterpretationPolicy`).

### Changed
- The widget reports version 2.1.0.

## [2.0.1] - 2026-11-11

### Fixed
- An interpretation's `reply()` is now shown when the answer is still invalid or the user asked a side question (it was only shown when the answer was accepted, corrected or sent back). One message now reads: reply, then the side answer or the error, then the question. The reply is never shown twice.

## [2.0.0] - 2026-11-11

The engine now covers everything a production lead-chat app needs, as generic features. A flow that uses none of the new features behaves like v1, apart from the changes listed under UPGRADE.

### Added
- **Step options** on `StepDefinition`: `question`, `editable`, `allowsEdits`, `afterApply`, `onChange`, `resetsFlow`, `confirmation`, `revalidate`, `sensitive` / `sensitiveUnless`, `interpretable`, `requiredMessage`, `echo`, `acknowledge`, `prefillUsing`, `section`, `storesAnswer`, `with` / `attribute`.
- `StepResult::invalid()` / `unrecognised()` accept a **hint**, stored on the state (`ChatState::hint/setHint/clearHint`) so a step can re-render with it.
- `ChatState::recordAsk($step, $reason)` with `AskReason` constants; ask log entries are `{step, reason, epoch}`. `ChatState` format version 2 (v1 data still loads).
- **Transcript** contract (`assistant`, `user`, `event`), `NullTranscript`, `SessionTranscript`, `CompositeTranscript`, `Flow::greeting()`. One assistant message per ask, user echo on every turn.
- **Messages** catalogue (`Contracts\Messages`, `Support\EnglishMessages`) for every engine string.
- **Interpretation v2**: `ConversationInterpreter` with `answers`, `corrections`, `revisit`, `reply`, `question`, `notes`, `intent`; `InterpretationPolicy`; `LegacyInterpreterAdapter` for v1 `TurnInterpreter`s.
- **Review and consent**: public `FlowEngine::review()` (items or sections), `Flow::reviewUsing()`, `Flow::submitStep()`.
- **Go back**: `ChangeDetector`, `FlowEngine::goBack()`, `Flow::revisitable()`.
- **Invariant checker** (on by default, `checkInvariants: false` disables).
- **Hand-off**: `Flow::interrupt()`, `HandoffPolicy`, `Handoff`.
- **Deferred completion**: `Flow::deferCompletion()`, `DeferredCompletion`, `FlowEngine::completeSubmission()`, `TurnResult::submitting()`, `Flow::uiUsing()`.
- **Turn limit** ends the conversation (`TurnResult::ended()`, `{type: 'ended'}` UI).
- `ConversationListener` with no-op hooks, including `corrected($session, $step, $old, $new)`, which fires (together with a transcript event `correction "step" (source: ai_correction)`) for every AI correction that was committed.
- `FlowEngine` and `ChatEndpoint` take named optional arguments for all of the above; Laravel config keys `messages`, `interpreter`, `change_detector`, `handoff`, `interpretation_policy`, `listener`, `check_invariants`.
- `ArraySession` keeps a message list and implements `StoresMessages` and `DeferredCompletion`; `ChatEndpoint` returns `messages` and `cursor`.
- Widget renders the server's message stream (acknowledgements, side answers, errors), review sections, non-editable rows, consent/submitting and ended states.

### Changed
- `invalidates`, `resetsFlow` and the automatic clearing of confirmation steps fire only when an **existing** answer changes to a different value, never on a step's first answer (same rule as `onChange`). A prefilled value is no longer wiped when the step that `invalidates` it is answered for the first time.
- A correction/revisit interpretation (`intent: correction`, or a `revisit` / a failed correction that could not be applied) now goes back even when the current text also parses; the current answer is not committed.
- `ui()` reports `{type: 'ended'}` as soon as `turnCount() >= maxTurns` (the message and listener call still happen once, on the next turn attempt); `ChatEndpoint` then reports status `ended`.
- README screenshots retaken at 2x resolution with native names: an English name in the English one, an Arabic name in the Arabic one.
- Prefill from a parse result is applied in flow order, only to unanswered applicable steps, and never overwrites an answer (v1 set it unconditionally).
- Values from an interpreter are parsed by the step's own `parse` instead of being stored raw.
- `Interpretation` is immutable and uses getters.

### UPGRADE from 1.x
1. **Interpreters.** A v1 `TurnInterpreter` keeps working through an adapter, but its value is now run through the step's `parse` (an invalid value is rejected, not stored). `Interpretation` properties became methods (`$i->failed()`, `value()`, `prefill()`); `Interpretation::ok()` and `fail()` are unchanged. Prefer `ConversationInterpreter` for new code.
2. **Edits.** EDIT turns are accepted only from the done screen or from steps marked `allowsEdits()`; the target must be editable and applicable. v1 accepted them anywhere.
3. **Turn limit.** Reaching `maxTurns` returns `TurnResult::ended()` (HTTP status `ended`, UI `{type: 'ended'}`) instead of `invalid`.
4. **Invariants.** Answers of steps that no longer apply or no longer pass `validate` are dropped after each turn. Opt out per step with `revalidate(false)` or globally with `checkInvariants: false`.
5. **Prefill.** It no longer overwrites existing answers or fills steps that do not apply.
6. **Transcript and asks.** Invalid answers now record an ask (reason `invalid`); `ChatState::askLog()` entries have a `reason`. The `ArraySession` array format gained `messages`, `messageBase` and `submitting` (old data loads fine).
7. **Custom `ChatSession` implementations** need no change. To use two-phase completion also implement `Contracts\DeferredCompletion`; to return messages from `ChatEndpoint` use `ArraySession`.
8. **Widget.** Update `stepchat-widget.js` together with the PHP package. An older widget keeps working against a v2 server (it ignores `messages` and echoes locally), but it will not show acknowledgements, side answers or the `ended`/`submitting` states.
9. **Messages.** Hard-coded English engine strings now come from `Messages`; override them to localise. `Messages::goingBack()` receives the source (`detector`, `ai`, `ai_correction`, `edit`) as a second argument; return `''` to show no lead-in (e.g. for plain edits). Custom `Messages` implementations must add the parameter.

## [1.0.1] - 2026-11-11

### Changed
- **Renamed to StepChat** (the "AI" in the old name was misleading, since the AI model is optional). Composer package `faridm/stepchat`, namespace `FaridM\StepChat`, config `config/stepchat.php`, widget file `stepchat-widget.js` and global `StepChatWidget`, `@stepChatWidget` / `<x-stepchat-widget>`, `php artisan stepchat:install`, env `STEPCHAT_WIDGET`. GitHub repository is now `faridmasoum/stepchat`. The old package name `faridm/ai-chat-state-machine` is abandoned.
- Plain PHP example now localizes plan and topic labels (Persian, Arabic, English), so the README screenshots show fully English and fully Arabic conversations.

## [1.0.0] - 2026-11-10

### Added
- Deterministic step-based flow engine: `Flow`, `StepDefinition`, `FlowEngine`, `ChatState`, `Turn`, `TurnResult`.
- Step presets: `Steps::text`, `email`, `phone`, `number`, `choice`, `multiChoice`, `confirm`.
- Standard UI payload schema (`text`, `textarea`, `choice`, `multi`, `review`, `done`) with `message` and `optional`.
- Review screen with per-answer editing before final submit; `Flow::onComplete()` callback.
- Optional `TurnInterpreter` hook so an LLM can fill gaps the deterministic rules leave open.
- Framework-agnostic JSON protocol (`ChatEndpoint`), `FlowRegistry`, and session stores (native `$_SESSION`, in-memory, Laravel cache).
- Drop-in browser widget (`resources/dist/ai-chat-widget.js`): one `<script>` tag, Shadow DOM, dark mode, accessibility, resumable conversations.
- Built-in languages (en, fa, ar, he, ur, de, fr, es, tr), runtime language switching, automatic RTL for every right-to-left language, custom locales.
- Laravel integration: auto-discovered provider, opt-in widget route, `@aiChatWidget` directive, `<x-ai-chat-widget>` component, `FlowCompleted` event, `ai-chat:install` command, `HasChatState` Eloquent trait.
