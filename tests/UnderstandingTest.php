<?php

namespace FaridM\StepChat\Tests;

use FaridM\StepChat\ChatState;
use FaridM\StepChat\Contracts\ChangeDetector;
use FaridM\StepChat\Contracts\ChatSession;
use FaridM\StepChat\Contracts\HandoffPolicy;
use FaridM\StepChat\Contracts\InterpretationPolicy;
use FaridM\StepChat\Flow;
use FaridM\StepChat\FlowEngine;
use FaridM\StepChat\Handoff;
use FaridM\StepChat\Interpretation;
use FaridM\StepChat\StepDefinition;
use FaridM\StepChat\StepResult;
use FaridM\StepChat\Support\ArraySession;
use FaridM\StepChat\Tests\Support\ClosureInterpreter;
use FaridM\StepChat\Tests\Support\Flows;
use FaridM\StepChat\Tests\Support\RecordingListener;
use FaridM\StepChat\Tests\Support\RecordingTranscript;
use FaridM\StepChat\Turn;
use PHPUnit\Framework\TestCase;

final class UnderstandingTest extends TestCase
{
    private function start(FlowEngine $engine): ArraySession
    {
        $s = new ArraySession();
        $engine->start($s);

        return $s;
    }

    private function always(): InterpretationPolicy
    {
        return new class () implements InterpretationPolicy {
            public function shouldInterpret(ChatSession $s, ChatState $st, string $step, Turn $t, StepResult $p): bool
            {
                return true;
            }
        };
    }

    private function detector(string $prefix, string $target): ChangeDetector
    {
        return new class ($prefix, $target) implements ChangeDetector {
            /** @var string */
            private $prefix;

            /** @var string */
            private $target;

            public function __construct(string $prefix, string $target)
            {
                $this->prefix = $prefix;
                $this->target = $target;
            }

            public function detect(string $text, string $currentStep, ChatState $state): ?string
            {
                return stripos($text, $this->prefix) === 0 ? $this->target : null;
            }
        };
    }

    // ------------------------------------------------ 1. meta-only interpretation

    private function metaFlow(string $mode = StepDefinition::INTERPRET_META): Flow
    {
        return Flow::make()->add(Flows::text('make'))->add(Flows::text('features')->interpretable($mode));
    }

    public function test_interpretable_accepts_a_mode_and_true_false_still_work(): void
    {
        $this->assertSame('full', StepDefinition::make('a', 'A')->interpretMode());
        $this->assertSame('full', StepDefinition::make('a', 'A')->interpretable(true)->interpretMode());
        $this->assertSame('none', StepDefinition::make('a', 'A')->interpretable(false)->interpretMode());
        $this->assertFalse(StepDefinition::make('a', 'A')->interpretable(false)->isInterpretable());
        $this->assertSame('meta', StepDefinition::make('a', 'A')->interpretable(StepDefinition::INTERPRET_META)->interpretMode());
        $this->assertTrue(StepDefinition::make('a', 'A')->interpretable(StepDefinition::INTERPRET_META)->isInterpretable());

        $this->expectException(\InvalidArgumentException::class);
        StepDefinition::make('a', 'A')->interpretable('sometimes');
    }

