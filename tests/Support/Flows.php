<?php

namespace FaridM\StepChat\Tests\Support;

use FaridM\StepChat\Flow;
use FaridM\StepChat\StepDefinition;
use FaridM\StepChat\StepResult;
use FaridM\StepChat\Turn;

final class Flows
{
    /** A text step that rejects empty input and "?" as unrecognised. */
    public static function text(string $key, ?string $label = null): StepDefinition
    {
        return StepDefinition::make($key, $label ?? ucfirst($key))
            ->parse(static function (Turn $t) use ($key): StepResult {
                $v = $t->text();
                if ($v === '?') {
                    return StepResult::unrecognised('What?');
                }

                return $v === '' ? StepResult::invalid("Enter $key.") : StepResult::ok($v);
            });
    }

    /** A choice step restricted to the given values. */
    public static function choice(string $key, array $values): StepDefinition
    {
        return StepDefinition::make($key, ucfirst($key))
            ->ui(static function () use ($values): array {
                return ['type' => 'choice', 'options' => array_map(static function ($v) {
                    return ['value' => $v, 'label' => strtoupper($v)];
                }, $values)];
            })
            ->parse(static function (Turn $t) use ($values): StepResult {
                $v = strtolower($t->text());

                return in_array($v, $values, true) ? StepResult::ok($v) : StepResult::unrecognised('Pick one.');
            });
    }

    public static function make(): StepDefinition
    {
        return self::text('make');
    }

    public static function lead(): Flow
    {
        return Flow::make()
            ->add(self::text('make'))
            ->add(self::text('model'))
            ->add(self::text('year'));
    }
}
