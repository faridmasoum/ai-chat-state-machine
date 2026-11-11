<?php

namespace FaridM\StepChat;

use Closure;

/**
 * Declarative definition of one step in a Flow. Build one with the fluent
 * `make()` API instead of the constructor directly — see StepDefinition::make().
 */
final class StepDefinition
{
    /** The interpreter may supply the answer (default). */
    public const INTERPRET_FULL = 'full';

    /** The value is always the user's own text; the interpreter is only used for meta (corrections, revisit, side questions). */
    public const INTERPRET_META = 'meta';

    /** The interpreter is never consulted. */
    public const INTERPRET_NONE = 'none';

    /** @var string */
    public $key;

    /** @var string */
    public $label;

    /** @var Closure(ChatState): bool */
    public $applies;

    /** @var Closure(ChatState): array<string, mixed> */
    public $ui;

    /** @var Closure(Turn, ChatState): StepResult */
    public $parse;

    /** @var Closure(mixed, ChatState): (string|null) */
    public $validate;

    /** @var Closure(mixed, ChatState): string */
    public $display;

    /** @var list<string> steps cleared when this answer changes */
    public $invalidates = [];

    /** @var bool */
    public $optional = false;

    /** @var mixed value stored when the step is skipped */
    public $skipValue;

    /** @var Closure(ChatState): bool|null */
    public $optionalWhen;

    /** @var string|Closure(ChatState): string|null question asked in the conversation (label is the short review name) */
    public $question;

    /** @var bool */
    public $editable = true;

    /** @var bool */
    public $allowsEdits = false;

    /** @var Closure(ChatState, mixed): void|null */
    public $afterApply;

    /** @var Closure(ChatState, mixed, mixed): void|null */
    public $onChange;

    /** @var bool */
    public $resetsFlow = false;

    /** @var bool */
    public $confirmation = false;

    /** @var bool */
    public $revalidate = true;

    /** @var bool */
    public $sensitive = false;

    /** @var Closure(Turn): bool|null raw input that is NOT secret on a sensitive step */
    public $sensitiveUnless;

    /** @var bool false only in INTERPRET_NONE mode */
    public $interpretable = true;

    /** @var string */
    public $interpretMode = self::INTERPRET_FULL;

    /** @var string|null */
    public $requiredMessage;

    /** @var Closure(Turn, ChatState): string|null */
    public $echo;

    /** @var Closure(mixed, ChatState, bool): (string|null)|null */
    public $acknowledge;

    /** @var Closure(mixed, ChatState): StepResult|null */
    public $prefillUsing;

    /** @var string|null */
    public $section;

    /** @var bool interrupt steps only: keep the value as an answer */
    public $storesAnswer = false;

    /** @var array<string, mixed> */
    private $attributes = [];

    public function __construct(string $key, string $label)
    {
        $this->key = $key;
        $this->label = $label;
        $this->applies = static fn (ChatState $state): bool => true;
        $this->ui = static fn (ChatState $state): array => ['type' => 'text'];
        $this->parse = static fn (Turn $turn, ChatState $state): StepResult => StepResult::ok($turn->text());
        $this->validate = static fn ($value, ChatState $state): ?string => null;
        $this->display = static fn ($value, ChatState $state): string => is_scalar($value) ? (string) $value : '';
    }

    public static function make(string $key, string $label): self
    {
        return new self($key, $label);
    }

    public function applies(Closure $applies): self
    {
        $this->applies = $applies;

        return $this;
    }

    public function ui(Closure $ui): self
    {
        $this->ui = $ui;

        return $this;
    }

    public function parse(Closure $parse): self
    {
        $this->parse = $parse;

        return $this;
    }

    public function validate(Closure $validate): self
    {
        $this->validate = $validate;

        return $this;
    }

    public function display(Closure $display): self
    {
        $this->display = $display;

        return $this;
    }

    /**
     * @param list<string> $steps
     */
    public function invalidates(array $steps): self
    {
        $this->invalidates = $steps;

        return $this;
    }

    /**
     * @param mixed $skipValue value stored when the user skips this step
     */
    public function optional(bool $optional = true, $skipValue = null): self
    {
        $this->optional = $optional;
        $this->skipValue = $skipValue;

        return $this;
    }

    public function optionalWhen(Closure $condition): self
    {
        $this->optionalWhen = $condition;

        return $this;
    }

    /**
     * The question asked in the conversation (the label stays the short name
     * for the review screen).
     *
     * @param string|Closure(ChatState): string $question
     */
    public function question($question): self
    {
        $this->question = $question;

        return $this;
    }

    /** Non-editable steps cannot be targeted by edit turns, corrections or go-back. */
    public function editable(bool $editable = true): self
    {
        $this->editable = $editable;

        return $this;
    }

