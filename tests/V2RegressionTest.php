<?php

namespace FaridM\StepChat\Tests;

use FaridM\StepChat\ChatState;
use FaridM\StepChat\Contracts\ChatSession;
use FaridM\StepChat\Contracts\InterpretationPolicy;
use FaridM\StepChat\Flow;
use FaridM\StepChat\FlowEngine;
use FaridM\StepChat\Interpretation;
use FaridM\StepChat\StepResult;
use FaridM\StepChat\Support\ArraySession;
use FaridM\StepChat\Support\EnglishMessages;
use FaridM\StepChat\Tests\Support\ClosureInterpreter;
use FaridM\StepChat\Tests\Support\Flows;
use FaridM\StepChat\Tests\Support\RecordingListener;
use FaridM\StepChat\Tests\Support\RecordingTranscript;
use FaridM\StepChat\Turn;
use PHPUnit\Framework\TestCase;

final class V2RegressionTest extends TestCase
{
    private function always(): InterpretationPolicy
    {
        return new class () implements InterpretationPolicy {
            public function shouldInterpret(ChatSession $s, ChatState $st, string $step, Turn $t, StepResult $p): bool
            {
                return true;
            }
        };
    }

    private function start(FlowEngine $engine): ArraySession
    {
        $s = new ArraySession();
        $engine->start($s);

        return $s;
    }

    // 1. revisit even when the current answer parses

