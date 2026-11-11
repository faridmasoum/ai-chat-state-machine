<?php

namespace FaridM\StepChat\Tests\Support;

use Closure;
use FaridM\StepChat\ChatState;
use FaridM\StepChat\Contracts\ChatSession;
use FaridM\StepChat\Contracts\ConversationInterpreter;
use FaridM\StepChat\Interpretation;

final class ClosureInterpreter implements ConversationInterpreter
{
    /** @var Closure(string, string): Interpretation */
    private $fn;

    /** @var list<array{string, string, ChatState}> */
    public $calls = [];

    public function __construct(Closure $fn)
    {
        $this->fn = $fn;
    }

    public function interpret(ChatSession $session, ChatState $state, string $step, string $text): Interpretation
    {
        $this->calls[] = [$step, $text, $state];

        return ($this->fn)($step, $text);
    }
}
