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

    /** @var bool the user declined (see skip()); handled like a SKIP turn */
    public $skipped = false;

    /** @var string|null acknowledgement text for a skip() */
    public $skipAck;

    /** @var bool false for retry(): an invalid answer that does not count as an attempt */
    public $countsAttempt = true;

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

    /**
     * The user declined to answer (e.g. "no thanks"). Handled exactly like a
     * SKIP turn on an optional step: the skip value is stored (interrupt
     * steps: afterApply gets it), acknowledge() receives skipped = true and
     * the flow moves on. On a required step it is treated as invalid with
     * Messages::required().
     *
     * @param string|null $ack text shown instead of the step's acknowledge()
     */
    public static function skip(?string $ack = null): self
    {
        $result = new self(true);
        $result->skipped = true;
        $result->skipAck = $ack;

        return $result;
    }

    /**
     * Like invalid(), but the engine does not count it as an attempt
     * (ChatState::invalidAttempts() is left alone): for input that was not
     * even an attempt at answering, e.g. a sentence typed into a code field.
     *
     * @param array<string, mixed> $hint
     */
    public static function retry(string $error, array $hint = []): self
    {
        $result = new self(false, null, $error, [], false, $hint);
        $result->countsAttempt = false;

        return $result;
    }
}