    public function test_a_correction_intent_with_a_revisit_goes_back_even_if_the_current_text_parses(): void
    {
        $flow = Flow::make()->add(Flows::text('budget'))->add(Flows::text('year'));
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['intent' => Interpretation::CORRECTION, 'revisit' => 'budget']);
        });
        $listener = new RecordingListener();
        $engine = new FlowEngine($flow, $ai, 200, null, null, null, null, $listener, $this->always());
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('budget', Turn::TEXT, '50'));

        $engine->handleTurn($s, new Turn('year', Turn::TEXT, '2020'));

        $this->assertSame('budget', $s->currentStep());
        $this->assertFalse($s->chatState()->has('year'), 'the current answer is NOT committed');
        $this->assertSame('50', $s->chatState()->answer('budget'));
        $this->assertContains('wentBack:year>budget:ai', $listener->events);
    }

    public function test_a_failed_correction_reopens_its_step_even_if_the_current_text_parses(): void
    {
        $flow = Flow::make()->add(Flows::choice('fuel', ['petrol', 'diesel']))->add(Flows::text('year'));
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['intent' => Interpretation::CORRECTION, 'corrections' => ['fuel' => 'steam']]);
        });
        $engine = new FlowEngine($flow, $ai, 200, null, null, null, null, null, $this->always());
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('fuel', Turn::TEXT, 'petrol'));

        $engine->handleTurn($s, new Turn('year', Turn::TEXT, '2020'));

        $this->assertSame('fuel', $s->currentStep());
        $this->assertFalse($s->chatState()->has('year'));
    }

    public function test_an_applied_correction_does_not_block_the_current_answer(): void
    {
        $flow = Flow::make()->add(Flows::text('budget'))->add(Flows::text('year'))->add(Flows::text('color'));
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['intent' => Interpretation::CORRECTION, 'corrections' => ['budget' => '60']]);
        });
        $engine = new FlowEngine($flow, $ai, 200, null, null, null, null, null, $this->always());
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('budget', Turn::TEXT, '50'));

        $engine->handleTurn($s, new Turn('year', Turn::TEXT, '2020'));

        $this->assertSame('60', $s->chatState()->answer('budget'));
        $this->assertSame('2020', $s->chatState()->answer('year'));
        $this->assertSame('color', $s->currentStep());
    }

    // 2. corrections are observable

    public function test_every_committed_correction_writes_an_event_and_calls_the_listener(): void
    {
        $t = new RecordingTranscript();
        $listener = new RecordingListener();
        $flow = Flow::make()->add(Flows::text('budget'))->add(Flows::text('year'));
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['corrections' => ['budget' => '60']]);
        });
        $engine = new FlowEngine($flow, $ai, 200, $t, null, null, null, $listener);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('budget', Turn::TEXT, '50'));

        $engine->handleTurn($s, new Turn('year', Turn::TEXT, '?'));

        $events = $t->texts('event');
        $this->assertContains('correction "budget" (source: ai_correction)', $events);
        $this->assertContains('corrected:budget:"50">"60"', $listener->events);
    }

    // 3. invalidates only on an existing answer that changes

    public function test_invalidates_does_not_fire_on_a_steps_first_answer(): void
    {
        $flow = Flow::make()
            ->add(Flows::text('intro')->parse(static function (Turn $t): StepResult {
                return StepResult::ok($t->text(), ['km' => '1000']);
            }))
            ->add(Flows::text('year')->invalidates(['km']))
            ->add(Flows::text('km'));
        $engine = new FlowEngine($flow);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('intro', Turn::TEXT, 'hi'));
        $this->assertSame('1000', $s->chatState()->answer('km'));
        $this->assertSame('year', $s->currentStep());

        $engine->handleTurn($s, new Turn('year', Turn::TEXT, '2020'));

        $this->assertSame('1000', $s->chatState()->answer('km'));
        $this->assertSame('done', $s->currentStep(), 'the flow moves past km');
    }

    public function test_invalidates_still_fires_when_an_existing_answer_changes(): void
    {
        $flow = Flow::make()->add(Flows::text('year')->invalidates(['km']))->add(Flows::text('km'));
        $engine = new FlowEngine($flow);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('year', Turn::TEXT, '2020'));
        $engine->handleTurn($s, new Turn('km', Turn::TEXT, '1000'));

        $engine->handleTurn($s, new Turn('done', Turn::EDIT, 'year'));
        $engine->handleTurn($s, new Turn('year', Turn::TEXT, '2021'));

        $this->assertFalse($s->chatState()->has('km'));
        $this->assertSame('km', $s->currentStep());
    }

    // 4. the turn limit shows in ui() immediately

    public function test_ui_reports_ended_as_soon_as_the_limit_is_reached(): void
    {
        $t = new RecordingTranscript();
        $listener = new RecordingListener();
        $engine = new FlowEngine(Flows::lead(), null, 2, $t, null, null, null, $listener);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'Audi'));
        $this->assertNotSame('ended', $engine->ui($s)['type']);

        $engine->handleTurn($s, new Turn('model', Turn::TEXT, 'A4'));

        $ui = $engine->ui($s);
        $this->assertSame('ended', $ui['type']);
        $this->assertSame('This conversation has reached its turn limit.', $ui['text']);
        $this->assertSame('year', $ui['step']);
        $this->assertNotContains('turnLimit', $listener->events, 'message and listener still wait for the next attempt');

        $this->assertTrue($engine->handleTurn($s, new Turn('year', Turn::TEXT, '2020'))->isEnded());
        $this->assertSame(1, count(array_filter($listener->events, static function ($e) {
            return $e === 'turnLimit';
        })));
    }

    // 5. goingBack knows the source

    public function test_going_back_lead_in_can_be_dropped_for_plain_edits(): void
    {
        $sources = [];
        $messages = new class ($sources) extends EnglishMessages {
            /** @var array<int, string> */
            private $sources;

            public function __construct(array &$sources)
            {
                $this->sources = &$sources;
            }

            public function goingBack(string $currentAnswerDisplay, string $source): string
            {
                $this->sources[] = $source;

                return $source === 'edit' ? '' : parent::goingBack($currentAnswerDisplay, $source);
            }
        };
        $t = new RecordingTranscript();
        $engine = new FlowEngine(Flows::lead(), null, 200, $t, $messages);
        $s = $this->start($engine);
        foreach (['make' => 'Audi', 'model' => 'A4', 'year' => '2020'] as $k => $v) {
            $engine->handleTurn($s, new Turn($k, Turn::TEXT, $v));
        }

        $engine->handleTurn($s, new Turn('done', Turn::EDIT, 'make'));

        $this->assertSame(['edit'], $sources);
        $this->assertSame('Make', $t->last('assistant'), 'no lead-in for a plain edit');
    }

    public function test_going_back_passes_detector_and_ai_sources(): void
    {
        $sources = [];
        $messages = new class ($sources) extends EnglishMessages {
            /** @var array<int, string> */
            private $sources;

            public function __construct(array &$sources)
            {
                $this->sources = &$sources;
            }

            public function goingBack(string $currentAnswerDisplay, string $source): string
            {
                $this->sources[] = $source;

                return '';
            }
        };
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['revisit' => 'make']);
        });
        $engine = new FlowEngine(Flows::lead(), $ai, 200, null, $messages);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'Audi'));
        $engine->handleTurn($s, new Turn('model', Turn::TEXT, '?'));

        $this->assertSame(['ai'], $sources);
    }
}
