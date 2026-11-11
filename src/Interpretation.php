<?php

namespace FaridM\StepChat;

/**
 * Result of a TurnInterpreter call: either a value for the step that was
 * unrecognised, plus any other steps the same message answered, or a flag
 * saying the interpreter could not help either.
 */
final class Interpretation
{
    /** @var bool */
    public $failed;

    /** @var mixed */
    public $value;

    /** @var array<string, mixed> */
    public $prefill;

    /**
     * @param mixed $value
     * @param array<string, mixed> $prefill
     */
    private function __construct(bool $failed, $value = null, array $prefill = [])
    {
        $this->failed = $failed;
        $this->value = $value;
        $this->prefill = $prefill;
    }

    /**
     * @param mixed $value
     * @param array<string, mixed> $prefill
     */
    public static function ok($value, array $prefill = []): self
    {
        return new self(false, $value, $prefill);
    }

    public static function fail(): self
    {
        return new self(true);
    }
}
