<?php

namespace FaridM\StepChat;

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
    public const VERSION = 2;

    /** @var array<string, mixed> */
    private $answers = [];

    /** @var array<string, mixed> free-form context that is not a step answer */
    private $meta = [];

    /** @var list<array{step: string, reason: string, epoch: int}> */
    private $askLog = [];

    /** @var int total asks ever recorded (the log itself is capped) */
    private $askTotal = 0;

    /** @var array<string, array<string, mixed>> per-step data a step's ui closure can re-render with */
    private $hints = [];

    /** @var bool the conversation hit its turn limit */
    private $ended = false;

    /** @var bool the last deferred submission failed (offer a retry) */
    private $submitFailed = false;

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
        $this->askLog = [];
        foreach (is_array($data['askLog'] ?? null) ? $data['askLog'] : [] as $entry) {
            if (is_array($entry) && isset($entry['step'])) {
                $this->askLog[] = [
                    'step' => (string) $entry['step'],
                    'reason' => is_string($entry['reason'] ?? null) ? $entry['reason'] : AskReason::NEXT,
                    'epoch' => (int) ($entry['epoch'] ?? 0),
                ];
            }
        }
        $this->askTotal = (int) ($data['askTotal'] ?? count($this->askLog));
        $this->hints = is_array($data['hints'] ?? null) ? $data['hints'] : [];
        $this->ended = (bool) ($data['ended'] ?? false);
        $this->submitFailed = (bool) ($data['submitFailed'] ?? false);
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
            'askTotal' => $this->askTotal,
            'hints' => $this->hints,
            'ended' => $this->ended,
            'submitFailed' => $this->submitFailed,
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
            unset($this->answers[$step], $this->hints[$step]);
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
        $this->hints = [];
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

    public function recordAsk(string $step, string $reason = AskReason::NEXT): void
    {
        $this->askLog[] = ['step' => $step, 'reason' => $reason, 'epoch' => $this->epoch];
        $this->askTotal++;
        if (count($this->askLog) > 400) {
            $this->askLog = array_slice($this->askLog, -400);
        }
    }

    /**
     * @return list<array{step: string, reason: string, epoch: int}>
     */
    public function askLog(): array
    {
        return $this->askLog;
    }

    public function askTotal(): int
    {
        return $this->askTotal;
    }

    /**
     * Asks recorded after the log had $total entries (see askTotal()).
     *
     * @return list<array{step: string, reason: string, epoch: int}>
     */
    public function asksSince(int $total): array
    {
        $count = max(0, $this->askTotal - $total);

        return $count === 0 ? [] : array_slice($this->askLog, -min($count, count($this->askLog)));
    }

    /** True when the step was already asked in the current epoch. */
    public function askedInEpoch(string $step): bool
    {
        foreach ($this->askLog as $entry) {
            if ($entry['step'] === $step && $entry['epoch'] === $this->epoch) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function hint(string $step): array
    {
        return $this->hints[$step] ?? [];
    }

    /**
     * @param array<string, mixed> $hint
     */
    public function setHint(string $step, array $hint): void
    {
        if ($hint === []) {
            unset($this->hints[$step]);
        } else {
            $this->hints[$step] = $hint;
        }
    }

    public function clearHint(string $step): void
    {
        unset($this->hints[$step]);
    }

    public function clearHints(): void
    {
        $this->hints = [];
    }

    public function ended(): bool
    {
        return $this->ended;
    }

    public function markEnded(): void
    {
        $this->ended = true;
    }

    public function submitFailed(): bool
    {
        return $this->submitFailed;
    }

    public function setSubmitFailed(bool $failed): void
    {
        $this->submitFailed = $failed;
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