    /** EDIT turns are accepted while this step is current (besides from DONE). */
    public function allowsEdits(bool $allows = true): self
    {
        $this->allowsEdits = $allows;

        return $this;
    }

    /**
     * Runs after ANY commit of this step (answer, prefill, correction, interrupt).
     *
     * @param Closure(ChatState, mixed): void $afterApply
     */
    public function afterApply(Closure $afterApply): self
    {
        $this->afterApply = $afterApply;

        return $this;
    }

    /**
     * Runs only when an existing answer changes to a different value, after
     * `invalidates` has cleared dependents.
     *
     * @param Closure(ChatState, mixed, mixed): void $onChange
     */
    public function onChange(Closure $onChange): self
    {
        $this->onChange = $onChange;

        return $this;
    }

    /** Changing an existing answer of this step clears every other answer. */
    public function resetsFlow(bool $resets = true): self
    {
        $this->resetsFlow = $resets;

        return $this;
    }

    /**
     * An answer that only confirms earlier ones (review, consent). Cleared
     * whenever another answer changes, an edit starts or a go-back happens.
     */
    public function confirmation(bool $confirmation = true): self
    {
        $this->confirmation = $confirmation;

        return $this;
    }

    /** When false the invariant checker keeps the stored answer even if `validate` later fails. */
    public function revalidate(bool $revalidate = true): self
    {
        $this->revalidate = $revalidate;

        return $this;
    }

    /** Secrets: never echoed to the transcript, never given to the interpreter or change detector. */
    public function sensitive(bool $sensitive = true): self
    {
        $this->sensitive = $sensitive;

        return $this;
    }

    /**
     * Sensitive step, except for the raw input where the closure returns true
     * (e.g. an escape button such as "continue as buyer").
     *
     * @param Closure(Turn): bool $notSecret
     */
    public function sensitiveUnless(Closure $notSecret): self
    {
        $this->sensitive = true;
        $this->sensitiveUnless = $notSecret;

        return $this;
    }

