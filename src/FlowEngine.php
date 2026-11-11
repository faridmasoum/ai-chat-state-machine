<?php

namespace FaridM\StepChat;

use FaridM\StepChat\Contracts\ChangeDetector;
use FaridM\StepChat\Contracts\ChatSession;
use FaridM\StepChat\Contracts\ConversationInterpreter;
use FaridM\StepChat\Contracts\DeferredCompletion;
use FaridM\StepChat\Contracts\HandoffPolicy;
use FaridM\StepChat\Contracts\InterpretationPolicy;
use FaridM\StepChat\Contracts\Messages;
use FaridM\StepChat\Contracts\Transcript;
use FaridM\StepChat\Contracts\TurnInterpreter;
use FaridM\StepChat\Engine\DefaultEcho;
use FaridM\StepChat\Engine\InvariantChecker;
use FaridM\StepChat\Exceptions\InvalidTurnException;
use FaridM\StepChat\Exceptions\StepMismatchException;
use FaridM\StepChat\Support\DefaultHandoffPolicy;
use FaridM\StepChat\Support\EnglishMessages;
use FaridM\StepChat\Support\LegacyInterpreterAdapter;
use FaridM\StepChat\Support\NeverInterpretPolicy;
use FaridM\StepChat\Support\NullTranscript;
use InvalidArgumentException;
use LogicException;

/**
 * Deterministic, server-controlled conversation flow.
 *
 * The engine (never the AI) decides which step comes next and validates
 * every answer against the step's own rules. A step is asked once, in
 * order; it is asked again only after an invalid answer, an edit, a
 * go-back or an interrupt.
 *
 * An interpreter is entirely optional. When given, it is consulted only on
 * free-text turns, and every value it suggests is run through the target
 * step's own parse before anything is stored: a successful deterministic
 * parse always wins and AI output is never stored raw.
 */
final class FlowEngine
{
    public const DONE = 'done';

    /** @var Flow */
    private $flow;

    /** @var ConversationInterpreter|null */
    private $interpreter;

    /** @var int */
    private $maxTurns;

    /** @var Transcript */
    private $transcript;

    /** @var Messages */
    private $messages;

    /** @var ChangeDetector|null */
    private $changeDetector;

    /** @var HandoffPolicy */
    private $handoff;

    /** @var ConversationListener */
    private $listener;

    /** @var InterpretationPolicy */
    private $policy;

    /** @var bool */
    private $checkInvariants;

    /** @var InvariantChecker */
    private $invariants;

    /** @var bool */
    private $deterministicCorrections;

    /** @var list<array{0: string, 1: string}> lead-ins (text, kind) waiting for the next ask */
    private $leadIns = [];

    /**
     * @param ConversationInterpreter|TurnInterpreter|null $interpreter a v1 TurnInterpreter is wrapped automatically
     */
    public function __construct(
        Flow $flow,
        $interpreter = null,
        int $maxTurns = 200,
        ?Transcript $transcript = null,
        ?Messages $messages = null,
        ?ChangeDetector $changeDetector = null,
        ?HandoffPolicy $handoff = null,
        ?ConversationListener $listener = null,
        ?InterpretationPolicy $interpretationPolicy = null,
        bool $checkInvariants = true,
        bool $deterministicCorrections = false
    ) {
        if ($interpreter instanceof TurnInterpreter) {
            $interpreter = new LegacyInterpreterAdapter($interpreter);
        } elseif ($interpreter !== null && !$interpreter instanceof ConversationInterpreter) {
            throw new InvalidArgumentException('The interpreter must implement ConversationInterpreter or TurnInterpreter.');
        }

        $this->flow = $flow;
        $this->interpreter = $interpreter;
        $this->maxTurns = $maxTurns;
        $this->transcript = $transcript ?? new NullTranscript();
        $this->messages = $messages ?? new EnglishMessages();
        $this->changeDetector = $changeDetector;
        $this->handoff = $handoff ?? new DefaultHandoffPolicy($this->messages);
        $this->listener = $listener ?? new ConversationListener();
        $this->policy = $interpretationPolicy ?? new NeverInterpretPolicy();
        $this->checkInvariants = $checkInvariants;
        $this->invariants = new InvariantChecker();
        $this->deterministicCorrections = $deterministicCorrections;
    }

    public function flow(): Flow
    {
        return $this->flow;
    }

    /**
     * Point a fresh session at its first applicable step.
     */
    public function start(ChatSession $session): void
    {
        $state = new ChatState();
        $this->leadIns = [];
        $first = $this->nextStep($state);
        $session->setCurrentStep($first);

        $greeting = $this->flow->greetingText($state);
        if ($greeting !== '') {
            $this->transcript->assistant($session, $first, $greeting, 'info');
        }

        $this->ask($session, $state, $first, AskReason::NEXT);
        $session->putChatState($state);
    }

