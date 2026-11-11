<?php

namespace FaridM\StepChat\Engine;

use FaridM\StepChat\ChatState;
use FaridM\StepChat\Contracts\Messages;
use FaridM\StepChat\StepDefinition;
use FaridM\StepChat\Turn;

/**
 * How a turn is shown in the transcript when a step defines no echo():
 * choices as their option label, lists as comma-joined labels, skips as
 * Messages::skipped(), text as typed. @internal
 */
final class DefaultEcho
{
    public static function echo(StepDefinition $step, Turn $turn, ChatState $state, Messages $messages): string
    {
        if ($turn->is(Turn::SKIP)) {
            return $messages->skipped();
        }

        $options = $step->stepUi($state)['options'] ?? [];
        $options = is_array($options) ? $options : [];

        if ($turn->is(Turn::LIST)) {
            return implode(', ', array_map(static function ($v) use ($options): string {
                return self::label($options, $v);
            }, $turn->list()));
        }

        if ($turn->is(Turn::CHOICE)) {
            return self::label($options, $turn->value);
        }

        return $turn->text();
    }

    /**
     * @param array<mixed> $options list of {value,label} or plain scalars
     * @param mixed $value
     */
    private static function label(array $options, $value): string
    {
        $needle = is_bool($value) ? ($value ? 'yes' : 'no') : (is_scalar($value) ? (string) $value : '');
        foreach ($options as $option) {
            if (is_array($option) && array_key_exists('value', $option)) {
                $v = $option['value'];
                $v = is_bool($v) ? ($v ? 'yes' : 'no') : (is_scalar($v) ? (string) $v : null);
                if ($v === $needle) {
                    return (string) ($option['label'] ?? $needle);
                }
            }
        }

        return $needle;
    }
}
