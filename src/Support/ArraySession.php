<?php

namespace FaridM\AiChatStateMachine\Support;

use FaridM\AiChatStateMachine\ChatState;
use FaridM\AiChatStateMachine\Contracts\ChatSession;

/**
 * Minimal, storage-agnostic ChatSession. Useful for tests, CLI demos, or any
 * place you already have your own way of persisting a plain array (cache,
 * Redis, a session column) and just need something that satisfies the
 * contract. Call toArray()/fromArray() to move it in and out of storage.
 */
final class ArraySession implements ChatSession
{
    /** @var string */
    private $currentStep = '';

    /** @var ChatState */
    private $state;

    /** @var int */
    private $turnCount = 0;

    /** @var bool */
    private $completed = false;

    public function __construct(?ChatState $state = null)
    {
        $this->state = $state ?? new ChatState();
    }

    /**
     * @param array{currentStep?: string, state?: array<string, mixed>, turnCount?: int, completed?: bool} $data
     */
    public static function fromArray(array $data): self
    {
        $session = new self(ChatState::fromArray($data['state'] ?? null));
        $session->currentStep = (string) ($data['currentStep'] ?? '');
        $session->turnCount = (int) ($data['turnCount'] ?? 0);
        $session->completed = (bool) ($data['completed'] ?? false);

        return $session;
    }

    /**
     * @return array{currentStep: string, state: array<string, mixed>, turnCount: int, completed: bool}
     */
    public function toArray(): array
    {
        return [
            'currentStep' => $this->currentStep,
            'state' => $this->state->toArray(),
            'turnCount' => $this->turnCount,
            'completed' => $this->completed,
        ];
    }

    public function currentStep(): string
    {
        return $this->currentStep;
    }

    public function setCurrentStep(string $step): void
    {
        $this->currentStep = $step;
    }

    public function chatState(): ChatState
    {
        return $this->state;
    }

    public function putChatState(ChatState $state): void
    {
        $this->state = $state;
    }

    public function turnCount(): int
    {
        return $this->turnCount;
    }

    public function incrementTurnCount(): void
    {
        $this->turnCount++;
    }

    public function isCompleted(): bool
    {
        return $this->completed;
    }

    public function markCompleted(): void
    {
        $this->completed = true;
    }
}