    /**
     * Apply one turn. Throws StepMismatchException if the turn targets a
     * step other than the session's current one (stale client, replay),
     * and InvalidTurnException if the turn is not valid here.
     */
    public function handleTurn(ChatSession $session, Turn $turn): TurnResult
    {
        if ($session->isCompleted()) {
            return TurnResult::completed();
        }

        if ($turn->step !== $session->currentStep()) {
            throw new StepMismatchException(sprintf(
                'Turn targets step "%s" but session is on "%s".',
                $turn->step,
                $session->currentStep()
            ));
        }

        if ($session instanceof DeferredCompletion && $session->isSubmitting()) {
            return TurnResult::submitting();
        }

        $state = $session->chatState();
        $this->leadIns = [];

        if ($state->ended() || $session->turnCount() >= $this->maxTurns) {
            if (!$state->ended()) {
                $state->markEnded();
                $this->transcript->assistant($session, $turn->step, $this->messages->turnLimitReached(), 'ended');
                $this->transcript->event($session, $turn->step, 'turn limit reached');
                $this->listener->turnLimitReached($session);
                $session->putChatState($state);
            }

            return TurnResult::ended();
        }

        $session->incrementTurnCount();
        $askMark = $state->askTotal();

        if ($state->isInterrupted()) {
            $result = $this->handleInterruptAnswer($session, $state, $turn);
        } elseif ($turn->is(Turn::EDIT)) {
            $result = $this->handleEdit($session, $state, $turn);
        } elseif ($turn->is(Turn::SUBMIT)) {
            $result = $this->handleSubmit($session, $state, $turn);
        } else {
            $result = $this->handleAnswer($session, $state, $turn);
        }

        if ($this->checkInvariants && !$result->isCompleted()) {
            $this->enforceInvariants($session, $state, $askMark);
        }

        $session->putChatState($state);

        return $result;
    }

    /**
     * The next step key to ask, or self::DONE when every applicable step is satisfied.
     */
    public function nextStep(ChatState $state): string
    {
        if ($state->isInterrupted()) {
            return (string) $state->interruptStep();
        }

        foreach ($this->flow->steps() as $key => $step) {
            if (!$step->stepApplies($state)) {
                continue;
            }
            if ($state->editing() === $key || !$step->isSatisfied($state)) {
                return $key;
            }
        }

        return self::DONE;
    }

    /**
     * The UI payload the client should render for the session's current step.
     *
     * @return array<string, mixed>
     */
    public function ui(ChatSession $session): array
    {
        return $this->uiFor($session, $session->chatState());
    }

    /**
     * Summary of the answers so far: {type: 'review', items|sections, confirmLabel}.
     * Items are {step, label, value, editable}. When any applicable step has
     * a section(), grouped as sections [{title, items}] in flow order.
     *
     * @return array<string, mixed>
     */
    public function review(ChatState $state): array
    {
        $items = [];
        $sectioned = false;
        foreach ($this->flow->steps() as $key => $step) {
            if (!$step->stepApplies($state)) {
                continue;
            }
            if ($step->section !== null) {
                $sectioned = true;
            }
            if ($step->confirmation || !$state->has($key)) {
                continue;
            }
            $items[] = [
                'step' => $key,
                'label' => $step->label,
                'value' => $step->stepDisplay($state),
                'editable' => $step->isEditable(),
                'section' => $step->section,
            ];
        }

        $review = ['type' => 'review', 'confirmLabel' => $this->messages->reviewConfirmLabel()];
        if ($sectioned) {
            $sections = [];
            foreach ($items as $item) {
                $title = (string) $item['section'];
                unset($item['section']);
                $sections[$title]['title'] = $title;
                $sections[$title]['items'][] = $item;
            }
            $review['sections'] = array_values($sections);
        } else {
            $review['items'] = array_map(static function (array $item): array {
                unset($item['section']);

                return $item;
            }, $items);
        }

        return $this->flow->applyReview($review, $state);
    }

    /**
     * Applicable steps (excluding "done") and the 1-based position of the current one.
     *
     * @return array{current: int, total: int}
     */
    public function progress(ChatSession $session): array
    {
        $state = $session->chatState();
        $keys = array_keys(array_filter($this->flow->steps(), static fn (StepDefinition $s) => $s->stepApplies($state)));
        $current = $session->currentStep() === $state->interruptStep() ? $state->resumeStep() : $session->currentStep();
        $index = array_search($current, $keys, true);

        return [
            'current' => $session->isCompleted() || $current === self::DONE ? count($keys) : ($index === false ? 1 : $index + 1),
            'total' => count($keys),
        ];
    }

