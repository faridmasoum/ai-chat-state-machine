<?php

namespace FaridM\StepChat;

/**
 * Outcome of FlowEngine::handleTurn(): what the caller should do next.
 */
final class TurnResult
{
    public const CONTINUE = 'continue';

    public const INVALID = 'invalid';

    public const COMPLETED = 'completed';

    /** @var string */
    public $status;

    /** @var string|null */
    public $error;

    private function __construct(string $status, ?string $error = null)
    {
        $this->status = $status;
        $this->error = $error;
    }

    public static function continue(): self
    {
        return new self(self::CONTINUE);
    }

    public static function invalid(string $error): self
    {
        return new self(self::INVALID, $error);
    }

    public static function completed(): self
    {
        return new self(self::COMPLETED);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }

    public function isInvalid(): bool
    {
        return $this->status === self::INVALID;
    }
}
