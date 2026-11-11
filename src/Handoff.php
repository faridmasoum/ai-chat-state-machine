<?php

namespace FaridM\StepChat;

/**
 * What to do with a side question that could not be answered: show a text
 * and re-ask the step, or pause the flow on an interrupt step.
 */
final class Handoff
{
    /** @var string */
    public $text;

    /** @var string|null */
    public $interruptStep;

    private function __construct(string $text, ?string $interruptStep)
    {
        $this->text = $text;
        $this->interruptStep = $interruptStep;
    }

    public static function message(string $text): self
    {
        return new self($text, null);
    }

    public static function interrupt(string $interruptStep): self
    {
        return new self('', $interruptStep);
    }

    public function isInterrupt(): bool
    {
        return $this->interruptStep !== null;
    }
}
