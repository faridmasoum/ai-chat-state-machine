<?php

namespace FaridM\StepChat;

use FaridM\StepChat\Contracts\ChatSession;

/**
 * Optional observer of the conversation. Extend it and override only what
 * you need; every method is a no-op by default (this class is also the
 * default listener).
 */
class ConversationListener
{
    /**
     * A value was committed for a step (direct answer, prefill, correction or interrupt).
     *
     * @param mixed $value
     */
    public function answered(ChatSession $session, ChatState $state, string $step, $value): void
    {
    }

    public function interpreted(ChatSession $session, ChatState $state, Interpretation $interpretation): void
    {
    }

    /**
     * @param string $source detector|ai|ai_correction|edit
     */
    public function wentBack(ChatSession $session, string $from, string $to, string $source): void
    {
    }

    /**
     * An AI correction was committed for an earlier answer (also written as a
     * transcript event). Fired once per corrected step.
     *
     * @param mixed $old
     * @param mixed $new
     */
    public function corrected(ChatSession $session, string $step, $old, $new): void
    {
    }

    public function invalid(ChatSession $session, string $step, string $error): void
    {
    }

    /**
     * A TEXT turn was not understood: it ended invalid after the interpreter failed
     * (`ai_failed`) or returned nothing usable (`ai_unhelpful`), or the step is not
     * interpretable (`not_interpretable`) and the text was rejected. Never fired for
     * sensitive steps. Not fired when the interpreter was simply not consulted
     * (escalate those with an InterpretationPolicy).
     *
     * @param string $reason ai_failed|ai_unhelpful|not_interpretable
     */
    public function unclear(ChatSession $session, string $step, string $text, string $reason): void
    {
    }

    /**
     * How long one interpreter call took (also fired when it failed).
     */
    public function interpretationTimed(ChatSession $session, string $step, int $ms): void
    {
    }

    public function turnLimitReached(ChatSession $session): void
    {
    }

    /**
     * @param list<string> $violations
     */
    public function invariantViolated(ChatSession $session, array $violations): void
    {
    }

    public function submitted(ChatSession $session): void
    {
    }

    public function completed(ChatSession $session): void
    {
    }

    public function submissionFailed(ChatSession $session, string $error): void
    {
    }
}
