<?php

namespace Farid\AiChatStateMachine;

/**
 * Typed wrapper over the JSON-serialisable state of one conversation.
 *
 * This is the only mutable piece of data the engine touches between turns.
 * It can be persisted as-is (json_encode(toArray())) and restored with
 * fromArray(), which makes it trivial to store on any session backend:
 * a database column, a cache key, a cookie, Redis, ...
 */
final class ChatState
{
    public const VERSION = 1;

    /** @var array<string, mixed> */
    private $answers = [];

    /** @var array<string, mixed> free-form context that is not a step answer */
    private $meta = [];

    /** @var list<array{step: string, epoch: int}> */
    private $askLog = [];

    /** @var array<string, int> */
    private $invalidAttempts = [];

    /** @var int bumped on every edit/invalidation so a re-asked step is legitimate */
    private $epoch = 0;

    /** @var string|null step currently being re-answered via an "edit" turn */
    private $editing;

    /** @var string|null step the flow is paused on to resolve a side question */
    private $interruptStep;

    /** @var string|null step to resume once the interrupt is resolved */
    private $resumeStep;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->answers = is_array($data['answers'] ?? null) ? $data['answers'] : [];
        $this->meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $this->askLog = is_array($data['askLog'] ?? null) ? $data['askLog'] : [];
        $this->invalidAttempts = is_array($data['invalidAttempts'] ?? null) ? $data['invalidAttempts'] : [];
        $this->epoch = (int) ($data['epoch'] ?? 0);
        $this->editing = is_string($data['editing'] ?? null) ? $data['editing'] : null;
        $this->interruptStep = is_string($data['interruptStep'] ?? null) ? $data['interruptStep'] : null;
        $this->resumeStep = is_string($data['resumeStep'] ?? null) ? $data['resumeStep'] : null;
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function fromArray(?array $data): self
    {
        return new self($data ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'v' => self::VERSION,
            'answers' => $this->answers,
            'meta' => $this->meta,
            'askLog' => $this->askLog,
            'invalidAttempts' => $this->invalidAttempts,
            'epoch' => $this->epoch,
            'editing' => $this->editing,
            'interruptStep' => $this->interruptStep,
            'resumeStep' => $this->resumeStep,
        ];
    }

    public function has(string $step): bool
    {
        return array_key_exists($step, $this->answers);
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function answer(string $step, $default = null)
    {
        return $this->answers[$step] ?? $default;
    }

    /**
     * @param mixed $value
     */
    public function setAnswer(string $step, $value): void
    {
        $this->answers[$step] = $value;
    }

    public function forget(string ...$steps): void
    {
        foreach ($steps as $step) {
            unset($this->answers[$step]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function answers(): array
    {
        return $this->answers;
    }

    public function resetAnswers(): void
    {
        $this->answers = [];
        $this->meta = [];
        $this->invalidAttempts = [];
        $this->editing = null;
        $this->bumpEpoch();
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function meta(string $key, $default = null)
    {
        return $this->meta[$key] ?? $default;
    }

    /**
     * @param mixed $value
     */
    public function setMeta(string $key, $value): void
    {
        if ($value === null) {
            unset($this->meta[$key]);
        } else {
            $this->meta[$key] = $value;
        }
    }

    public function epoch(): int
    {
        return $this->epoch;
    }

    public function bumpEpoch(): void
    {
        $this->epoch++;
    }

    public function recordAsk(string $step): void
    {
        $this->askLog[] = ['step' => $step, 'epoch' => $this->epoch];
        if (count($this->askLog) > 400) {
            $this->askLog = array_slice($this->askLog, -400);
        }
    }

    /**
     * @return list<array{step: string, epoch: int}>
     */
    public function askLog(): array
    {
        return $this->askLog;
    }

    public function editing(): ?string
    {
        return $this->editing;
    }

    public function setEditing(?string $step): void
    {
        $this->editing = $step;
    }

    public function isInterrupted(): bool
    {
        return $this->interruptStep !== null;
    }

    public function interruptStep(): ?string
    {
        return $this->interruptStep;
    }

    public function resumeStep(): ?string
    {
        return $this->resumeStep;
    }

    /**
     * Pause the main flow on $interruptStep (e.g. "I need to confirm a phone
     * number before we continue") and remember where to resume afterwards.
     */
    public function startInterrupt(string $interruptStep, string $resumeStep): void
    {
        $this->interruptStep = $interruptStep;
        $this->resumeStep = $resumeStep;
    }

    public function endInterrupt(): void
    {
        $this->interruptStep = null;
        $this->resumeStep = null;
    }

    public function invalidAttempts(string $step): int
    {
        return $this->invalidAttempts[$step] ?? 0;
    }

    public function recordInvalidAttempt(string $step): void
    {
        $this->invalidAttempts[$step] = $this->invalidAttempts($step) + 1;
    }

    public function clearInvalidAttempts(string $step): void
    {
        unset($this->invalidAttempts[$step]);
    }

    /**
     * Deep comparison that ignores array key order, which matters when one
     * side came back from a JSON column that reordered object keys.
     *
     * @param mixed $a
     * @param mixed $b
     */
    public static function same($a, $b): bool
    {
        $normalise = static function ($value) use (&$normalise) {
            if (!is_array($value)) {
                return $value;
            }
            $value = array_map($normalise, $value);
            if (array_values($value) !== $value) {
                ksort($value);
            }

            return $value;
        };

        return $normalise($a) === $normalise($b);
    }
}
