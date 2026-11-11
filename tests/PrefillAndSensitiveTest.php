<?php

namespace FaridM\StepChat\Tests;

use FaridM\StepChat\ChatState;
use FaridM\StepChat\Contracts\ChangeDetector;
use FaridM\StepChat\Contracts\ChatSession;
use FaridM\StepChat\Contracts\InterpretationPolicy;
use FaridM\StepChat\Flow;
use FaridM\StepChat\FlowEngine;
use FaridM\StepChat\Interpretation;
use FaridM\StepChat\StepDefinition;
use FaridM\StepChat\StepResult;
use FaridM\StepChat\Support\ArraySession;
use FaridM\StepChat\Tests\Support\ClosureInterpreter;
use FaridM\StepChat\Tests\Support\Flows;
use FaridM\StepChat\Tests\Support\RecordingTranscript;
use FaridM\StepChat\Turn;
use PHPUnit\Framework\TestCase;

final class PrefillAndSensitiveTest extends TestCase
{
    private function start(FlowEngine $engine): ArraySession
    {
        $s = new ArraySession();
        $engine->start($s);

        return $s;
    }

    public function test_prefill_is_applied_in_flow_order_so_make_is_stored_before_model_is_parsed(): void
    {
        $flow = Flow::make()
            ->add(Flows::text('first'))
            ->add(Flows::text('make'))
            ->add(Flows::text('model')->prefillUsing(static function ($raw, ChatState $s): StepResult {
                return $s->has('make') ? StepResult::ok($s->answer('make') . ' ' . $raw) : StepResult::invalid('make first');
            }));
        $first = Flows::text('first')->parse(static function (Turn $t): StepResult {
            // The value for "model" is listed BEFORE "make" on purpose.
            return StepResult::ok($t->text(), ['model' => 'A4', 'make' => 'Audi']);
        });
        $flow = Flow::make()->add($first)->add($flow->step('make'))->add($flow->step('model'));
        $engine = new FlowEngine($flow);
        $s = $this->start($engine);

        $engine->handleTurn($s, new Turn('first', Turn::TEXT, 'hi'));

        $this->assertSame('Audi', $s->chatState()->answer('make'));
        $this->assertSame('Audi A4', $s->chatState()->answer('model'));
        $this->assertSame('done', $s->currentStep());
    }

