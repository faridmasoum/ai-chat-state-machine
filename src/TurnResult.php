<?php

namespace FaridM\StepChat;

/**
 * Outcome of FlowEngine::handleTurn(): what the caller should do next.
 */
final class TurnResult
{
    public const CONTINUE = 'continue';

    public const INVALID = 'invalid';

    public const SUBMITTING = 'submitting';

    public const COMPLETED = 'completed';

    public const ENDED = 'ended';

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

    public static function submitting(): self
    {
        return new self(self::SUBMITTING);
    }

    public static function completed(): self
    {
        return new self(self::COMPLETED);
    }

    public static function ended(): self
    {
        return new self(self::ENDED);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }

    public function isInvalid(): bool
    {
        return $this->status === self::INVALID;
    }

    public function isSubmitting(): bool
    {
        return $this->status === self::SUBMITTING;
    }

    public function isEnded(): bool
    {
        return $this->status === self::ENDED;
    }
}