    /**
     * Reopen an earlier step. Later answers stay; only `invalidates`
     * dependents are cleared if the new value differs.
     *
     * @param list<string> $leadIns extra lead-in texts shown before the question
     * @param string $source detector|ai|ai_correction|edit
     */
    public function goBack(ChatSession $session, ChatState $state, string $from, string $target, array $leadIns = [], string $source = 'detector'): TurnResult
    {
        $step = $this->flow->step($target);
        if (!$step) {
            throw new InvalidTurnException(sprintf('Cannot go back to unknown step "%s".', $target));
        }

        foreach ($leadIns as $text) {
            $this->leadIn($text, 'ack');
        }
        $display = $state->has($target) ? $step->stepDisplay($state) : '';

        $this->clearConfirmations($state);
        $state->clearHints();
        $state->bumpEpoch();
        $state->setEditing($target);
        $next = $this->nextStep($state);

        $this->leadIn($this->messages->goingBack($display, $source), 'info');
        $this->transcript->event($session, $from, sprintf('go back from "%s" to "%s" (source: %s)', $from, $target, $source));
        $this->listener->wentBack($session, $from, $target, $source);
        $this->ask($session, $state, $next, $source === 'edit' ? AskReason::EDIT : AskReason::GOBACK);

        return TurnResult::continue();
    }

    /**
     * Finish a deferred (two-phase) submission once the app has sent the
     * result. On success the flow completes; on failure the consent step is
     * asked again and offers a retry.
     */
    public function completeSubmission(ChatSession $session, bool $ok, ?string $error = null): TurnResult
    {
        if ($session->isCompleted()) {
            return TurnResult::completed();
        }
        $state = $session->chatState();
        $this->leadIns = [];
        $submitKey = $this->flow->submitStepKey();

        if ($ok) {
            $step = $submitKey !== null ? $this->flow->step($submitKey) : null;
            if ($step) {
                $this->commit($session, $state, $step, true);
            }
            $state->setEditing(null);
            $state->setSubmitFailed(false);
            $this->finish($session, $state);
            $session->putChatState($state);

            return TurnResult::completed();
        }

        if ($session instanceof DeferredCompletion) {
            $session->markSubmitFailed();
        }
        $state->setSubmitFailed(true);
        $this->transcript->event($session, $session->currentStep(), 'submission failed' . ($error !== null ? ': ' . $error : ''));
        $this->listener->submissionFailed($session, (string) $error);
        $message = $this->messages->submissionFailed();
        $this->leadIn($message, 'error');
        $this->ask($session, $state, $submitKey ?? self::DONE, AskReason::REASK);
        $session->putChatState($state);

        return TurnResult::invalid($message);
    }

    // ------------------------------------------------------------- turns

    private function handleAnswer(ChatSession $session, ChatState $state, Turn $turn): TurnResult
    {
        $key = $session->currentStep();
        $step = $this->flow->step($key);
        if (!$step) {
            throw new InvalidTurnException(sprintf('Unknown step "%s".', $key));
        }

        $this->echoTurn($session, $state, $turn, $step);

        $meta = $step->interpretMode() === StepDefinition::INTERPRET_META;
        $mayAsk = $turn->is(Turn::TEXT) && $step->isInterpretable() && !$step->isSensitiveTurn($turn);
        $parsed = $step->stepParse($turn, $state, $this->messages->required());

        // Deterministic change detection ("actually it's a Toyota", "go back"), then a
        // deterministic correction (no AI round-trip) when the target step can parse the text.
        $detected = $mayAsk ? $this->detectChange($state, $key, $turn) : null;
        if ($detected !== null && $this->deterministicCorrection($session, $state, $key, $detected, $turn->text())) {
            $this->ask($session, $state, $this->nextStep($state), AskReason::NEXT);

            return TurnResult::continue();
        }

        // Interpretation (optional).
        [$interp, $aiFailed] = $this->consult($session, $state, $key, $step, $turn, $parsed, $detected, $mayAsk);

        // a) the AI's value for the current step: only when the deterministic parse failed
        //    (never on a meta-only step: its value is always the user's own text).
        if (!$meta && $interp && !$parsed->ok && $detected === null && isset($interp->answers()[$key])) {
            $ai = $step->stepParseRaw($interp->answers()[$key], $state, $this->messages->required());
            if ($ai->ok) {
                $parsed = $ai;
            } elseif ($ai->hint !== []) {
                $parsed->hint = $ai->hint;
            }
        }

        // b) let the app absorb notes.
        if ($interp) {
            $this->listener->interpreted($session, $state, $interp);
        }

        // c) corrections of earlier answers.
        $failedCorrections = [];
        $applied = false;
        if ($interp && $interp->corrections() !== []) {
            [$labels, $failedCorrections] = $this->applyCorrections($session, $state, $key, $interp->corrections());
            if ($labels !== []) {
                $applied = true;
                $this->addReply($interp);
                $this->clearConfirmations($state);
                $state->bumpEpoch();
                $this->leadIn($this->messages->updated($labels), 'ack');
                if ($meta || !$parsed->ok || $detected !== null || !$step->stepApplies($state)) {
                    $this->ask($session, $state, $this->nextStep($state), AskReason::NEXT);

                    return TurnResult::continue();
                }
            }
        }

        // d) go back to an earlier step: the detector found a change, the current answer is unusable,
        //    or the interpretation is a correction/revisit that could not be applied (even if the
        //    current text also parsed: then it is NOT committed).
        $wantsRevisit = $interp !== null && !$applied
            && ($interp->intent() === Interpretation::CORRECTION || $interp->revisit() !== null);
        if ($detected !== null || !$parsed->ok || $wantsRevisit) {
            [$target, $source] = $this->revisitTarget($state, $key, $detected, $interp, $failedCorrections);
            if ($target !== null) {
                $this->addReply($interp);

                return $this->goBack($session, $state, $key, $target, [], $source);
            }
        }

        // e) still invalid, or (meta-only step) a side question: the text is not committed.
        $question = $interp ? $interp->question() : null;
        if (!$parsed->ok || ($meta && $question !== null)) {
            $state->setHint($key, $parsed->hint);
            $this->addReply($interp);
            if ($question !== null) {
                $interrupt = $this->sideQuestion($session, $state, $key, $question);
                if ($interrupt !== null) {
                    $state->startInterrupt($interrupt, $key);
                    $this->ask($session, $state, $interrupt, AskReason::INTERRUPT);
                } else {
                    $this->ask($session, $state, $key, AskReason::REASK);
                }

                return TurnResult::continue();
            }

            return $this->rejected($session, $state, $key, $step, $turn, $parsed, $interp, $aiFailed);
        }

        // f) valid.
        $this->addReply($interp);
        $this->commit($session, $state, $step, $parsed->value);
        $this->leadInText($parsed->skipAck ?? $step->acknowledgeText($parsed->value, $state, $turn->is(Turn::SKIP) || $parsed->skipped), 'ack');

        $this->applyPrefill($session, $state, $key, $parsed->prefill, $interp ? $interp->answers() : []);

        $next = $this->nextStep($state);
        if ($question !== null) {
            $interrupt = $this->sideQuestion($session, $state, $key, $question);
            if ($interrupt !== null) {
                $state->startInterrupt($interrupt, $next);
                $this->ask($session, $state, $interrupt, AskReason::INTERRUPT);

                return TurnResult::continue();
            }
        }

        $this->ask($session, $state, $next, AskReason::NEXT);

        return TurnResult::continue();
    }

