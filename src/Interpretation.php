<?php

namespace FaridM\StepChat;

/**
 * Immutable result of an interpreter call. Build one with the named
 * constructors (ok, fail, of) and refine it with the with*() methods.
 *
 * Every raw value in answers/corrections is only a *suggestion*: the engine
 * runs it through the target step's own parse before storing anything.
 */
final class Interpretation
{
    public const ANSWER = 'answer';

    public const CORRECTION = 'correction';

    public const QUESTION = 'question';

    public const REQUEST = 'request';

    public const SMALLTALK = 'smalltalk';

    public const UNCLEAR = 'unclear';

    /** @var bool */
    private $failed;

    /** @var mixed */
    private $value;

    /** @var array<string, mixed> */
    private $prefill;

    /** @var array<string, mixed> */
    private $answers = [];

    /** @var array<string, mixed> */
    private $corrections = [];

    /** @var string|null */
    private $revisit;

    /** @var string */
    private $reply = '';

    /** @var array{text: string, answerable: bool, answer: ?string, topic: ?string}|null */
    private $question;

    /** @var array<string, list<string>> */
    private $notes = [];

    /** @var string */
    private $intent = self::ANSWER;

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
     * v1-style result: a value for the unrecognised step plus prefill for others.
     *
     * @param mixed $value
     * @param array<string, mixed> $prefill
     */
    public static function ok($value = null, array $prefill = []): self
    {
        return new self(false, $value, $prefill);
    }

    public static function fail(): self
    {
        return new self(true);
    }

    /**
     * @param array{answers?: array<string, mixed>, corrections?: array<string, mixed>, revisit?: ?string, reply?: string, question?: array<string, mixed>|null, notes?: array<string, list<string>>, intent?: string} $fields
     */
    public static function of(array $fields): self
    {
        $i = new self(false);
        $i->answers = $fields['answers'] ?? [];
        $i->corrections = $fields['corrections'] ?? [];
        $i->revisit = $fields['revisit'] ?? null;
        $i->reply = (string) ($fields['reply'] ?? '');
        $i->question = isset($fields['question']) ? self::normaliseQuestion($fields['question']) : null;
        $i->notes = $fields['notes'] ?? [];
        $i->intent = (string) ($fields['intent'] ?? self::ANSWER);

        return $i;
    }

    /**
     * @param array<string, mixed> $answers
     */
    public function withAnswers(array $answers): self
    {
        $c = clone $this;
        $c->answers = $answers;

        return $c;
    }

    /**
     * @param array<string, mixed> $corrections
     */
    public function withCorrections(array $corrections): self
    {
        $c = clone $this;
        $c->corrections = $corrections;

        return $c;
    }

    public function withRevisit(?string $step): self
    {
        $c = clone $this;
        $c->revisit = $step;

        return $c;
    }

    public function withReply(string $reply): self
    {
        $c = clone $this;
        $c->reply = $reply;

        return $c;
    }

    /**
     * @param array<string, mixed>|null $question text, answerable, answer, topic
     */
    public function withQuestion(?array $question): self
    {
        $c = clone $this;
        $c->question = $question === null ? null : self::normaliseQuestion($question);

        return $c;
    }

    /**
     * @param array<string, list<string>> $notes
     */
    public function withNotes(array $notes): self
    {
        $c = clone $this;
        $c->notes = $notes;

        return $c;
    }

    public function withIntent(string $intent): self
    {
        $c = clone $this;
        $c->intent = $intent;

        return $c;
    }

    public function failed(): bool
    {
        return $this->failed;
    }

    /** @return mixed v1 value for the unrecognised step */
    public function value()
    {
        return $this->value;
    }

    /** @return array<string, mixed> v1 prefill */
    public function prefill(): array
    {
        return $this->prefill;
    }

    /** @return array<string, mixed> raw values for the current or LATER steps */
    public function answers(): array
    {
        return $this->answers;
    }

    /** @return array<string, mixed> raw new values for EARLIER answered steps */
    public function corrections(): array
    {
        return $this->corrections;
    }

    public function revisit(): ?string
    {
        return $this->revisit;
    }

    public function reply(): string
    {
        return $this->reply;
    }

    /** @return array{text: string, answerable: bool, answer: ?string, topic: ?string}|null */
    public function question(): ?array
    {
        return $this->question;
    }

    /** @return array<string, list<string>> */
    public function notes(): array
    {
        return $this->notes;
    }

    public function intent(): string
    {
        return $this->intent;
    }

    /**
     * @param array<string, mixed> $q
     * @return array{text: string, answerable: bool, answer: ?string, topic: ?string}
     */
    private static function normaliseQuestion(array $q): array
    {
        return [
            'text' => (string) ($q['text'] ?? ''),
            'answerable' => (bool) ($q['answerable'] ?? false),
            'answer' => isset($q['answer']) ? (string) $q['answer'] : null,
            'topic' => isset($q['topic']) ? (string) $q['topic'] : null,
        ];
    }
}
