<?php

namespace FaridM\StepChat\Contracts;

use FaridM\StepChat\ChatState;
use FaridM\StepChat\Interpretation;

/**
 * v1 free-text fallback, still supported (it is wrapped in a
 * Support\LegacyInterpreterAdapter). New code should implement
 * ConversationInterpreter.
 */
interface TurnInterpreter
{
    public function interpret(ChatState $state, string $step, string $text): Interpretation;
}
