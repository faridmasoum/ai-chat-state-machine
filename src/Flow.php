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

    /** @var array<string, StepDefinition> */
    private $interrupts = [];

    /** @var list<\Closure(array<string, mixed>, Contracts\ChatSession): void> */
    private $completionHandlers = [];

    /** @var string|\Closure(ChatState): string|null */
    private $greeting;

    /** @var \Closure(array<string, mixed>, ChatState): array<string, mixed>|null */
    private $reviewUsing;

    /** @var \Closure(array<string, mixed>, Contracts\ChatSession, ChatState): array<string, mixed>|null */
    private $uiUsing;

    /** @var string|null */
    private $submitStep;

    /** @var bool */
    private $deferCompletion = false;

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

    /**
     * Register an out-of-band step (e.g. "leave your number so we can call
     * you"). It is never walked by nextStep(), is not part of progress or the
     * review, and its value is not stored as an answer unless ->storesAnswer().
     */
    public function interrupt(StepDefinition $step): self
    {
        $this->interrupts[$step->key] = $step;

        return $this;
    }

    public function interruptStep(string $key): ?StepDefinition
    {
        return $this->interrupts[$key] ?? null;
    }

    /** A normal step or an interrupt step. */
    public function anyStep(string $key): ?StepDefinition
    {
        return $this->steps[$key] ?? $this->interrupts[$key] ?? null;
    }

    /**
     * Message shown before the first question.
     *
     * @param string|\Closure(ChatState): string $greeting
     */
    public function greeting($greeting): self
    {
        $this->greeting = $greeting;

        return $this;
    }

    public function greetingText(ChatState $state): string
    {
        if ($this->greeting === null) {
            return '';
        }

        return $this->greeting instanceof \Closure ? (string) ($this->greeting)($state) : (string) $this->greeting;
    }

    /**
     * Let the app add or alter review fields.
     *
     * @param \Closure(array<string, mixed>, ChatState): array<string, mixed> $review
     */
    public function reviewUsing(\Closure $review): self
    {
        $this->reviewUsing = $review;

        return $this;
    }

    /**
     * @param array<string, mixed> $review
     * @return array<string, mixed>
     */
    public function applyReview(array $review, ChatState $state): array
    {
        return $this->reviewUsing ? ($this->reviewUsing)($review, $state) : $review;
    }

    /**
     * Decorate any UI payload with session data (a "submitting" spinner, a retry label...).
     *
     * @param \Closure(array<string, mixed>, Contracts\ChatSession, ChatState): array<string, mixed> $ui
     */
    public function uiUsing(\Closure $ui): self
    {
        $this->uiUsing = $ui;

        return $this;
    }

    /**
     * @param array<string, mixed> $ui
     * @return array<string, mixed>
     */
    public function decorateUi(array $ui, Contracts\ChatSession $session, ChatState $state): array
    {
        return $this->uiUsing ? ($this->uiUsing)($ui, $session, $state) : $ui;
    }

    /**
     * The step whose answer is given by a SUBMIT turn (a consent step).
     */
    public function submitStep(string $key): self
    {
        $this->submitStep = $key;

        return $this;
    }

    public function submitStepKey(): ?string
    {
        return $this->submitStep;
    }

    /**
     * Two-phase completion: a valid SUBMIT only marks the session as
     * submitting; call FlowEngine::completeSubmission() once the result was sent.
     */
    public function deferCompletion(bool $defer = true): self
    {
        $this->deferCompletion = $defer;

        return $this;
    }

    public function defersCompletion(): bool
    {
        return $this->deferCompletion;
    }

    /**
     * Answered, applicable, editable steps before $current: the valid targets
     * of a go-back or a correction.
     *
     * @return list<string>
     */
    public function revisitable(ChatState $state, string $current): array
    {
        $keys = [];
        foreach ($this->steps as $key => $step) {
            if ($key === $current) {
                break;
            }
            if ($step->isEditable() && !$step->confirmation && $state->has($key) && $step->stepApplies($state)) {
                $keys[] = $key;
            }
        }

        return $keys;
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
