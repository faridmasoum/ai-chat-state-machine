<?php

namespace FaridM\StepChat\Support;

use FaridM\StepChat\ChatState;
use FaridM\StepChat\Contracts\Messages;

/**
 * Default English wording. Extend it and override only what you want to
 * change, or implement Messages from scratch for another language.
 */
class EnglishMessages implements Messages
{
    public function required(): string
    {
        return 'This step is required.';
    }

    public function incomplete(): string
    {
        return 'Some required steps are still missing.';
    }

    public function fallback(): string
    {
        return "Sorry, I couldn't understand that. Could you answer in another way?";
    }

    public function skipped(): string
    {
        return 'Skipped';
    }

    public function secretEntered(): string
    {
        return '••••••';
    }

    public function turnLimitReached(): string
    {
        return 'This conversation has reached its turn limit.';
    }

    public function goingBack(string $currentAnswerDisplay): string
    {
        return $currentAnswerDisplay === ''
            ? "Sure, let's change that."
            : sprintf("Sure, it's currently \"%s\". Let's change it.", $currentAnswerDisplay);
    }

    public function updated(array $labels): string
    {
        return 'Updated: ' . implode(', ', $labels) . '.';
    }

    public function submissionFailed(): string
    {
        return 'We could not send your details. Please try again.';
    }

    public function done(ChatState $state): string
    {
        return 'Thank you! Your request has been submitted.';
    }

    public function handoffSaved(): string
    {
        return 'Thanks, saved.';
    }

    public function handoffSkipped(): string
    {
        return 'No problem.';
    }

    public function handoffWithContact(string $contact): string
    {
        return sprintf("Thanks, we'll be in touch on %s.", $contact);
    }

    public function reviewPrompt(): string
    {
        return 'Please review your answers.';
    }

    public function reviewConfirmLabel(): string
    {
        return 'Confirm';
    }

    public function submitLabel(ChatState $state, bool $retry): string
    {
        return $retry ? 'Try again' : 'Submit';
    }
}