    private function handleEdit(ChatSession $session, ChatState $state, Turn $turn): TurnResult
    {
        $current = $session->currentStep();
        $currentStep = $this->flow->step($current);
        if ($current !== self::DONE && !($currentStep && $currentStep->allowsEdits)) {
            throw new InvalidTurnException('Edits are not allowed from this step.');
        }

        $target = $turn->editTarget();
        $step = $this->flow->step($target);
        if (!$step) {
            throw new InvalidTurnException(sprintf('Cannot edit unknown step "%s".', $target));
        }
        if (!$step->stepApplies($state) || !($step->isEditable() || $step->confirmation)) {
            throw new InvalidTurnException(sprintf('Step "%s" cannot be edited.', $target));
        }

        $this->transcript->user($session, $current, $turn, $step->label);

        return $this->goBack($session, $state, $current, $target, [], 'edit');
    }

    private function handleSubmit(ChatSession $session, ChatState $state, Turn $turn): TurnResult
    {
        $submitKey = $this->flow->submitStepKey();
        $current = $session->currentStep();

        if ($submitKey !== null) {
            if ($current !== $submitKey || $turn->value !== true) {
                throw new InvalidTurnException('Submit is only valid on the submit step with a true value.');
            }
        } elseif ($current !== self::DONE) {
            throw new InvalidTurnException('Submit is only valid when the flow is done.');
        }

        $this->transcript->user($session, $current, $turn, $this->messages->submitLabel($state, $state->submitFailed()));

        if ($this->nextStep($state) !== ($submitKey ?? self::DONE)) {
            $this->leadIn($this->messages->incomplete(), 'error');
            $this->ask($session, $state, $this->nextStep($state), AskReason::REASK);

            return TurnResult::invalid($this->messages->incomplete());
        }

        $this->listener->submitted($session);

        if ($this->flow->defersCompletion()) {
            if (!$session instanceof DeferredCompletion) {
                throw new LogicException('Flow::deferCompletion() needs a session that implements DeferredCompletion.');
            }
            $state->setSubmitFailed(false);
            $session->markSubmitting();
            $this->transcript->event($session, $current, 'submission started');

            return TurnResult::submitting();
        }

        $step = $submitKey !== null ? $this->flow->step($submitKey) : null;
        if ($step) {
            $this->commit($session, $state, $step, true);
        }
        $state->setEditing(null);
        $this->finish($session, $state);

        return TurnResult::completed();
    }

