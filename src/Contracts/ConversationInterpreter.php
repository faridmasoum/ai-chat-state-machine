<?php

namespace FaridM\StepChat\Contracts;

use FaridM\StepChat\ChatState;
use FaridM\StepChat\Interpretation;

/**
 * Optional AI helper (v2). It never decides the next step: every value it
 * returns is run through the target step's own deterministic parse or
 * prefillUsing before anything is stored. See FlowEngine for when it is
 * consulted and how the result is applied.
 */
interface ConversationInterpreter
{
    public function interpret(ChatSession $session, ChatState $state, string $step, string $text): Interpretation;
}
