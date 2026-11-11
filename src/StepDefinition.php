<?php

namespace FaridM\StepChat;

use Closure;

/**
 * Declarative definition of one step in a Flow. Build one with the fluent
 * `make()` API instead of the constructor directly — see StepDefinition::make().
 */
final class StepDefinition
{
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

    public function stepParse(Turn $turn, ChatState $state): StepResult
    {
        if ($turn->is(Turn::SKIP)) {
            return $this->isOptional($state)
                ? StepResult::ok($this->skipValue)
                : StepResult::invalid('This step is required.');
        }

        return ($this->parse)($turn, $state);
    }

    public function stepDisplay(ChatState $state): string
    {
        return $state->has($this->key) ? ($this->display)($state->answer($this->key), $state) : '';
    }
}
