<?php

namespace FaridM\StepChat\Contracts;

use FaridM\StepChat\ChatState;
use FaridM\StepChat\Handoff;

/**
 * Decides what happens when the user asks a side question the interpreter
 * could not answer: show a message, or pause the flow on an interrupt step
 * (e.g. "leave your number and we will call you").
 */
interface HandoffPolicy
{
    /**
     * @param array{text: string, answerable: bool, answer: ?string, topic: ?string} $question
     */
    public function decide(ChatSession $session, ChatState $state, string $step, array $question): Handoff;
}