    private function handleInterruptAnswer(ChatSession $session, ChatState $state, Turn $turn): TurnResult
    {
        if ($turn->is(Turn::EDIT) || $turn->is(Turn::SUBMIT)) {
            throw new InvalidTurnException('That action is not valid while a hand-off question is open.');
        }

        $key = (string) $state->interruptStep();
        $step = $this->flow->anyStep($key);
        if (!$step) {
            throw new InvalidTurnException(sprintf('Unknown interrupt step "%s".', $key));
        }
        $this->echoTurn($session, $state, $turn, $step);

        // Free text on an interrupt goes through the same escalation as a normal step.
        $meta = $step->interpretMode() === StepDefinition::INTERPRET_META;
        $mayAsk = $turn->is(Turn::TEXT) && $step->isInterpretable() && !$step->isSensitiveTurn($turn);
        $parsed = $step->stepParse($turn, $state, $this->messages->required());

        $detected = $mayAsk ? $this->detectChange($state, $key, $turn) : null;
        if ($detected !== null && $this->deterministicCorrection($session, $state, $key, $detected, $turn->text())) {
            $state->endInterrupt();
            $this->ask($session, $state, $this->nextStep($state), AskReason::NEXT);

            return TurnResult::continue();
        }

        [$interp, $aiFailed] = $this->consult($session, $state, $key, $step, $turn, $parsed, $detected, $mayAsk);

        if (!$meta && $interp && !$parsed->ok && $detected === null && isset($interp->answers()[$key])) {
            $ai = $step->stepParseRaw($interp->answers()[$key], $state, $this->messages->required());
            if ($ai->ok) {
                $parsed = $ai;
            } elseif ($ai->hint !== []) {
                $parsed->hint = $ai->hint;
            }
        }
        if ($interp) {
            $this->listener->interpreted($session, $state, $interp);
        }

        // A correction ends the interrupt (the flow continues from the next step) ...
        $failedCorrections = [];
        $applied = false;
        if ($interp && $interp->corrections() !== []) {
            [$labels, $failedCorrections] = $this->applyCorrections($session, $state, $key, $interp->corrections());
            if ($labels !== []) {
                $applied = true;
                $this->addReply($interp);
                $this->clearConfirmations($state);
                $state->bumpEpoch();
                $this->leadIn($this->messages->updated($labels), 'ack');
                if ($meta || !$parsed->ok || $detected !== null) {
                    $state->endInterrupt();
                    $this->ask($session, $state, $this->nextStep($state), AskReason::NEXT);

                    return TurnResult::continue();
                }
            }
        }

        // ... and so does a revisit: the interrupt ends, then the flow goes back.
        $wantsRevisit = $interp !== null && !$applied
            && ($interp->intent() === Interpretation::CORRECTION || $interp->revisit() !== null);
        if ($detected !== null || !$parsed->ok || $wantsRevisit) {
            [$target, $source] = $this->revisitTarget($state, $key, $detected, $interp, $failedCorrections);
            if ($target !== null) {
                $this->addReply($interp);
                $state->endInterrupt();

                return $this->goBack($session, $state, $key, $target, [], $source);
            }
        }

        // A side question is answered (never handed off again) and the interrupt question asked again.
        $question = $interp ? $interp->question() : null;
        $answer = $question !== null && $question['answerable'] && $question['answer'] !== null && $question['answer'] !== ''
            ? $question['answer']
            : null;
        if (!$parsed->ok || ($meta && $question !== null)) {
            $state->setHint($key, $parsed->hint);
            $this->addReply($interp);
            if ($question !== null) {
                $this->leadInText($answer, 'ack');
                $this->ask($session, $state, $key, AskReason::REASK);

                return TurnResult::continue();
            }

            return $this->rejected($session, $state, $key, $step, $turn, $parsed, $interp, $aiFailed);
        }

        $this->addReply($interp);
        $skipped = $turn->is(Turn::SKIP) || $parsed->skipped;
        if ($step->storesAnswer) {
            $this->commit($session, $state, $step, $parsed->value);
        } else {
            $state->clearHint($key);
            $state->clearInvalidAttempts($key);
            if ($step->afterApply) {
                ($step->afterApply)($state, $parsed->value);
            }
            $this->listener->answered($session, $state, $key, $parsed->value);
        }

        $ack = $parsed->skipAck ?? $step->acknowledgeText($parsed->value, $state, $skipped);
        if ($ack === null) {
            if ($skipped) {
                $ack = $this->messages->handoffSkipped();
            } elseif (is_string($parsed->value) && $parsed->value !== '') {
                $ack = $this->messages->handoffWithContact($parsed->value);
            } else {
                $ack = $this->messages->handoffSaved();
            }
        }
        $this->leadIn($ack, 'ack');
        $this->leadInText($answer, 'ack');

        $resume = (string) $state->resumeStep();
        $state->endInterrupt();
        $next = $this->nextStep($state);
        $reason = $next === $resume && $state->askedInEpoch($next) ? AskReason::REASK : AskReason::NEXT;
        $this->ask($session, $state, $next, $reason);

        return TurnResult::continue();
    }

    // ----------------------------------------------------------- helpers

    /**
     * The step the ChangeDetector wants to reopen, if it is a valid target.
     */
    private function detectChange(ChatState $state, string $key, Turn $turn): ?string
    {
        if (!$this->changeDetector) {
            return null;
        }
        $target = $this->changeDetector->detect($turn->text(), $key, $this->stateForAi($state));

        return $target !== null && in_array($target, $this->flow->revisitable($state, $key), true) ? $target : null;
    }

