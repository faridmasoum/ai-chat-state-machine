<?php

namespace FaridM\StepChat\Support;

use FaridM\StepChat\ChatState;
use FaridM\StepChat\Contracts\ChatSession;
use FaridM\StepChat\Contracts\InterpretationPolicy;
use FaridM\StepChat\StepResult;
use FaridM\StepChat\Turn;

/** The default policy: only consult the interpreter when the parse is unrecognised. */
final class NeverInterpretPolicy implements InterpretationPolicy
{
    public function shouldInterpret(ChatSession $session, ChatState $state, string $step, Turn $turn, StepResult $parsed): bool
    {
        return false;
    }
}
