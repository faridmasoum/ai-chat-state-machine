<?php

namespace FaridM\StepChat\Tests;

use FaridM\StepChat\ChatState;
use FaridM\StepChat\Contracts\ChangeDetector;
use FaridM\StepChat\Contracts\ChatSession;
use FaridM\StepChat\Contracts\HandoffPolicy;
use FaridM\StepChat\Contracts\InterpretationPolicy;
use FaridM\StepChat\Contracts\TurnInterpreter;
use FaridM\StepChat\Flow;
use FaridM\StepChat\FlowEngine;
use FaridM\StepChat\Handoff;
use FaridM\StepChat\Interpretation;
use FaridM\StepChat\StepResult;
use FaridM\StepChat\Support\ArraySession;
use FaridM\StepChat\Tests\Support\ClosureInterpreter;
use FaridM\StepChat\Tests\Support\Flows;
use FaridM\StepChat\Tests\Support\RecordingListener;
use FaridM\StepChat\Tests\Support\RecordingTranscript;
use FaridM\StepChat\Turn;
use PHPUnit\Framework\TestCase;

final class InterpretationTest extends TestCase
{
    private function fuelFlow(): Flow
    {
        return Flow::make()
            ->add(Flows::choice('fuel', ['petrol', 'diesel']))
            ->add(Flows::text('year'));
    }

    private function start(FlowEngine $engine): ArraySession
    {
        $s = new ArraySession();
        $engine->start($s);

        return $s;
    }