    /**
     * How the interpreter may be used on this step: true / INTERPRET_FULL (default), INTERPRET_META
     * (the value is always the user's own text; the interpreter can still produce corrections,
     * a revisit or a side question, and the text is not committed when it does), or
     * false / INTERPRET_NONE (never consulted). The change detector runs unless the mode is NONE.
     *
     * @param bool|string $mode
     */
    public function interpretable($mode = true): self
    {
        if ($mode === true) {
            $mode = self::INTERPRET_FULL;
        } elseif ($mode === false) {
            $mode = self::INTERPRET_NONE;
        }
        if (!in_array($mode, [self::INTERPRET_FULL, self::INTERPRET_META, self::INTERPRET_NONE], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown interpret mode "%s".', is_scalar($mode) ? (string) $mode : gettype($mode)));
        }
        $this->interpretMode = $mode;
        $this->interpretable = $mode !== self::INTERPRET_NONE;

        return $this;
    }

    public function requiredMessage(string $message): self
    {
        $this->requiredMessage = $message;

        return $this;
    }

    /**
     * How the user's turn is shown in the transcript.
     *
     * @param Closure(Turn, ChatState): string $echo
     */
    public function echo(Closure $echo): self
    {
        $this->echo = $echo;

        return $this;
    }

    /**
     * Text shown after the answer is accepted and before the next question.
     *
     * @param Closure(mixed, ChatState, bool): (string|null) $acknowledge value, state, skipped
     */
    public function acknowledge(Closure $acknowledge): self
    {
        $this->acknowledge = $acknowledge;

        return $this;
    }

    /**
     * How a value for THIS step found in another step's input is parsed.
     *
     * @param Closure(mixed, ChatState): StepResult $prefillUsing
     */
    public function prefillUsing(Closure $prefillUsing): self
    {
        $this->prefillUsing = $prefillUsing;

        return $this;
    }

    /** Group name on the review screen. */
    public function section(string $section): self
    {
        $this->section = $section;

        return $this;
    }

    /** Interrupt steps: keep the value as an answer instead of only running afterApply/acknowledge. */
    public function storesAnswer(bool $stores = true): self
    {
        $this->storesAnswer = $stores;

        return $this;
    }

    /**
     * Free attribute bag for app data.
     *
     * @param mixed $value
     */
    public function with(string $key, $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function attribute(string $key, $default = null)
    {
        return array_key_exists($key, $this->attributes) ? $this->attributes[$key] : $default;
    }

    public function isEditable(): bool
    {
        return $this->editable;
    }

    public function isInterpretable(): bool
    {
        return $this->interpretMode !== self::INTERPRET_NONE;
    }

    public function interpretMode(): string
    {
        return $this->interpretMode;
    }

    // ------------------------------------------------------------- runtime

    public function stepApplies(ChatState $state): bool
    {
        return ($this->applies)($state);
    }

    public function isOptional(ChatState $state): bool
    {
        return $this->optionalWhen ? (bool) ($this->optionalWhen)($state) : $this->optional;
    }

    public function isSatisfied(ChatState $state): bool
    {
        return $state->has($this->key) && ($this->validate)($state->answer($this->key), $state) === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function stepUi(ChatState $state): array
    {
        return ($this->ui)($state);
    }

    /**
     * @param string|null $requiredDefault error for a skip on a required step when no requiredMessage is set (the engine passes Messages::required())
     */
    public function stepParse(Turn $turn, ChatState $state, ?string $requiredDefault = null): StepResult
    {
        if ($turn->is(Turn::SKIP)) {
            if (!$this->isOptional($state)) {
                return StepResult::invalid($this->requiredMessage ?? $requiredDefault ?? 'This step is required.');
            }
            $skip = StepResult::skip();
            $skip->value = $this->skipValue;

            return $skip;
        }

        return $this->resolveSkip(($this->parse)($turn, $state), $state, $requiredDefault);
    }

    /**
     * A parse that returned StepResult::skip() is a skip: on an optional step it
     * stores the skip value, on a required one it is invalid.
     */
    private function resolveSkip(StepResult $result, ChatState $state, ?string $requiredDefault): StepResult
    {
        if (!$result->ok || !$result->skipped) {
            return $result;
        }
        if (!$this->isOptional($state)) {
            return StepResult::invalid($this->requiredMessage ?? $requiredDefault ?? 'This step is required.');
        }
        $skip = StepResult::skip($result->skipAck);
        $skip->value = $this->skipValue;

        return $skip;
    }

    /**
     * Parse a raw value suggested by the interpreter exactly like user input,
     * then run it through `validate`. A string becomes a TEXT turn, a list a
     * LIST turn, booleans "yes"/"no".
     *
     * @param mixed $raw
     */
    public function stepParseRaw($raw, ChatState $state, ?string $requiredDefault = null): StepResult
    {
        if (is_array($raw)) {
            $turn = new Turn($this->key, Turn::LIST, array_values(array_filter($raw, 'is_string')));
        } elseif (is_bool($raw)) {
            $turn = new Turn($this->key, Turn::TEXT, $raw ? 'yes' : 'no');
        } elseif (is_scalar($raw)) {
            $turn = new Turn($this->key, Turn::TEXT, (string) $raw);
        } else {
            return StepResult::invalid($this->requiredMessage ?? 'This step is required.');
        }
        $result = $this->resolveSkip(($this->parse)($turn, $state), $state, $requiredDefault);
        if ($result->ok && !$result->skipped) {
            $error = ($this->validate)($result->value, $state);
            if ($error !== null) {
                return StepResult::invalid($error, $result->hint);
            }
        }

        return $result;
    }

    /**
     * Parse a value for this step found in another step's input. Uses
     * prefillUsing when defined; otherwise AI-suggested raw values go through
     * the step's own parse (never stored raw) and app-supplied prefill values
     * through `validate`.
     *
     * @param mixed $raw
     */
    public function stepPrefill($raw, ChatState $state, bool $fromAi = false): StepResult
    {
        if ($this->prefillUsing) {
            $result = ($this->prefillUsing)($raw, $state);

            return $result->ok && $result->skipped ? StepResult::invalid('A prefill cannot skip a step.') : $result;
        }
        if ($fromAi) {
            return $this->stepParseRaw($raw, $state);
        }
        $error = ($this->validate)($raw, $state);

        return $error === null ? StepResult::ok($raw) : StepResult::invalid($error);
    }

    public function questionText(ChatState $state): string
    {
        if ($this->question === null) {
            return $this->label;
        }

        return $this->question instanceof Closure ? (string) ($this->question)($state) : (string) $this->question;
    }

    /** True when this raw input is a secret (never echoed, never sent to an interpreter). */
    public function isSensitiveTurn(Turn $turn): bool
    {
        if (!$this->sensitive || !$turn->is(Turn::TEXT) && !$turn->is(Turn::CHOICE) && !$turn->is(Turn::LIST)) {
            return false;
        }

        return !($this->sensitiveUnless && ($this->sensitiveUnless)($turn));
    }

    /**
     * @param mixed $value
     */
    public function acknowledgeText($value, ChatState $state, bool $skipped): ?string
    {
        if (!$this->acknowledge) {
            return null;
        }
        $text = ($this->acknowledge)($value, $state, $skipped);

        return $text === null || $text === '' ? null : $text;
    }

    public function stepDisplay(ChatState $state): string
    {
        return $state->has($this->key) ? ($this->display)($state->answer($this->key), $state) : '';
    }
}
