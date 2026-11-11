# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/).

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
