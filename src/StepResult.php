<?php

namespace FaridM\StepChat;

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

    /** @var array<string, mixed> data the step's ui closure can use to re-render (see ChatState::hint()) */
    public $hint;

    /**
     * @param mixed $value
     * @param array<string, mixed> $prefill
     * @param array<string, mixed> $hint
     */
    private function __construct(bool $ok, $value = null, ?string $error = null, array $prefill = [], bool $unrecognised = false, array $hint = [])
    {
        $this->ok = $ok;
        $this->value = $value;
        $this->error = $error;
        $this->prefill = $prefill;
        $this->unrecognised = $unrecognised;
        $this->hint = $hint;
    }

    /**
     * @param mixed $value
     * @param array<string, mixed> $prefill
     */
    public static function ok($value, array $prefill = []): self
    {
        return new self(true, $value, null, $prefill);
    }

    /**
     * @param array<string, mixed> $hint e.g. a partially picked multi-select, to come back preselected
     */
    public static function invalid(string $error, array $hint = []): self
    {
        return new self(false, null, $error, [], false, $hint);
    }

    /**
     * Parsing could not classify the input at all; a pluggable interpreter
     * (e.g. an LLM) may be given a chance to make sense of it before the
     * answer is rejected outright.
     *
     * @param array<string, mixed> $hint
     */
    public static function unrecognised(string $error, array $hint = []): self
    {
        return new self(false, null, $error, [], true, $hint);
    }
}
