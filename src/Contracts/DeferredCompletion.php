<?php

namespace FaridM\StepChat\Contracts;

/**
 * Optional, implemented by a ChatSession that supports two-phase submission
 * (Flow::deferCompletion()): the final submit only marks the session as
 * submitting; the app sends the result (e.g. to a CRM) and then calls
 * FlowEngine::completeSubmission().
 */
interface DeferredCompletion
{
    public function isSubmitting(): bool;

    public function markSubmitting(): void;

    public function markSubmitFailed(): void;

    public function markCompleted(): void;
}