    /**
     * Opt-in speed path: parse the user's text with the detected TARGET step (on a
     * copy of the state). If it yields a valid, different value, commit it as a
     * correction without consulting the interpreter. Returns true when it did.
     */
    private function deterministicCorrection(ChatSession $session, ChatState $state, string $current, string $target, string $text): bool
    {
        if (!$this->deterministicCorrections) {
            return false;
        }
        $step = $this->flow->step($target);
        if (!$step || $step->sensitive) {
            return false;
        }
        $result = $step->stepParse(new Turn($target, Turn::TEXT, $text), ChatState::fromArray($state->toArray()), $this->messages->required());
        if (!$result->ok || $result->skipped || ($step->validate)($result->value, $state) !== null) {
            return false;
        }
        if (ChatState::same($state->answer($target), $result->value)) {
            return false;
        }

        $this->commitCorrection($session, $state, $current, $step, $result->value, 'detector');
        $this->clearConfirmations($state);
        $state->bumpEpoch();
        $this->leadIn($this->messages->updated([$step->label]), 'ack');

        return true;
    }

    /**
     * Consult the interpreter when the escalation rules say so. Returns [interpretation, aiFailed].
     * On a meta-only step an unrecognised parse does not trigger it (the policy or a detected change does).
     *
     * @return array{0: Interpretation|null, 1: bool}
     */
    private function consult(ChatSession $session, ChatState $state, string $key, StepDefinition $step, Turn $turn, StepResult $parsed, ?string $detected, bool $mayAsk): array
    {
        if (!$mayAsk || !$this->interpreter) {
            return [null, false];
        }
        $unrecognised = !$parsed->ok && $parsed->unrecognised && $step->interpretMode() === StepDefinition::INTERPRET_FULL;
        if (!$unrecognised && $detected === null && !$this->policy->shouldInterpret($session, $state, $key, $turn, $parsed)) {
            return [null, false];
        }

        $start = microtime(true);
        $candidate = $this->interpreter->interpret($session, $this->stateForAi($state), $key, $turn->text());
        $this->listener->interpretationTimed($session, $key, (int) round((microtime(true) - $start) * 1000));

        return $candidate->failed() ? [null, true] : [$candidate, false];
    }

    /**
     * Where a revisit should go: the detector's target, the interpretation's revisit, or the
     * first correction that could not be applied.
     *
     * @param list<string> $failedCorrections
     * @return array{0: string|null, 1: string} target and source (detector|ai|ai_correction)
     */
    private function revisitTarget(ChatState $state, string $key, ?string $detected, ?Interpretation $interp, array $failedCorrections): array
    {
        if ($detected !== null) {
            return [$detected, 'detector'];
        }
        if ($interp) {
            $revisit = $interp->revisit();
            if ($revisit !== null && in_array($revisit, $this->flow->revisitable($state, $key), true)) {
                return [$revisit, 'ai'];
            }
            if ($failedCorrections !== []) {
                return [$failedCorrections[0], 'ai_correction'];
            }
        }

        return [null, 'detector'];
    }

    /**
     * The answer was rejected and nothing else could be done: record the attempt (unless it was a
     * retry), tell the listener and ask the step again with the error.
     */
    private function rejected(ChatSession $session, ChatState $state, string $key, StepDefinition $step, Turn $turn, StepResult $parsed, ?Interpretation $interp, bool $aiFailed): TurnResult
    {
        $error = $aiFailed ? $this->messages->fallback() : (string) $parsed->error;
        if ($parsed->countsAttempt) {
            $state->recordInvalidAttempt($key);
        }
        $this->listener->invalid($session, $key, $error);

        if ($turn->is(Turn::TEXT) && !$step->isSensitiveTurn($turn)) {
            $reason = null;
            if ($step->interpretMode() === StepDefinition::INTERPRET_NONE) {
                $reason = 'not_interpretable';
            } elseif ($aiFailed) {
                $reason = 'ai_failed';
            } elseif ($interp !== null) {
                $reason = 'ai_unhelpful';
            }
            if ($reason !== null) {
                $this->listener->unclear($session, $key, $turn->text(), $reason);
            }
        }

        $this->leadIn($error, 'error');
        $this->ask($session, $state, $key, AskReason::INVALID);

        return TurnResult::invalid($error);
    }

    /**
     * Commit a correction of an earlier answer and make it observable (transcript event + listener).
     *
     * @param mixed $value
     */
    private function commitCorrection(ChatSession $session, ChatState $state, string $current, StepDefinition $step, $value, string $source): void
    {
        $old = $state->answer($step->key);
        $this->commit($session, $state, $step, $value);
        $this->transcript->event($session, $current, sprintf('correction "%s" (source: %s)', $step->key, $source));
        $this->listener->corrected($session, $step->key, $old, $value);
    }

