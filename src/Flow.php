<?php

namespace FaridM\StepChat;

/**
 * An ordered collection of StepDefinition. The order declared here is the
 * order the engine walks through; branching is expressed per-step with
 * `->applies()`, not by the collection itself.
 */
final class Flow
{
    /** @var array<string, StepDefinition> */
    private $steps = [];

    /** @var list<\Closure(array<string, mixed>, Contracts\ChatSession): void> */
    private $completionHandlers = [];

    public static function make(): self
    {
        return new self();
    }

    public function add(StepDefinition $step): self
    {
        $this->steps[$step->key] = $step;

        return $this;
    }

    /**
     * Register a callback run once when the user submits a fully answered flow.
     * It receives the answers array and the session.
     *
     * @param \Closure(array<string, mixed>, Contracts\ChatSession): void $handler
     */
    public function onComplete(\Closure $handler): self
    {
        $this->completionHandlers[] = $handler;

        return $this;
    }

    /**
     * @param array<string, mixed> $answers
     */
    public function fireCompleted(array $answers, Contracts\ChatSession $session): void
    {
        foreach ($this->completionHandlers as $handler) {
            $handler($answers, $session);
        }
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
