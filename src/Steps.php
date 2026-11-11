<?php

namespace FaridM\StepChat;

/**
 * One-line presets for the most common step types. Each preset sets both the
 * UI payload the widget renders and the deterministic parse rules, so a
 * typical flow is a handful of lines. Every preset returns a plain
 * StepDefinition: chain ->optional(), ->applies(), ->invalidates() etc. on it.
 */
final class Steps
{
    /**
     * @param array{placeholder?: string, min?: int, max?: int, multiline?: bool, pattern?: string, error?: string} $options
     */
    public static function text(string $key, string $label, array $options = []): StepDefinition
    {
        $min = $options['min'] ?? 1;
        $max = $options['max'] ?? 500;

        return StepDefinition::make($key, $label)
            ->ui(static fn (): array => array_filter([
                'type' => !empty($options['multiline']) ? 'textarea' : 'text',
                'inputType' => 'text',
                'placeholder' => $options['placeholder'] ?? null,
                'maxLength' => $max,
            ], static fn ($v) => $v !== null))
            ->parse(static function (Turn $turn) use ($min, $max, $options): StepResult {
                $value = $turn->text();
                $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
                if ($length < $min || $length > $max) {
                    return StepResult::invalid($options['error'] ?? 'Please enter a valid answer.');
                }
                if (isset($options['pattern']) && !preg_match($options['pattern'], $value)) {
                    return StepResult::invalid($options['error'] ?? 'That answer is not in the expected format.');
                }

                return StepResult::ok($value);
            });
    }

    public static function email(string $key, string $label, string $error = 'That does not look like an email address.'): StepDefinition
    {
        return StepDefinition::make($key, $label)
            ->ui(static fn (): array => ['type' => 'text', 'inputType' => 'email', 'placeholder' => 'name@example.com'])
            ->parse(static fn (Turn $turn): StepResult => filter_var($turn->text(), FILTER_VALIDATE_EMAIL)
                ? StepResult::ok($turn->text())
                : StepResult::invalid($error));
    }

    public static function phone(string $key, string $label, string $error = 'Please enter a valid phone number.'): StepDefinition
    {
        return StepDefinition::make($key, $label)
            ->ui(static fn (): array => ['type' => 'text', 'inputType' => 'tel', 'placeholder' => '+1 555 0100'])
            ->parse(static function (Turn $turn) use ($error): StepResult {
                $value = $turn->text();
                $digits = preg_replace('/\D+/', '', $value) ?? '';

                return preg_match('/^\+?[0-9\s().-]+$/', $value) && strlen($digits) >= 7 && strlen($digits) <= 15
                    ? StepResult::ok($value)
                    : StepResult::invalid($error);
            });
    }

    public static function number(string $key, string $label, ?float $min = null, ?float $max = null, string $error = 'Please enter a valid number.'): StepDefinition
    {
        return StepDefinition::make($key, $label)
            ->ui(static fn (): array => array_filter([
                'type' => 'text', 'inputType' => 'number', 'min' => $min, 'max' => $max,
            ], static fn ($v) => $v !== null))
            ->parse(static function (Turn $turn) use ($min, $max, $error): StepResult {
                $value = $turn->text();
                if (!is_numeric($value)) {
                    return StepResult::invalid($error);
                }
                $number = $value + 0;
                if (($min !== null && $number < $min) || ($max !== null && $number > $max)) {
                    return StepResult::invalid($error);
                }

                return StepResult::ok($number);
            });
    }

    /**
     * @param array<int|string, string> $options list of values, or value => label map
     */
    public static function choice(string $key, string $label, array $options, string $error = 'Please pick one of the options.'): StepDefinition
    {
        $normalised = self::normaliseOptions($options);
        $values = array_column($normalised, 'value');

        return StepDefinition::make($key, $label)
            ->ui(static fn (): array => ['type' => 'choice', 'options' => $normalised])
            ->parse(static fn (Turn $turn): StepResult => in_array($turn->text(), $values, true)
                ? StepResult::ok($turn->text())
                : StepResult::invalid($error))
            ->display(static function ($value) use ($normalised): string {
                foreach ($normalised as $option) {
                    if ($option['value'] === $value) {
                        return $option['label'];
                    }
                }

                return is_scalar($value) ? (string) $value : '';
            });
    }

    /**
     * @param array<int|string, string> $options
     */
    public static function multiChoice(string $key, string $label, array $options, int $min = 1, ?int $max = null, string $error = 'Please pick at least one option.'): StepDefinition
    {
        $normalised = self::normaliseOptions($options);
        $values = array_column($normalised, 'value');

        return StepDefinition::make($key, $label)
            ->ui(static fn (): array => ['type' => 'multi', 'options' => $normalised, 'min' => $min, 'max' => $max])
            ->parse(static function (Turn $turn) use ($values, $min, $max, $error): StepResult {
                $picked = array_values(array_unique($turn->list()));
                if (count($picked) < $min || ($max !== null && count($picked) > $max) || array_diff($picked, $values)) {
                    return StepResult::invalid($error);
                }

                return StepResult::ok($picked);
            })
            ->display(static fn ($value): string => is_array($value) ? implode(', ', $value) : '');
    }

    /**
     * Yes / No question stored as a boolean.
     */
    public static function confirm(string $key, string $label, string $yes = 'Yes', string $no = 'No'): StepDefinition
    {
        return StepDefinition::make($key, $label)
            ->ui(static fn (): array => ['type' => 'choice', 'options' => [
                ['value' => 'yes', 'label' => $yes],
                ['value' => 'no', 'label' => $no],
            ]])
            ->parse(static fn (Turn $turn): StepResult => in_array($turn->text(), ['yes', 'no'], true)
                ? StepResult::ok($turn->text() === 'yes')
                : StepResult::invalid('Please answer yes or no.'))
            ->display(static fn ($value): string => $value ? $yes : $no);
    }

    /**
     * @param array<int|string, mixed> $options
     * @return list<array{value: string, label: string}>
     */
    private static function normaliseOptions(array $options): array
    {
        $out = [];
        foreach ($options as $k => $v) {
            if (is_array($v)) {
                $out[] = ['value' => (string) ($v['value'] ?? ''), 'label' => (string) ($v['label'] ?? $v['value'] ?? '')];
            } elseif (is_int($k)) {
                $out[] = ['value' => (string) $v, 'label' => (string) $v];
            } else {
                $out[] = ['value' => (string) $k, 'label' => (string) $v];
            }
        }

        return $out;
    }
}
