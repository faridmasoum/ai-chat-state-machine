<?php

namespace FaridM\StepChat\Support;

use FaridM\StepChat\ChatState;
use FaridM\StepChat\Contracts\ChatSession;
use FaridM\StepChat\Contracts\DeferredCompletion;
use FaridM\StepChat\Contracts\StoresMessages;

/**
 * Minimal, storage-agnostic ChatSession. Useful for tests, CLI demos, or any
 * place you already have your own way of persisting a plain array (cache,
 * Redis, a session column) and just need something that satisfies the
 * contract. Call toArray()/fromArray() to move it in and out of storage.
 */
final class ArraySession implements ChatSession, StoresMessages, DeferredCompletion
{
    private const MAX_MESSAGES = 200;

    /** @var string */
    private $currentStep = '';

    /** @var ChatState */
    private $state;

    /** @var int */
    private $turnCount = 0;

    /** @var bool */
    private $completed = false;

    /** @var bool */
    private $submitting = false;

    /** @var list<array<string, mixed>> */
    private $messages = [];

    /** @var int absolute index of the first kept message */
    private $messageBase = 0;

    public function __construct(?ChatState $state = null)
    {
        $this->state = $state ?? new ChatState();
    }

    /**
     * @param array{currentStep?: string, state?: array<string, mixed>, turnCount?: int, completed?: bool, submitting?: bool, messages?: list<array<string, mixed>>, messageBase?: int} $data
     */
    public static function fromArray(array $data): self
    {
        $session = new self(ChatState::fromArray($data['state'] ?? null));
        $session->currentStep = (string) ($data['currentStep'] ?? '');
        $session->turnCount = (int) ($data['turnCount'] ?? 0);
        $session->completed = (bool) ($data['completed'] ?? false);
        $session->submitting = (bool) ($data['submitting'] ?? false);
        $session->messages = array_values($data['messages'] ?? []);
        $session->messageBase = (int) ($data['messageBase'] ?? 0);

        return $session;
    }

    /**
     * @return array{currentStep: string, state: array<string, mixed>, turnCount: int, completed: bool, submitting: bool, messages: list<array<string, mixed>>, messageBase: int}
     */
    public function toArray(): array
    {
        return [
            'currentStep' => $this->currentStep,
            'state' => $this->state->toArray(),
            'turnCount' => $this->turnCount,
            'completed' => $this->completed,
            'submitting' => $this->submitting,
            'messages' => $this->messages,
            'messageBase' => $this->messageBase,
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
        $this->submitting = false;
    }

    public function isSubmitting(): bool
    {
        return $this->submitting && !$this->completed;
    }

    public function markSubmitting(): void
    {
        $this->submitting = true;
    }

    public function markSubmitFailed(): void
    {
        $this->submitting = false;
    }

    public function addMessage(array $message): void
    {
        $this->messages[] = $message;
        if (count($this->messages) > self::MAX_MESSAGES) {
            $drop = count($this->messages) - self::MAX_MESSAGES;
            $this->messages = array_slice($this->messages, $drop);
            $this->messageBase += $drop;
        }
    }

    public function messagesSince(int $cursor): array
    {
        $out = [];
        foreach ($this->messages as $i => $message) {
            $id = $this->messageBase + $i;
            if ($id >= $cursor) {
                $out[] = ['id' => $id] + $message;
            }
        }

        return $out;
    }

    public function messageCursor(): int
    {
        return $this->messageBase + count($this->messages);
    }
}
