<?php

namespace Farid\AiChatStateMachine\Laravel;

use Farid\AiChatStateMachine\ChatState;
use Farid\AiChatStateMachine\Contracts\ChatSession;

/**
 * Drop this trait on any Eloquent model to make it satisfy the ChatSession
 * contract. Expected columns (adjust the cast in your model if you name
 * them differently):
 *
 *   current_step  varchar
 *   chat_state    json
 *   turn_count    unsigned int, default 0
 *   completed_at  nullable timestamp
 *
 * Add `'chat_state' => 'array'` to the model's $casts.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasChatState
{
    public function currentStep(): string
    {
        return (string) $this->current_step;
    }

    public function setCurrentStep(string $step): void
    {
        $this->current_step = $step;
    }

    public function chatState(): ChatState
    {
        return ChatState::fromArray($this->chat_state);
    }

    public function putChatState(ChatState $state): void
    {
        $this->chat_state = $state->toArray();
        $this->save();
    }

    public function turnCount(): int
    {
        return (int) $this->turn_count;
    }

    public function incrementTurnCount(): void
    {
        $this->turn_count = $this->turnCount() + 1;
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    public function markCompleted(): void
    {
        $this->completed_at = now();
        $this->save();
    }
}
