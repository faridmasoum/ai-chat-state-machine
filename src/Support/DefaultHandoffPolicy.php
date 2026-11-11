<?php

namespace FaridM\StepChat\Support;

use FaridM\StepChat\ChatState;
use FaridM\StepChat\Contracts\ChatSession;
use FaridM\StepChat\Contracts\HandoffPolicy;
use FaridM\StepChat\Contracts\Messages;
use FaridM\StepChat\Handoff;

/** Shows Messages::fallback() and re-asks the step. */
final class DefaultHandoffPolicy implements HandoffPolicy
{
    /** @var Messages */
    private $messages;

    public function __construct(Messages $messages)
    {
        $this->messages = $messages;
    }

    public function decide(ChatSession $session, ChatState $state, string $step, array $question): Handoff
    {
        return Handoff::message($this->messages->fallback());
    }
}
