<?php

namespace FaridM\StepChat\Contracts;

use FaridM\StepChat\ChatState;
use FaridM\StepChat\StepResult;
use FaridM\StepChat\Turn;

/**
 * Lets the app consult the interpreter even when the deterministic parse
 * succeeded (e.g. "long text on a choice step may carry more answers").
 * A successful deterministic parse still wins for the current step.
 */
interface InterpretationPolicy
{
    public function shouldInterpret(ChatSession $session, ChatState $state, string $step, Turn $turn, StepResult $parsed): bool;
}