    public function test_a_meta_step_is_never_interpreted_unless_the_policy_or_a_detected_change_asks(): void
    {
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of([]);
        });
        $engine = new FlowEngine($this->metaFlow(), $ai);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'Audi'));

        $engine->handleTurn($s, new Turn('features', Turn::TEXT, 'tow pack and sunroof'));

        $this->assertSame([], $ai->calls);
        $this->assertSame('tow pack and sunroof', $s->chatState()->answer('features'));
    }

    public function test_a_meta_step_revisit_goes_back_and_the_text_is_not_stored(): void
    {
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['revisit' => 'make', 'intent' => 'correction']);
        });
        $listener = new RecordingListener();
        $engine = new FlowEngine($this->metaFlow(), $ai, 200, null, null, null, null, $listener, $this->always());
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'Audi'));

        $engine->handleTurn($s, new Turn('features', Turn::TEXT, 'wait, I want to change the make'));

        $this->assertSame('make', $s->currentStep());
        $this->assertFalse($s->chatState()->has('features'));
        $this->assertContains('wentBack:features>make:ai', $listener->events);
    }

    public function test_a_meta_step_stores_the_users_own_text_and_ignores_the_ai_answer(): void
    {
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['intent' => 'answer', 'answers' => ['features' => 'REWRITTEN BY AI']]);
        });
        $engine = new FlowEngine($this->metaFlow(), $ai, 200, null, null, null, null, null, $this->always());
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'Audi'));

        $engine->handleTurn($s, new Turn('features', Turn::TEXT, 'tow pack and sunroof'));

        $this->assertCount(2, $ai->calls, 'the policy consulted it on both steps');
        $this->assertSame('tow pack and sunroof', $s->chatState()->answer('features'));
        $this->assertSame('done', $s->currentStep());
    }

    public function test_the_ai_answer_for_a_meta_step_is_ignored_even_when_the_parse_failed(): void
    {
        $flow = Flow::make()->add(Flows::text('features')->interpretable(StepDefinition::INTERPRET_META));
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['answers' => ['features' => 'fixed by ai']]);
        });
        $engine = new FlowEngine($flow, $ai, 200, null, null, null, null, null, $this->always());
        $s = $this->start($engine);

        $r = $engine->handleTurn($s, new Turn('features', Turn::TEXT, ''));

        $this->assertTrue($r->isInvalid());
        $this->assertFalse($s->chatState()->has('features'));
    }

    public function test_a_meta_step_does_not_commit_the_text_when_corrections_were_applied_or_a_side_question_was_asked(): void
    {
        $flow = $this->metaFlow();
        $corr = new ClosureInterpreter(static function () {
            return Interpretation::of(['corrections' => ['make' => 'BMW']]);
        });
        $engine = new FlowEngine($flow, $corr, 200, null, null, null, null, null, $this->always());
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'Audi'));
        $engine->handleTurn($s, new Turn('features', Turn::TEXT, 'actually BMW please'));
        $this->assertSame('BMW', $s->chatState()->answer('make'));
        $this->assertFalse($s->chatState()->has('features'));
        $this->assertSame('features', $s->currentStep());

        $t = new RecordingTranscript();
        $q = new ClosureInterpreter(static function () {
            return Interpretation::of(['question' => ['text' => 'Is a tow pack extra?', 'answerable' => true, 'answer' => 'Yes, it is extra.']]);
        });
        $engine = new FlowEngine($flow, $q, 200, $t, null, null, null, null, $this->always());
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'Audi'));
        $engine->handleTurn($s, new Turn('features', Turn::TEXT, 'is a tow pack extra?'));
        $this->assertFalse($s->chatState()->has('features'));
        $this->assertSame('features', $s->currentStep());
        $this->assertSame("Yes, it is extra.\n\nFeatures", $t->last('assistant'));
    }

    public function test_the_change_detector_runs_on_meta_steps_like_on_full_steps(): void
    {
        $engine = new FlowEngine($this->metaFlow(), null, 200, null, null, $this->detector('wait', 'make'));
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'Audi'));

        $engine->handleTurn($s, new Turn('features', Turn::TEXT, 'wait, I want to change the make'));

        $this->assertSame('make', $s->currentStep());
        $this->assertFalse($s->chatState()->has('features'));
    }

    public function test_none_mode_skips_both_the_interpreter_and_the_detector(): void
    {
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['revisit' => 'make']);
        });
        $engine = new FlowEngine($this->metaFlow('none'), $ai, 200, null, null, $this->detector('wait', 'make'), null, null, $this->always());
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'Audi'));
        $calls = count($ai->calls);

        $engine->handleTurn($s, new Turn('features', Turn::TEXT, 'wait, change the make'));

        $this->assertSame($calls, count($ai->calls));
        $this->assertSame('wait, change the make', $s->chatState()->answer('features'));
    }

    // ------------------------------------------------ 2. interrupts behave like steps

    private function interruptFlow(bool $optional = true, ?array &$applied = null): Flow
    {
        $applied = [];
        $callback = StepDefinition::make('callback', 'Your number?')
            ->optional($optional)
            ->parse(static function (Turn $t): StepResult {
                $v = $t->text();
                if (stripos($v, 'no thanks') !== false) {
                    return StepResult::skip();
                }

                return ctype_digit($v) ? StepResult::ok($v) : StepResult::unrecognised('Digits please.');
            })
            ->afterApply(static function (ChatState $s, $v) use (&$applied) {
                $applied[] = $v;
            });

        return Flow::make()->add(Flows::text('make'))->add(Flows::text('year'))->interrupt($callback);
    }

    private function interruptEngine(Flow $flow, ?ClosureInterpreter &$ai = null, ?RecordingTranscript $t = null, ?ChangeDetector $detector = null, bool $deterministic = false): FlowEngine
    {
        $policy = new class () implements HandoffPolicy {
            public function decide(ChatSession $session, ChatState $state, string $step, array $question): Handoff
            {
                return Handoff::interrupt('callback');
            }
        };
        $ai = new ClosureInterpreter(static function (string $step, string $text) {
            if ($step === 'year') {
                return Interpretation::of(['question' => ['text' => 'call me?', 'answerable' => false]]);
            }
            if (stripos($text, 'finance') !== false) {
                return Interpretation::of(['question' => ['text' => 'finance?', 'answerable' => true, 'answer' => 'Yes, we offer finance.']]);
            }
            if (stripos($text, 'change the make') !== false) {
                return Interpretation::of(['revisit' => 'make', 'reply' => 'Sure.']);
            }

            return Interpretation::of([]);
        });

        return new FlowEngine($flow, $ai, 200, $t, null, $detector, $policy, null, null, true, $deterministic);
    }

    private function intoInterrupt(FlowEngine $engine): ArraySession
    {
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'Audi'));
        $engine->handleTurn($s, new Turn('year', Turn::TEXT, '?'));
        $this->assertSame('callback', $s->currentStep());

        return $s;
    }

    public function test_a_parse_that_returns_skip_on_an_interrupt_is_acknowledged_as_skipped_and_the_flow_resumes(): void
    {
        $t = new RecordingTranscript();
        $applied = [];
        $engine = $this->interruptEngine($this->interruptFlow(true, $applied), $ai, $t);
        $s = $this->intoInterrupt($engine);

        $r = $engine->handleTurn($s, new Turn('callback', Turn::TEXT, 'no thanks'));

        $this->assertFalse($r->isInvalid());
        $this->assertSame([null], $applied, 'afterApply gets the skip value');
        $this->assertSame("No problem.\n\nYear", $t->last('assistant'));
        $this->assertSame('year', $s->currentStep());
        $this->assertFalse($s->chatState()->isInterrupted());
    }

    public function test_skip_uses_the_acknowledge_closure_with_skipped_true_and_an_explicit_ack_wins(): void
    {
        $seen = [];
        $step = StepDefinition::make('callback', 'Number?')->optional(true, 'declined')
            ->parse(static function (Turn $t): StepResult {
                return $t->text() === 'no' ? StepResult::skip() : ($t->text() === 'never' ? StepResult::skip('Understood, no calls.') : StepResult::ok($t->text()));
            })
            ->acknowledge(static function ($v, ChatState $s, bool $skipped) use (&$seen) {
                $seen[] = [$v, $skipped];

                return $skipped ? 'Skipped it.' : 'Saved.';
            });
        $flow = Flow::make()->add($step)->add(Flows::text('after'));
        $t = new RecordingTranscript();
        $engine = new FlowEngine($flow, null, 200, $t);
        $s = $this->start($engine);

        $engine->handleTurn($s, new Turn('callback', Turn::TEXT, 'no'));
        $this->assertSame([['declined', true]], $seen);
        $this->assertSame('declined', $s->chatState()->answer('callback'));
        $this->assertSame("Skipped it.\n\nAfter", $t->last('assistant'));

        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('callback', Turn::TEXT, 'never'));
        $this->assertSame("Understood, no calls.\n\nAfter", $t->last('assistant'));
    }

    public function test_skip_on_a_required_step_is_invalid_with_the_required_message(): void
    {
        $flow = Flow::make()->add(StepDefinition::make('code', 'Code')->parse(static function (): StepResult {
            return StepResult::skip();
        }));
        $engine = new FlowEngine($flow);
        $s = $this->start($engine);

        $r = $engine->handleTurn($s, new Turn('code', Turn::TEXT, 'no thanks'));

        $this->assertTrue($r->isInvalid());
        $this->assertSame('This step is required.', $r->error);
        $this->assertFalse($s->chatState()->has('code'));
    }

    public function test_skip_on_a_required_interrupt_is_invalid(): void
    {
        $engine = $this->interruptEngine($this->interruptFlow(false), $ai);
        $s = $this->intoInterrupt($engine);

        $r = $engine->handleTurn($s, new Turn('callback', Turn::TEXT, 'no thanks'));

        $this->assertTrue($r->isInvalid());
        $this->assertSame('callback', $s->currentStep());
    }

    public function test_a_side_question_on_an_interrupt_is_answered_and_the_interrupt_asked_again(): void
    {
        $t = new RecordingTranscript();
        $engine = $this->interruptEngine($this->interruptFlow(), $ai, $t);
        $s = $this->intoInterrupt($engine);

        $r = $engine->handleTurn($s, new Turn('callback', Turn::TEXT, 'what about finance?'));

        $this->assertFalse($r->isInvalid());
        $this->assertSame('callback', $s->currentStep());
        $this->assertTrue($s->chatState()->isInterrupted());
        $this->assertSame("Yes, we offer finance.\n\nYour number?", $t->last('assistant'));
    }

    public function test_an_unanswerable_side_question_on_an_interrupt_is_never_handed_off_again(): void
    {
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['question' => ['text' => 'can you call?', 'answerable' => false]]);
        });
        $policy = new class () implements HandoffPolicy {
            /** @var int */
            public $calls = 0;

            public function decide(ChatSession $session, ChatState $state, string $step, array $question): Handoff
            {
                $this->calls++;

                return Handoff::interrupt('callback');
            }
        };
        $engine = new FlowEngine($this->interruptFlow(), $ai, 200, null, null, null, $policy);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'Audi'));
        $engine->handleTurn($s, new Turn('year', Turn::TEXT, '?'));
        $this->assertSame(1, $policy->calls);
        $this->assertSame('callback', $s->currentStep());

        $r = $engine->handleTurn($s, new Turn('callback', Turn::TEXT, 'can you call me?'));

        $this->assertFalse($r->isInvalid());
        $this->assertSame('callback', $s->currentStep(), 're-asked');
        $this->assertSame(1, $policy->calls, 'the policy was not asked again');
    }

    public function test_a_revisit_on_an_interrupt_ends_it_and_goes_back(): void
    {
        $engine = $this->interruptEngine($this->interruptFlow(), $ai);
        $s = $this->intoInterrupt($engine);

        $engine->handleTurn($s, new Turn('callback', Turn::TEXT, 'change the make please'));

        $this->assertFalse($s->chatState()->isInterrupted());
        $this->assertSame('make', $s->currentStep());
        $this->assertSame('make', $s->chatState()->editing());
        $this->assertSame('Audi', $s->chatState()->answer('make'));
    }

    public function test_the_detector_applies_on_an_interrupt_and_a_deterministic_correction_ends_it(): void
    {
        $flow = Flow::make()
            ->add(Flows::text('make'))
            ->add(Flows::text('year'))
            ->interrupt(Flows::text('callback')->optional());
        $engine = $this->interruptEngine($flow, $ai, null, $this->detector('actually', 'make'), true);
        $s = $this->intoInterrupt($engine);
        $before = count($ai->calls);

        $engine->handleTurn($s, new Turn('callback', Turn::TEXT, 'actually BMW'));

        $this->assertSame($before, count($ai->calls), 'no interpreter round-trip');
        $this->assertSame('actually BMW', $s->chatState()->answer('make'));
        $this->assertFalse($s->chatState()->isInterrupted());
        $this->assertSame('year', $s->currentStep());
    }

    public function test_a_sensitive_interrupt_is_never_interpreted(): void
    {
        $flow = Flow::make()->add(Flows::text('year'))->interrupt(Flows::text('code')->sensitive());
        $engine = $this->interruptEngine($flow, $ai);
        $s = $this->start($engine);
        $st = $s->chatState();
        $st->startInterrupt('code', 'year');
        $s->putChatState($st);
        $s->setCurrentStep('code');

        $engine->handleTurn($s, new Turn('code', Turn::TEXT, 'what about finance? hunter2'));

        $this->assertSame([], $ai->calls);
        $this->assertSame('year', $s->currentStep());
    }

    // ------------------------------------------------ 3. retry()

    public function test_retry_does_not_count_as_an_attempt_but_invalid_does(): void
    {
        $listener = new RecordingListener();
        $flow = Flow::make()->add(StepDefinition::make('code', 'Code')->parse(static function (Turn $t): StepResult {
            if (strlen($t->text()) > 10) {
                return StepResult::retry('That looks like a sentence, I need the code.');
            }

            return ctype_digit($t->text()) ? StepResult::ok($t->text()) : StepResult::invalid('Digits only.');
        }));
        $engine = new FlowEngine($flow, null, 200, null, null, null, null, $listener);
        $s = $this->start($engine);

        $r = $engine->handleTurn($s, new Turn('code', Turn::TEXT, 'here is a long sentence not a code'));

        $this->assertTrue($r->isInvalid());
        $this->assertSame('That looks like a sentence, I need the code.', $r->error);
        $this->assertSame(0, $s->chatState()->invalidAttempts('code'));
        $this->assertContains('invalid:code', $listener->events);

        $engine->handleTurn($s, new Turn('code', Turn::TEXT, 'abc'));
        $this->assertSame(1, $s->chatState()->invalidAttempts('code'));
    }

    public function test_retry_on_an_interrupt_is_not_counted_either(): void
    {
        $flow = Flow::make()->add(Flows::text('year'))->interrupt(StepDefinition::make('callback', 'Number?')->parse(static function (): StepResult {
            return StepResult::retry('Not a number.');
        }));
        $engine = $this->interruptEngine($flow, $ai);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('year', Turn::TEXT, '?'));

        $engine->handleTurn($s, new Turn('callback', Turn::TEXT, 'hmm'));

        $this->assertSame(0, $s->chatState()->invalidAttempts('callback'));
        $this->assertSame('callback', $s->currentStep());
    }

    // ------------------------------------------------ 4. deterministic corrections

    private function budgetParse(): \Closure
    {
        return static function (Turn $t): StepResult {
            if (preg_match('/(\d+)\s*k/i', $t->text(), $m)) {
                return StepResult::ok((string) ((int) $m[1] * 1000));
            }

            return ctype_digit($t->text()) ? StepResult::ok($t->text()) : StepResult::unrecognised('A number please.');
        };
    }

    private function budgetFlow(): Flow
    {
        return Flow::make()
            ->add(StepDefinition::make('budget', 'Budget')->parse($this->budgetParse())->invalidates(['color']))
            ->add(Flows::text('year'))
            ->add(Flows::text('color'));
    }

    public function test_a_deterministic_correction_updates_the_target_without_calling_the_interpreter(): void
    {
        $t = new RecordingTranscript();
        $listener = new RecordingListener();
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::fail();
        });
        $engine = new FlowEngine($this->budgetFlow(), $ai, 200, $t, null, $this->detector('sorry', 'budget'), null, $listener, null, true, true);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('budget', Turn::TEXT, '50'));
        $epoch = $s->chatState()->epoch();

        $engine->handleTurn($s, new Turn('year', Turn::TEXT, 'sorry, budget is 80k'));

        $this->assertSame([], $ai->calls, 'the interpreter was never called');
        $this->assertSame('80000', $s->chatState()->answer('budget'));
        $this->assertFalse($s->chatState()->has('year'), 'the correction text is not stored as the current answer');
        $this->assertSame('year', $s->currentStep(), 'the chat continues where it was');
        $this->assertGreaterThan($epoch, $s->chatState()->epoch());
        $this->assertSame("Updated: Budget.\n\nYear", $t->last('assistant'));
        $this->assertContains('correction "budget" (source: detector)', $t->texts('event'));
        $this->assertContains('corrected:budget:"50">"80000"', $listener->events);
    }

    public function test_a_deterministic_correction_runs_invalidates_on_change_and_clears_confirmations(): void
    {
        $changed = [];
        $flow = Flow::make()
            ->add(StepDefinition::make('budget', 'Budget')->parse($this->budgetParse())->invalidates(['color'])
                ->onChange(static function (ChatState $s, $old, $new) use (&$changed) {
                    $changed[] = [$old, $new];
                }))
            ->add(Flows::text('color'))
            ->add(Flows::text('review')->confirmation())
            ->add(Flows::text('year'));
        $engine = new FlowEngine($flow, null, 200, null, null, $this->detector('sorry', 'budget'), null, null, null, true, true);
        $s = $this->start($engine);
        foreach (['budget' => '50', 'color' => 'red', 'review' => 'ok'] as $k => $v) {
            $engine->handleTurn($s, new Turn($k, Turn::TEXT, $v));
        }

        $engine->handleTurn($s, new Turn('year', Turn::TEXT, 'sorry 80k'));

        $this->assertSame([['50', '80000']], $changed);
        $this->assertFalse($s->chatState()->has('color'), 'invalidates cleared the dependent');
        $this->assertFalse($s->chatState()->has('review'), 'confirmation cleared');
        $this->assertSame('color', $s->currentStep());
    }

    public function test_without_the_option_a_detected_change_still_goes_back(): void
    {
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::fail();
        });
        $engine = new FlowEngine($this->budgetFlow(), $ai, 200, null, null, $this->detector('sorry', 'budget'));
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('budget', Turn::TEXT, '50'));

        $engine->handleTurn($s, new Turn('year', Turn::TEXT, 'sorry, budget is 80k'));

        $this->assertSame('50', $s->chatState()->answer('budget'));
        $this->assertSame('budget', $s->currentStep());
        $this->assertCount(1, $ai->calls, 'today\'s behaviour: the interpreter is consulted');
    }

    public function test_when_the_target_cannot_parse_the_text_the_old_behaviour_is_kept(): void
    {
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['corrections' => ['budget' => '60']]);
        });
        $engine = new FlowEngine($this->budgetFlow(), $ai, 200, null, null, $this->detector('sorry', 'budget'), null, null, null, true, true);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('budget', Turn::TEXT, '50'));

        $engine->handleTurn($s, new Turn('year', Turn::TEXT, 'sorry, the budget is a bit higher'));

        $this->assertCount(1, $ai->calls);
        $this->assertSame('60', $s->chatState()->answer('budget'));
    }

    public function test_a_value_equal_to_the_stored_answer_is_not_a_correction(): void
    {
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of([]);
        });
        $engine = new FlowEngine($this->budgetFlow(), $ai, 200, null, null, $this->detector('sorry', 'budget'), null, null, null, true, true);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('budget', Turn::TEXT, '50000'));

        $engine->handleTurn($s, new Turn('year', Turn::TEXT, 'sorry, budget 50k'));

        $this->assertCount(1, $ai->calls, 'falls back to the usual path');
    }

    // ------------------------------------------------ 5. unclear() and timing

    private function unclearFlow(string $mode = StepDefinition::INTERPRET_FULL): Flow
    {
        return Flow::make()->add(Flows::text('car')->interpretable($mode));
    }

    public function test_unclear_fires_with_ai_failed(): void
    {
        $listener = new RecordingListener();
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::fail();
        });
        $engine = new FlowEngine($this->unclearFlow(), $ai, 200, null, null, null, null, $listener);
        $s = $this->start($engine);

        $engine->handleTurn($s, new Turn('car', Turn::TEXT, '?'));

        $this->assertSame([['car', '?', 'ai_failed']], $listener->unclear);
        $this->assertCount(1, $listener->timings);
        $this->assertSame('car', $listener->timings[0][0]);
        $this->assertGreaterThanOrEqual(0, $listener->timings[0][1]);
    }

    public function test_unclear_fires_with_ai_unhelpful(): void
    {
        $listener = new RecordingListener();
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['intent' => 'unclear']);
        });
        $engine = new FlowEngine($this->unclearFlow(), $ai, 200, null, null, null, null, $listener);
        $s = $this->start($engine);

        $engine->handleTurn($s, new Turn('car', Turn::TEXT, '?'));

        $this->assertSame([['car', '?', 'ai_unhelpful']], $listener->unclear);
    }

    public function test_unclear_fires_with_not_interpretable(): void
    {
        $listener = new RecordingListener();
        $engine = new FlowEngine($this->unclearFlow('none'), null, 200, null, null, null, null, $listener);
        $s = $this->start($engine);

        $engine->handleTurn($s, new Turn('car', Turn::TEXT, ''));

        $this->assertSame([['car', '', 'not_interpretable']], $listener->unclear);
    }

    public function test_unclear_is_not_fired_for_sensitive_steps_non_text_turns_or_when_the_ai_helped(): void
    {
        $listener = new RecordingListener();
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::fail();
        });
        $flow = Flow::make()->add(Flows::text('code')->sensitive());
        $engine = new FlowEngine($flow, $ai, 200, null, null, null, null, $listener);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('code', Turn::TEXT, '?'));
        $this->assertSame([], $listener->unclear);
        $this->assertSame([], $listener->timings, 'a sensitive step never reached the interpreter');

        $sensitiveNone = new FlowEngine(Flow::make()->add(Flows::text('code')->sensitive()->interpretable(false)), null, 200, null, null, null, null, $listener);
        $s = $this->start($sensitiveNone);
        $sensitiveNone->handleTurn($s, new Turn('code', Turn::TEXT, ''));
        $this->assertSame([], $listener->unclear);

        $q = new ClosureInterpreter(static function () {
            return Interpretation::of(['question' => ['text' => 'q?', 'answerable' => true, 'answer' => 'a']]);
        });
        $engine = new FlowEngine($this->unclearFlow(), $q, 200, null, null, null, null, $listener);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('car', Turn::TEXT, '?'));
        $this->assertSame([], $listener->unclear, 'a side question was answered: the message was understood');
    }

    public function test_the_policy_can_escalate_invalid_results_that_look_like_sentences(): void
    {
        $policy = new class () implements InterpretationPolicy {
            public function shouldInterpret(ChatSession $s, ChatState $st, string $step, Turn $t, StepResult $p): bool
            {
                return !$p->ok && str_word_count($t->text()) >= 4;
            }
        };
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['answers' => ['year' => '2020']]);
        });
        $flow = Flow::make()->add(StepDefinition::make('year', 'Year')->parse(static function (Turn $t): StepResult {
            return preg_match('/^\d{4}$/', $t->text()) ? StepResult::ok($t->text()) : StepResult::invalid('A 4-digit year, please.');
        }));
        $engine = new FlowEngine($flow, $ai, 200, null, null, null, null, null, $policy);
        $s = $this->start($engine);

        $engine->handleTurn($s, new Turn('year', Turn::TEXT, 'abc'));
        $this->assertSame([], $ai->calls, 'a short invalid answer is not escalated');

        $engine->handleTurn($s, new Turn('year', Turn::TEXT, 'it was made in 2020 I think'));
        $this->assertCount(1, $ai->calls);
    }
}
