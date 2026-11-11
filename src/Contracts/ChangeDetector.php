<?php

namespace FaridM\StepChat\Contracts;

use FaridM\StepChat\ChatState;

/**
 * Deterministic "the user wants to change an earlier answer" detection
 * ("actually it's a Toyota", "go back"). Works without AI.
 * Return the step to reopen, or null. Flow::revisitable() lists valid targets.
 */
interface ChangeDetector
{
    public function detect(string $text, string $currentStep, ChatState $state): ?string;
}
