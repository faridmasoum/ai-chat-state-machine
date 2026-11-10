<?php

namespace FaridM\AiChatStateMachine;

use FaridM\AiChatStateMachine\Contracts\ChatSession;
use FaridM\AiChatStateMachine\Contracts\TurnInterpreter;
use FaridM\AiChatStateMachine\Exceptions\InvalidTurnException;
use FaridM\AiChatStateMachine\Exceptions\StepMismatchException;

/**
 * Deterministic, server-controlled conversation flow.
 *
 * The engine (never the AI) decides which step comes next and validates
 * every answer against the step's own rules. A step is asked once, in
 * order; it is asked again only after an invalid answer, an edit, or an
 * interrupt (see ChatState::startInterrupt()).
 *
 * A TurnInterpreter is entirely optional. When given, it only gets a turn
 * at the input the deterministic `parse` closure could not classify, and
 * its answer is only trusted to fill gaps — it never overrides a
 * successful deterministic parse.
 */
final class FlowEngine
{
    public const DONE = 'done';

    /** @var Flow */
    private $flow;

    /** @var TurnInterpreter|null */
    private $interpreter;

    /** @var int */
    private $maxTurns;

    public function __construct(Flow $flow, ?TurnInterpreter $interpreter = null, int $maxTurns = 200)
    {
        $this->flow = $flow;
        $this->interpreter = $interpreter;
        $this->maxTurns = $maxTurns;
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
        $session->setCurrentStep($this->nextStep($state));
        $state->recordAsk($session->currentStep());
        $session->putChatState($state);
    }

    /**
     * Apply one turn. Throws StepMismatchException if the turn targets a
     * step other than the session's current one (stale client, replay),
     * and InvalidTurnException if the current step key is not in the flow.
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

        if ($session->turnCount() >= $this->maxTurns) {
            return TurnResult::invalid('This conversation has reached its turn limit.');
        }

        $session->incrementTurnCount();
        $state = $session->chatState();

        if ($state->isInterrupted()) {
            $result = $this->handleInterruptAnswer($session, $state, $turn);
        } elseif ($turn->is(Turn::EDIT)) {
            $result = $this->handleEdit($session, $state, $turn);
        } elseif ($turn->is(Turn::SUBMIT)) {
            $result = $this->handleSubmit($session, $state);
        } else {
            $result = $this->handleAnswer($session, $state, $turn);
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
        $state = $session->chatState();
        $key = $session->currentStep();

        if ($session->isCompleted()) {
            return ['type' => 'done', 'step' => self::DONE];
        }

        if ($key === self::DONE) {
            return $this->reviewUi($state);
        }

        $step = $this->flow->step($key);
        if (!$step) {
            return ['type' => 'done', 'step' => self::DONE];
        }

        return $step->stepUi($state) + [
            'step' => $key,
            'message' => $step->label,
            'optional' => $step->isOptional($state),
        ];
    }

    /**
     * Every applicable step answered: show a summary the user can edit or submit.
     *
     * @return array<string, mixed>
     */
    private function reviewUi(ChatState $state): array
    {
        $items = [];
        foreach ($this->flow->steps() as $key => $step) {
            if ($step->stepApplies($state) && $state->has($key)) {
                $items[] = ['step' => $key, 'label' => $step->label, 'value' => $step->stepDisplay($state)];
            }
        }

        return ['type' => 'review', 'step' => self::DONE, 'items' => $items];
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

    // ------------------------------------------------------------- turns

    private function handleAnswer(ChatSession $session, ChatState $state, Turn $turn): TurnResult
    {
        $key = $session->currentStep();
        $step = $this->flow->step($key);
        if (!$step) {
            throw new InvalidTurnException(sprintf('Unknown step "%s".', $key));
        }

        $result = $step->stepParse($turn, $state);

        if (!$result->ok && $result->unrecognised && $this->interpreter && $turn->is(Turn::TEXT)) {
            $interpretation = $this->interpreter->interpret($state, $key, $turn->text());
            if (!$interpretation->failed) {
                $result = StepResult::ok($interpretation->value, $interpretation->prefill);
            }
        }

        if (!$result->ok) {
            $state->recordInvalidAttempt($key);

            return TurnResult::invalid((string) $result->error);
        }

        $this->commitAnswer($state, $key, $step, $result->value);
        foreach ($result->prefill as $prefillKey => $prefillValue) {
            if ($this->flow->step($prefillKey)) {
                $state->setAnswer($prefillKey, $prefillValue);
            }
        }

        return $this->advance($session, $state);
    }

    private function handleEdit(ChatSession $session, ChatState $state, Turn $turn): TurnResult
    {
        $target = $turn->editTarget();
        if (!$this->flow->step($target)) {
            throw new InvalidTurnException(sprintf('Cannot edit unknown step "%s".', $target));
        }

        $state->setEditing($target);
        $session->setCurrentStep($target);
        $state->recordAsk($target);

        return TurnResult::continue();
    }

    private function handleSubmit(ChatSession $session, ChatState $state): TurnResult
    {
        if ($this->nextStep($state) !== self::DONE) {
            return TurnResult::invalid('Some required steps are still missing.');
        }

        $session->markCompleted();
        $this->flow->fireCompleted($state->answers(), $session);

        return TurnResult::completed();
    }

    private function handleInterruptAnswer(ChatSession $session, ChatState $state, Turn $turn): TurnResult
    {
        $interruptStep = (string) $state->interruptStep();
        $step = $this->flow->step($interruptStep);
        $result = $step ? $step->stepParse($turn, $state) : StepResult::invalid('No handler for this interrupt.');

        if (!$result->ok) {
            $state->recordInvalidAttempt($interruptStep);

            return TurnResult::invalid((string) $result->error);
        }

        $this->commitAnswer($state, $interruptStep, $step, $result->value);
        $resumeStep = (string) $state->resumeStep();
        $state->endInterrupt();
        $session->setCurrentStep($resumeStep);

        return $this->advance($session, $state);
    }

    /**
     * Stores a validated answer and clears whatever steps it invalidates.
     *
     * @param mixed $value
     */
    private function commitAnswer(ChatState $state, string $key, StepDefinition $step, $value): void
    {
        $previous = $state->has($key) ? $state->answer($key) : null;
        $state->setAnswer($key, $value);
        $state->clearInvalidAttempts($key);

        if ($state->editing() === $key) {
            $state->setEditing(null);
            $state->bumpEpoch();
        }

        if (!ChatState::same($previous, $value) && $step->invalidates) {
            $state->forget(...$step->invalidates);
        }
    }

    private function advance(ChatSession $session, ChatState $state): TurnResult
    {
        $next = $this->nextStep($state);
        $session->setCurrentStep($next);

        if ($next === self::DONE) {
            return TurnResult::continue();
        }

        $state->recordAsk($next);

        return TurnResult::continue();
    }
}
