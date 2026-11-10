<?php

namespace Farid\AiChatStateMachine;

/**
 * An ordered collection of StepDefinition. The order declared here is the
 * order the engine walks through; branching is expressed per-step with
 * `->applies()`, not by the collection itself.
 */
final class Flow
{
    /** @var array<string, StepDefinition> */
    private $steps = [];

    public static function make(): self
    {
        return new self();
    }

    public function add(StepDefinition $step): self
    {
        $this->steps[$step->key] = $step;

        return $this;
    }

    public function step(string $key): ?StepDefinition
    {
        return $this->steps[$key] ?? null;
    }

    /**
     * @return array<string, StepDefinition>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->steps);
    }
}
