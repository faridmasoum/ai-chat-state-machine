<?php

namespace Farid\AiChatStateMachine;

/**
 * One user action sent into the flow: a button choice, free text, a list of
 * values (e.g. multi-select), a skip, a request to edit a previous step, or
 * the final submit.
 */
final class Turn
{
    public const CHOICE = 'choice';

    public const TEXT = 'text';

    public const LIST = 'list';

    public const SKIP = 'skip';

    public const EDIT = 'edit';

    public const SUBMIT = 'submit';

    public const TYPES = [self::CHOICE, self::TEXT, self::LIST, self::SKIP, self::EDIT, self::SUBMIT];

    /** @var string */
    public $step;

    /** @var string */
    public $type;

    /** @var mixed */
    public $value;

    /**
     * @param mixed $value
     */
    public function __construct(string $step, string $type, $value = null)
    {
        $this->step = $step;
        $this->type = $type;
        $this->value = $value;
    }

    public function text(): string
    {
        return is_scalar($this->value) ? trim((string) $this->value) : '';
    }

    /**
     * @return list<string>
     */
    public function list(): array
    {
        return is_array($this->value) ? array_values(array_filter($this->value, 'is_string')) : [];
    }

    public function is(string $type): bool
    {
        return $this->type === $type;
    }

    /**
     * The step targeted by an `edit` turn (defaults to the turn's own step).
     */
    public function editTarget(): string
    {
        return is_string($this->value) && $this->value !== '' ? $this->value : $this->step;
    }
}
