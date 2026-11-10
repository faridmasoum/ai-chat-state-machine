<?php

namespace FaridM\AiChatStateMachine\Contracts;

use FaridM\AiChatStateMachine\ChatState;
use FaridM\AiChatStateMachine\Interpretation;

/**
 * Optional free-text fallback. The engine always decides which step is asked
 * and always tries its own deterministic `parse` closure first; an
 * interpreter (typically backed by an LLM of your choice) only gets a turn
 * when that deterministic parse comes back unrecognised, and it is only
 * trusted to fill in what the deterministic parser could not place.
 */
interface TurnInterpreter
{
    public function interpret(ChatState $state, string $step, string $text): Interpretation;
}
