<?php

namespace FaridM\StepChat\Contracts;

use FaridM\StepChat\ChatState;

/**
 * Every user-facing string the engine writes. Implement it (or extend
 * Support\EnglishMessages) to translate or reword them per app or locale.
 */
interface Messages
{
    /** Error for a skip on a required step. */
    public function required(): string;

    /** Error for a submit while required steps are still missing. */
    public function incomplete(): string;

    /** Shown when the AI interpreter could not make sense of the input either. */
    public function fallback(): string;

    /** Transcript echo for a skipped step. */
    public function skipped(): string;

    /** Transcript echo for a sensitive (secret) value. */
    public function secretEntered(): string;

    public function turnLimitReached(): string;

    /**
     * Lead-in when the conversation goes back to an earlier step. Return '' for no lead-in
     * (e.g. for a plain edit).
     *
     * @param string $currentAnswerDisplay display of the target's current answer ('' when it has none)
     * @param string $source detector|ai|ai_correction|edit
     */
    public function goingBack(string $currentAnswerDisplay, string $source): string;

    /**
     * Lead-in after earlier answers were corrected.
     *
     * @param list<string> $labels labels of the corrected steps
     */
    public function updated(array $labels): string;

    public function submissionFailed(): string;

    public function done(ChatState $state): string;

    public function handoffSaved(): string;

    public function handoffSkipped(): string;

    public function handoffWithContact(string $contact): string;

    /** The question shown with the review screen. */
    public function reviewPrompt(): string;

    public function reviewConfirmLabel(): string;

    public function submitLabel(ChatState $state, bool $retry): string;
}
