<?php

namespace FaridM\StepChat\Contracts;

use FaridM\StepChat\ChatState;

/**
 * Whatever persists a conversation between turns: an Eloquent model, a cache
 * entry, a plain array, a row in your own table. Implement this on top of
 * your own storage and the engine does not need to know it exists.
 */
interface ChatSession
{
    public function currentStep(): string;

    public function setCurrentStep(string $step): void;

    public function chatState(): ChatState;

    public function putChatState(ChatState $state): void;

    public function turnCount(): int;

    public function incrementTurnCount(): void;

    public function isCompleted(): bool;

    public function markCompleted(): void;
}