    public function test_an_ai_value_rejected_by_the_steps_parse_is_not_stored(): void
    {
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['answers' => ['fuel' => 'banana']]);
        });
        $engine = new FlowEngine($this->fuelFlow(), $ai);
        $s = $this->start($engine);

        $r = $engine->handleTurn($s, new Turn('fuel', Turn::TEXT, 'gasoil maybe'));

        $this->assertTrue($r->isInvalid());
        $this->assertFalse($s->chatState()->has('fuel'));
        $this->assertSame('fuel', $s->currentStep());
    }

    public function test_an_ai_value_is_stored_only_after_the_steps_own_parse(): void
    {
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['answers' => ['fuel' => 'DIESEL']]);
        });
        $engine = new FlowEngine($this->fuelFlow(), $ai);
        $s = $this->start($engine);

        $engine->handleTurn($s, new Turn('fuel', Turn::TEXT, 'gasoil'));

        $this->assertSame('diesel', $s->chatState()->answer('fuel'), 'normalised by the step parse, not stored raw');
        $this->assertSame('year', $s->currentStep());
    }

    public function test_a_successful_deterministic_parse_beats_the_ai(): void
    {
        $policy = new class () implements InterpretationPolicy {
            public function shouldInterpret(ChatSession $s, ChatState $st, string $step, Turn $t, StepResult $p): bool
            {
                return true;
            }
        };
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['answers' => ['fuel' => 'diesel', 'year' => '2020']]);
        });
        $engine = new FlowEngine($this->fuelFlow(), $ai, 200, null, null, null, null, null, $policy);
        $s = $this->start($engine);

        $engine->handleTurn($s, new Turn('fuel', Turn::TEXT, 'petrol'));

        $this->assertCount(1, $ai->calls, 'the policy made the engine consult the interpreter');
        $this->assertSame('petrol', $s->chatState()->answer('fuel'));
        $this->assertSame('2020', $s->chatState()->answer('year'), 'but later answers are still prefilled');
    }

    public function test_the_interpreter_is_not_consulted_without_a_text_turn_unrecognised_input_or_policy(): void
    {
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::fail();
        });
        $engine = new FlowEngine($this->fuelFlow(), $ai);
        $s = $this->start($engine);

        $engine->handleTurn($s, new Turn('fuel', Turn::CHOICE, 'nope'));
        $engine->handleTurn($s, new Turn('fuel', Turn::TEXT, 'petrol'));
        $this->assertSame([], $ai->calls);
    }

    public function test_a_failed_interpretation_uses_the_fallback_message(): void
    {
        $t = new RecordingTranscript();
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::fail();
        });
        $engine = new FlowEngine($this->fuelFlow(), $ai, 200, $t);
        $s = $this->start($engine);

        $r = $engine->handleTurn($s, new Turn('fuel', Turn::TEXT, 'hmm'));

        $this->assertSame("Sorry, I couldn't understand that. Could you answer in another way?", $r->error);
    }

    public function test_non_interpretable_steps_never_reach_the_interpreter(): void
    {
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['answers' => ['notes' => 'x']]);
        });
        $flow = Flow::make()->add(Flows::text('notes')->interpretable(false));
        $engine = new FlowEngine($flow, $ai);
        $s = $this->start($engine);

        $engine->handleTurn($s, new Turn('notes', Turn::TEXT, '?'));
        $this->assertSame([], $ai->calls);
    }

    public function test_a_v1_interpreter_is_wrapped_by_an_adapter(): void
    {
        $legacy = new class () implements TurnInterpreter {
            public function interpret(ChatState $state, string $step, string $text): Interpretation
            {
                return Interpretation::ok('diesel', ['year' => '2020']);
            }
        };
        $engine = new FlowEngine($this->fuelFlow(), $legacy);
        $s = $this->start($engine);

        $engine->handleTurn($s, new Turn('fuel', Turn::TEXT, 'gasoil'));

        $this->assertSame('diesel', $s->chatState()->answer('fuel'));
        $this->assertSame('2020', $s->chatState()->answer('year'));
        $this->assertSame('done', $s->currentStep());
    }

    public function test_corrections_update_earlier_answers_clear_dependents_and_confirmation_steps(): void
    {
        $t = new RecordingTranscript();
        $flow = Flow::make()
            ->add(Flows::text('make')->invalidates(['model']))
            ->add(Flows::text('model'))
            ->add(Flows::text('review')->confirmation())
            ->add(Flows::text('year'));
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['corrections' => ['make' => 'BMW'], 'reply' => 'Got it.']);
        });
        $listener = new RecordingListener();
        $engine = new FlowEngine($flow, $ai, 200, $t, null, null, null, $listener);
        $s = $this->start($engine);
        foreach ([['make', 'Audi'], ['model', 'A4'], ['review', 'ok']] as [$k, $v]) {
            $engine->handleTurn($s, new Turn($k, Turn::TEXT, $v));
        }
        $this->assertSame('year', $s->currentStep());
        $epoch = $s->chatState()->epoch();

        $engine->handleTurn($s, new Turn('year', Turn::TEXT, '?'));

        $st = $s->chatState();
        $this->assertSame('BMW', $st->answer('make'));
        $this->assertFalse($st->has('model'), 'invalidates cleared the dependent');
        $this->assertFalse($st->has('review'), 'confirmation step cleared');
        $this->assertGreaterThan($epoch, $st->epoch());
        $this->assertSame('model', $s->currentStep());
        $this->assertSame("Got it.\n\nUpdated: Make.\n\nModel", $t->last('assistant'));
        $this->assertContains('interpreted', $listener->events);
    }

    public function test_a_correction_that_fails_the_steps_parse_is_not_applied_and_reopens_the_step(): void
    {
        $flow = Flow::make()
            ->add(Flows::choice('fuel', ['petrol', 'diesel']))
            ->add(Flows::text('year'));
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['corrections' => ['fuel' => 'steam']]);
        });
        $listener = new RecordingListener();
        $engine = new FlowEngine($flow, $ai, 200, null, null, null, null, $listener);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('fuel', Turn::TEXT, 'petrol'));

        $engine->handleTurn($s, new Turn('year', Turn::TEXT, '?'));

        $this->assertSame('petrol', $s->chatState()->answer('fuel'));
        $this->assertSame('fuel', $s->currentStep());
        $this->assertSame('fuel', $s->chatState()->editing());
        $this->assertContains('wentBack:year>fuel:ai_correction', $listener->events);
    }

    public function test_revisit_goes_back_and_keeps_later_answers(): void
    {
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['revisit' => 'make']);
        });
        $engine = new FlowEngine(Flows::lead(), $ai);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'Audi'));
        $engine->handleTurn($s, new Turn('model', Turn::TEXT, 'A4'));

        $engine->handleTurn($s, new Turn('year', Turn::TEXT, '?'));
        $this->assertSame('make', $s->currentStep());
        $this->assertSame('A4', $s->chatState()->answer('model'), 'later answers stay');

        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'BMW'));
        $this->assertSame('year', $s->currentStep(), 'goes straight on to the first unanswered step');
        $this->assertSame('A4', $s->chatState()->answer('model'));
    }

    public function test_a_change_detector_goes_back_without_any_ai(): void
    {
        $t = new RecordingTranscript();
        $detector = new class () implements ChangeDetector {
            public function detect(string $text, string $currentStep, ChatState $state): ?string
            {
                return stripos($text, 'actually') === 0 ? 'make' : null;
            }
        };
        $listener = new RecordingListener();
        $engine = new FlowEngine(Flows::lead(), null, 200, $t, null, $detector, null, $listener);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'Audi'));
        $engine->handleTurn($s, new Turn('model', Turn::TEXT, 'A4'));

        $engine->handleTurn($s, new Turn('year', Turn::TEXT, 'actually a Toyota'));

        $this->assertSame('make', $s->currentStep());
        $this->assertFalse($s->chatState()->has('year'), 'the change request was not stored as an answer');
        $this->assertSame("Sure, it's currently \"Audi\". Let's change it.\n\nMake", $t->last('assistant'));
        $this->assertContains('wentBack:year>make:detector', $listener->events);
        $this->assertSame('goback', $s->chatState()->askLog()[count($s->chatState()->askLog()) - 1]['reason']);
    }

    public function test_a_detected_change_lets_the_ai_supply_the_new_value(): void
    {
        $detector = new class () implements ChangeDetector {
            public function detect(string $text, string $currentStep, ChatState $state): ?string
            {
                return stripos($text, 'actually') === 0 ? 'make' : null;
            }
        };
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['corrections' => ['make' => 'Toyota']]);
        });
        $engine = new FlowEngine(Flows::lead(), $ai, 200, null, null, $detector);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'Audi'));
        $engine->handleTurn($s, new Turn('model', Turn::TEXT, 'A4'));

        $engine->handleTurn($s, new Turn('year', Turn::TEXT, "actually it's a Toyota"));

        $this->assertSame('Toyota', $s->chatState()->answer('make'));
        $this->assertSame('year', $s->currentStep());
        $this->assertFalse($s->chatState()->has('year'));
    }

    public function test_an_answerable_side_question_is_answered_and_the_step_re_asked(): void
    {
        $t = new RecordingTranscript();
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['question' => ['text' => 'Is it free?', 'answerable' => true, 'answer' => 'Yes, it is free.']]);
        });
        $engine = new FlowEngine($this->fuelFlow(), $ai, 200, $t);
        $s = $this->start($engine);

        $r = $engine->handleTurn($s, new Turn('fuel', Turn::TEXT, 'is it free?'));

        $this->assertFalse($r->isInvalid());
        $this->assertSame('fuel', $s->currentStep());
        $this->assertSame("Yes, it is free.\n\nFuel", $t->last('assistant'));
    }

    public function test_an_unanswerable_side_question_uses_the_handoff_policy(): void
    {
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['question' => ['text' => 'Can you call me?', 'answerable' => false]]);
        });
        $t = new RecordingTranscript();
        $engine = new FlowEngine($this->fuelFlow(), $ai, 200, $t);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('fuel', Turn::TEXT, 'can you call me?'));
        $this->assertStringStartsWith("Sorry, I couldn't understand that", $t->last('assistant'));
        $this->assertSame('fuel', $s->currentStep());

        $policy = new class () implements HandoffPolicy {
            public function decide(ChatSession $session, ChatState $state, string $step, array $question): Handoff
            {
                return Handoff::interrupt('callback');
            }
        };
        $flow = $this->fuelFlow()->interrupt(Flows::text('callback')->optional());
        $engine = new FlowEngine($flow, $ai, 200, null, null, null, $policy);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('fuel', Turn::TEXT, 'can you call me?'));
        $this->assertSame('callback', $s->currentStep());
        $this->assertTrue($s->chatState()->isInterrupted());
    }

    public function test_a_side_question_next_to_a_valid_answer_leads_into_the_next_question(): void
    {
        $t = new RecordingTranscript();
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['question' => ['text' => 'Free?', 'answerable' => true, 'answer' => 'It is free.']]);
        });
        $policy = new class () implements InterpretationPolicy {
            public function shouldInterpret(ChatSession $s, ChatState $st, string $step, Turn $t, StepResult $p): bool
            {
                return true;
            }
        };
        $engine = new FlowEngine($this->fuelFlow(), $ai, 200, $t, null, null, null, null, $policy);
        $s = $this->start($engine);

        $engine->handleTurn($s, new Turn('fuel', Turn::TEXT, 'petrol'));

        $this->assertSame('petrol', $s->chatState()->answer('fuel'));
        $this->assertSame("It is free.\n\nYear", $t->last('assistant'));
    }

    public function test_the_interpretation_value_object_is_immutable(): void
    {
        $a = Interpretation::of(['reply' => 'Hi']);
        $b = $a->withReply('Bye')->withIntent(Interpretation::QUESTION)->withNotes(['requests' => ['x']]);

        $this->assertSame('Hi', $a->reply());
        $this->assertSame('Bye', $b->reply());
        $this->assertSame(Interpretation::QUESTION, $b->intent());
        $this->assertSame(['requests' => ['x']], $b->notes());
        $this->assertFalse(Interpretation::ok('v')->failed());
        $this->assertTrue(Interpretation::fail()->failed());
    }
}
