<?php

namespace FaridM\StepChat\Tests\Support;

use FaridM\StepChat\ChatState;
use FaridM\StepChat\Contracts\ChatSession;
use FaridM\StepChat\ConversationListener;
use FaridM\StepChat\Interpretation;

final class RecordingListener extends ConversationListener
{
    /** @var list<string> */
    public $events = [];

    /** @var list<string> */
    public $violations = [];

    public function answered(ChatSession $session, ChatState $state, string $step, $value): void
    {
        $this->events[] = 'answered:' . $step;
    }

    public function interpreted(ChatSession $session, ChatState $state, Interpretation $interpretation): void
    {
        $this->events[] = 'interpreted';
    }

    public function corrected(ChatSession $session, string $step, $old, $new): void
    {
        $this->events[] = sprintf('corrected:%s:%s>%s', $step, json_encode($old), json_encode($new));
    }

    /** @var list<array{string, string, string}> */
    public $unclear = [];

    /** @var list<array{string, int}> */
    public $timings = [];

    public function unclear(ChatSession $session, string $step, string $text, string $reason): void
    {
        $this->unclear[] = [$step, $text, $reason];
    }

    public function interpretationTimed(ChatSession $session, string $step, int $ms): void
    {
        $this->timings[] = [$step, $ms];
    }

    public function wentBack(ChatSession $session, string $from, string $to, string $source): void
    {
        $this->events[] = "wentBack:$from>$to:$source";
    }

    public function invalid(ChatSession $session, string $step, string $error): void
    {
        $this->events[] = 'invalid:' . $step;
    }

    public function turnLimitReached(ChatSession $session): void
    {
        $this->events[] = 'turnLimit';
    }

    public function invariantViolated(ChatSession $session, array $violations): void
    {
        $this->events[] = 'invariant';
        $this->violations = array_merge($this->violations, $violations);
    }

    public function submitted(ChatSession $session): void
    {
        $this->events[] = 'submitted';
    }

    public function completed(ChatSession $session): void
    {
        $this->events[] = 'completed';
    }

    public function submissionFailed(ChatSession $session, string $error): void
    {
        $this->events[] = 'submissionFailed:' . $error;
    }
}