    public function test_prefill_never_overwrites_an_answer_nor_fills_steps_that_do_not_apply_or_exist(): void
    {
        $flow = Flow::make()
            ->add(Flows::text('make')->parse(static function (Turn $t): StepResult {
                return $t->text() === 'BMW'
                    ? StepResult::ok($t->text(), ['model' => 'ZZ', 'ghost' => 'x', 'extra' => 'y'])
                    : StepResult::ok($t->text());
            }))
            ->add(Flows::text('model'))
            ->add(Flows::text('extra')->applies(static function (ChatState $s): bool {
                return false;
            }));
        $engine = new FlowEngine($flow);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'Audi'));
        $engine->handleTurn($s, new Turn('model', Turn::TEXT, 'A4'));
        $this->assertSame('A4', $s->chatState()->answer('model'));

        $engine->handleTurn($s, new Turn('done', Turn::EDIT, 'make'));
        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'BMW'));

        $this->assertSame('A4', $s->chatState()->answer('model'), 'existing answer not overwritten');
        $this->assertFalse($s->chatState()->has('ghost'));
        $this->assertFalse($s->chatState()->has('extra'));
    }

    public function test_a_prefill_that_fails_with_a_hint_stores_the_hint(): void
    {
        $flow = Flow::make()
            ->add(Flows::text('make')->parse(static function (Turn $t): StepResult {
                return StepResult::ok($t->text(), ['tags' => 'a,b']);
            }))
            ->add(Flows::text('tags')->prefillUsing(static function ($raw): StepResult {
                return StepResult::invalid('Pick all', ['selected' => ['a']]);
            }));
        $engine = new FlowEngine($flow);
        $s = $this->start($engine);

        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'Audi'));

        $this->assertFalse($s->chatState()->has('tags'));
        $this->assertSame(['selected' => ['a']], $s->chatState()->hint('tags'));
        $this->assertSame('tags', $s->currentStep());
    }

    public function test_hints_survive_an_invalid_answer_and_are_cleared_on_commit(): void
    {
        $flow = Flow::make()->add(StepDefinition::make('tags', 'Tags')
            ->ui(static function (ChatState $s): array {
                return ['type' => 'multi', 'selected' => $s->hint('tags')['selected'] ?? []];
            })
            ->parse(static function (Turn $t): StepResult {
                return count($t->list()) >= 2 ? StepResult::ok($t->list()) : StepResult::invalid('Pick two', ['selected' => $t->list()]);
            }));
        $engine = new FlowEngine($flow);
        $s = $this->start($engine);

        $engine->handleTurn($s, new Turn('tags', Turn::LIST, ['a']));
        $this->assertSame(['a'], $engine->ui($s)['selected']);

        $engine->handleTurn($s, new Turn('tags', Turn::LIST, ['a', 'b']));
        $this->assertSame([], $s->chatState()->hint('tags'));
    }

    public function test_ai_answers_for_later_steps_are_prefilled_through_their_own_parse(): void
    {
        $policy = new class () implements InterpretationPolicy {
            public function shouldInterpret(ChatSession $s, ChatState $st, string $step, Turn $t, StepResult $p): bool
            {
                return true;
            }
        };
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['answers' => ['fuel' => 'DIESEL', 'color' => 'blue']]);
        });
        $flow = Flow::make()
            ->add(Flows::text('make'))
            ->add(Flows::choice('fuel', ['petrol', 'diesel']))
            ->add(Flows::choice('color', ['red', 'green']));
        $engine = new FlowEngine($flow, $ai, 200, null, null, null, null, null, $policy);
        $s = $this->start($engine);

        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'Audi diesel blue'));

        $this->assertSame('diesel', $s->chatState()->answer('fuel'));
        $this->assertFalse($s->chatState()->has('color'), '"blue" is not a valid colour');
        $this->assertSame('color', $s->currentStep());
    }

    public function test_sensitive_values_never_reach_the_transcript_the_interpreter_or_the_detector(): void
    {
        $t = new RecordingTranscript();
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['answers' => ['name' => 'Ann']]);
        });
        $seenByDetector = [];
        $detector = new class ($seenByDetector) implements ChangeDetector {
            /** @var array<int, string> */
            private $seen;

            public function __construct(array &$seen)
            {
                $this->seen = &$seen;
            }

            public function detect(string $text, string $currentStep, ChatState $state): ?string
            {
                $this->seen[] = $text . '|' . json_encode($state->answers());

                return null;
            }
        };
        $flow = Flow::make()
            ->add(Flows::text('code')->sensitive())
            ->add(Flows::text('name'));
        $engine = new FlowEngine($flow, $ai, 200, $t, null, $detector);
        $s = $this->start($engine);

        $engine->handleTurn($s, new Turn('code', Turn::TEXT, 'hunter2'));
        $engine->handleTurn($s, new Turn('name', Turn::TEXT, '?'));

        $this->assertStringNotContainsString('hunter2', $t->dump());
        $this->assertSame(['••••••'], array_slice($t->texts('user'), 0, 1));
        $this->assertSame('hunter2', $s->chatState()->answer('code'), 'the answer itself is stored');
        $this->assertCount(1, $ai->calls, 'only the non-sensitive step reached the interpreter');
        $this->assertArrayNotHasKey('code', $ai->calls[0][2]->answers(), 'stored secrets are hidden from the interpreter');
        $this->assertSame(['?|[]'], $seenByDetector);
    }

    public function test_sensitive_unless_lets_an_escape_input_through(): void
    {
        $t = new RecordingTranscript();
        $flow = Flow::make()->add(Flows::text('code')->sensitiveUnless(static function (Turn $turn): bool {
            return $turn->text() === 'continue as buyer';
        }));
        $engine = new FlowEngine($flow, null, 200, $t);
        $s = $this->start($engine);

        $engine->handleTurn($s, new Turn('code', Turn::TEXT, 'continue as buyer'));
        $this->assertSame('continue as buyer', $t->last('user'));

        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('code', Turn::TEXT, '1234'));
        $this->assertSame('••••••', $t->last('user'));
    }
}