    /**
     * Stores a validated value, clears what it invalidates and runs the
     * step's hooks (onChange, afterApply).
     *
     * @param mixed $value
     */
    private function commit(ChatSession $session, ChatState $state, StepDefinition $step, $value): void
    {
        $key = $step->key;
        $had = $state->has($key);
        $previous = $had ? $state->answer($key) : null;
        $changed = !ChatState::same($previous, $value);
        $differs = $had && $changed;

        $state->setAnswer($key, $value);
        $state->clearHint($key);
        $state->clearInvalidAttempts($key);

        if ($state->editing() === $key) {
            $state->setEditing(null);
            $state->bumpEpoch();
        }

        // Dependents and confirmations are only cleared when an EXISTING answer changes,
        // never on a step's first answer (same rule as onChange).
        if ($differs) {
            if ($step->resetsFlow) {
                $state->resetAnswers();
                $state->setAnswer($key, $value);
            } elseif ($step->invalidates) {
                $state->forget(...$step->invalidates);
            }
            if (!$step->confirmation && $this->clearConfirmations($state)) {
                $state->bumpEpoch();
            }
        }

        if ($differs && $step->onChange) {
            ($step->onChange)($state, $previous, $value);
        }
        if ($step->afterApply) {
            ($step->afterApply)($state, $value);
        }
        $this->listener->answered($session, $state, $key, $value);
    }

    /**
     * Forget every answered confirmation step. Returns true if any was cleared.
     */
    private function clearConfirmations(ChatState $state): bool
    {
        $cleared = false;
        foreach ($this->flow->steps() as $key => $step) {
            if ($step->confirmation && $state->has($key)) {
                $state->forget($key);
                $cleared = true;
            }
        }

        return $cleared;
    }

    /**
     * Commit corrections for earlier answers.
     *
     * @param array<string, mixed> $corrections
     * @return array{0: list<string>, 1: list<string>} labels of the corrected steps, keys that could not be applied
     */
    private function applyCorrections(ChatSession $session, ChatState $state, string $current, array $corrections): array
    {
        $labels = [];
        $failed = [];
        foreach ($this->flow->revisitable($state, $current) as $key) {
            if (!array_key_exists($key, $corrections)) {
                continue;
            }
            $step = $this->flow->step($key);
            if (!$step || $step->sensitive || $step->interpretMode() === StepDefinition::INTERPRET_META) {
                continue;
            }
            $result = $step->stepPrefill($corrections[$key], $state, true);
            if (!$result->ok) {
                $failed[] = $key;
                continue;
            }
            if (ChatState::same($state->answer($key), $result->value)) {
                continue;
            }
            $this->commitCorrection($session, $state, $current, $step, $result->value, 'ai_correction');
            $labels[] = $step->label;
        }

        return [$labels, $failed];
    }

    /**
     * Fill later steps from values found in other steps' input, in FLOW ORDER,
     * only for steps that apply, are unanswered and accept the value. Never overwrites.
     *
     * @param array<string, mixed> $prefill typed values from StepResult::ok()
     * @param array<string, mixed> $aiAnswers raw interpreter suggestions
     */
    private function applyPrefill(ChatSession $session, ChatState $state, string $current, array $prefill, array $aiAnswers): void
    {
        if ($prefill === [] && $aiAnswers === []) {
            return;
        }
        foreach ($this->flow->steps() as $key => $step) {
            if ($key === $current || $step->confirmation || $step->sensitive) {
                continue;
            }
            $fromAi = !array_key_exists($key, $prefill);
            if (!array_key_exists($key, $prefill) && !array_key_exists($key, $aiAnswers)) {
                continue;
            }
            if ($fromAi && $step->interpretMode() === StepDefinition::INTERPRET_META) {
                continue; // a meta-only step's value is always the user's own text, never the AI's
            }
            if (!$step->stepApplies($state) || $state->has($key)) {
                continue;
            }
            $result = $step->stepPrefill($fromAi ? $aiAnswers[$key] : $prefill[$key], $state, $fromAi);
            if ($result->ok) {
                $this->commit($session, $state, $step, $result->value);
            } elseif ($result->hint !== []) {
                $state->setHint($key, $result->hint);
            }
        }
    }

    /**
     * Answer or hand off a side question. Returns an interrupt step key, or
     * null when a lead-in text was added and the flow just carries on.
     *
     * @param array{text: string, answerable: bool, answer: ?string, topic: ?string} $question
     */
    private function sideQuestion(ChatSession $session, ChatState $state, string $step, array $question): ?string
    {
        if ($question['answerable'] && $question['answer'] !== null && $question['answer'] !== '') {
            $this->leadIn($question['answer'], 'ack');

            return null;
        }
        $decision = $this->handoff->decide($session, $state, $step, $question);
        if ($decision->isInterrupt()) {
            return $decision->interruptStep;
        }
        $this->leadIn($decision->text, 'info');

        return null;
    }

    private function addReply(?Interpretation $interp): void
    {
        if ($interp !== null && $interp->reply() !== '') {
            foreach ($this->leadIns as $existing) {
                if ($existing[0] === $interp->reply()) {
                    return;
                }
            }
            $this->leadIn($interp->reply(), 'ack');
        }
    }

