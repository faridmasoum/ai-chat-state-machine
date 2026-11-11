<?php

namespace FaridM\StepChat\Support;

use FaridM\StepChat\ChatState;
use FaridM\StepChat\Contracts\ChatSession;
use FaridM\StepChat\Contracts\ConversationInterpreter;
use FaridM\StepChat\Contracts\TurnInterpreter;
use FaridM\StepChat\Interpretation;

/**
 * Lets a v1 TurnInterpreter keep working: its value becomes answers[current
 * step] and its prefill becomes further answers.
 */
final class LegacyInterpreterAdapter implements ConversationInterpreter
{
    /** @var TurnInterpreter */
    private $inner;

    public function __construct(TurnInterpreter $inner)
    {
        $this->inner = $inner;
    }

    public function interpret(ChatSession $session, ChatState $state, string $step, string $text): Interpretation
    {
        $result = $this->inner->interpret($state, $step, $text);
        if ($result->failed()) {
            return Interpretation::fail();
        }

        return Interpretation::of(['answers' => [$step => $result->value()] + $result->prefill()]);
    }
}
