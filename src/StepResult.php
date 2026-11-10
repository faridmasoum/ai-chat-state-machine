<?php

namespace FaridM\AiChatStateMachine;

/**
 * Outcome of parsing a turn against one step's validation rules.
 */
final class StepResult
{
    /** @var bool */
    public $ok;

    /** @var mixed */
    public $value;

    /** @var string|null */
    public $error;

    /** @var array<string, mixed> answers for other steps found in the same input */
    public $prefill;

    /** @var bool true when deterministic parsing could not make sense of the input at all */
    public $unrecognised;

    /**
     * @param mixed $value
     * @param array<string, mixed> $prefill
     */
    private function __construct(bool $ok, $value = null, ?string $error = null, array $prefill = [], bool $unrecognised = false)
    {
        $this->ok = $ok;
        $this->value = $value;
        $this->error = $error;
        $this->prefill = $prefill;
        $this->unrecognised = $unrecognised;
    }

    /**
     * @param mixed $value
     * @param array<string, mixed> $prefill
     */
    public static function ok($value, array $prefill = []): self
    {
        return new self(true, $value, null, $prefill);
    }

    public static function invalid(string $error): self
    {
        return new self(false, null, $error);
    }

    /**
     * Parsing could not classify the input at all; a pluggable TurnInterpreter
     * (e.g. an LLM) may be given a chance to make sense of it before the
     * answer is rejected outright.
     */
    public static function unrecognised(string $error): self
    {
        return new self(false, null, $error, [], true);
    }
}