    private function leadIn(string $text, string $kind): void
    {
        if ($text !== '') {
            $this->leadIns[] = [$text, $kind];
        }
    }

    private function leadInText(?string $text, string $kind): void
    {
        if ($text !== null) {
            $this->leadIn($text, $kind);
        }
    }

    /**
     * A copy of the state without sensitive answers, safe to hand to an
     * interpreter or change detector.
     */
    private function stateForAi(ChatState $state): ChatState
    {
        $copy = ChatState::fromArray($state->toArray());
        foreach ($this->flow->steps() as $key => $step) {
            if ($step->sensitive) {
                $copy->forget($key);
            }
        }

        return $copy;
    }

    private function echoTurn(ChatSession $session, ChatState $state, Turn $turn, StepDefinition $step): void
    {
        if ($step->isSensitiveTurn($turn)) {
            $echo = $this->messages->secretEntered();
        } elseif ($step->echo) {
            $echo = ($step->echo)($turn, $state);
        } else {
            $echo = DefaultEcho::echo($step, $turn, $state, $this->messages);
        }
        $this->transcript->user($session, $step->key, $turn, $echo);
    }

    /**
     * Point the session at $step and write ONE assistant message: the
     * pending lead-ins joined with a blank line, then the question.
     */
    private function ask(ChatSession $session, ChatState $state, string $step, string $reason): void
    {
        $session->setCurrentStep($step);

        if ($step === self::DONE) {
            $text = $this->messages->reviewPrompt();
            $kind = 'review';
        } else {
            $state->recordAsk($step, $reason);
            $def = $this->flow->anyStep($step);
            $text = $def ? $def->questionText($state) : '';
            $kind = 'question';
        }

        $this->flush($session, $state, $step, $text, $kind);
    }

    private function flush(ChatSession $session, ChatState $state, string $step, string $question, string $kind): void
    {
        $parts = [];
        foreach ($this->leadIns as [$text, $leadKind]) {
            $parts[] = $text;
            if ($leadKind === 'error') {
                $kind = 'error';
            }
        }
        if ($question !== '') {
            $parts[] = $question;
        }
        $this->leadIns = [];

        $this->transcript->assistant($session, $step, implode("\n\n", $parts), $kind, $this->uiFor($session, $state));
    }

    private function finish(ChatSession $session, ChatState $state): void
    {
        $session->markCompleted();
        $session->setCurrentStep(self::DONE);
        $this->transcript->event($session, self::DONE, 'completed');
        $this->flow->fireCompleted($state->answers(), $session);
        $this->listener->completed($session);
        $this->flush($session, $state, self::DONE, $this->messages->done($state), 'done');
    }

    private function enforceInvariants(ChatSession $session, ChatState $state, int $askMark): void
    {
        $violations = $this->invariants->repair($this->flow, $state);
        $violations = array_merge($violations, $this->invariants->duplicateAsks($state, $askMark));

        $next = $this->nextStep($state);
        $moved = $session->currentStep() !== $next;
        if ($moved) {
            $violations[] = sprintf('current step "%s" is not the next step "%s"', $session->currentStep(), $next);
        }
        if ($violations === []) {
            return;
        }

        $this->transcript->event($session, $session->currentStep(), 'invariant violated: ' . implode('; ', $violations));
        $this->listener->invariantViolated($session, $violations);

        if ($moved) {
            $this->leadIns = [];
            $this->ask($session, $state, $next, AskReason::RECOVERED);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function uiFor(ChatSession $session, ChatState $state): array
    {
        $key = $session->currentStep();
        $submitKey = $this->flow->submitStepKey();

        if ($session->isCompleted()) {
            $ui = ['type' => 'done', 'step' => self::DONE];
        } elseif ($state->ended() || $session->turnCount() >= $this->maxTurns) {
            $ui = ['type' => 'ended', 'text' => $this->messages->turnLimitReached(), 'step' => $key];
        } elseif ($key === self::DONE) {
            $ui = $this->review($state) + ['step' => self::DONE];
            $ui += $this->submitExtras($session, $state);
        } else {
            $step = $this->flow->anyStep($key);
            if (!$step) {
                $ui = ['type' => 'done', 'step' => self::DONE];
            } else {
                $ui = $step->stepUi($state) + [
                    'step' => $key,
                    'message' => $step->questionText($state),
                    'optional' => $step->isOptional($state),
                ];
                if ($key === $submitKey) {
                    $ui += $this->submitExtras($session, $state);
                }
            }
        }

        return $this->flow->decorateUi($ui, $session, $state);
    }

    /**
     * @return array<string, mixed>
     */
    private function submitExtras(ChatSession $session, ChatState $state): array
    {
        return [
            'submitLabel' => $this->messages->submitLabel($state, $state->submitFailed()),
            'submitting' => $session instanceof DeferredCompletion && $session->isSubmitting(),
        ];
    }
}
