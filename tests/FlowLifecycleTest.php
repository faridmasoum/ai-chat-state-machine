<?php

namespace FaridM\StepChat\Tests;

use FaridM\StepChat\AskReason;
use FaridM\StepChat\ChatState;
use FaridM\StepChat\Contracts\ChatSession;
use FaridM\StepChat\Contracts\HandoffPolicy;
use FaridM\StepChat\Contracts\Messages;
use FaridM\StepChat\Engine\InvariantChecker;
use FaridM\StepChat\Exceptions\InvalidTurnException;
use FaridM\StepChat\Flow;
use FaridM\StepChat\FlowEngine;
use FaridM\StepChat\Handoff;
use FaridM\StepChat\Interpretation;
use FaridM\StepChat\StepDefinition;
use FaridM\StepChat\StepResult;
use FaridM\StepChat\Support\ArraySession;
use FaridM\StepChat\Support\EnglishMessages;
use FaridM\StepChat\Tests\Support\ClosureInterpreter;
use FaridM\StepChat\Tests\Support\Flows;
use FaridM\StepChat\Tests\Support\RecordingListener;
use FaridM\StepChat\Tests\Support\RecordingTranscript;
use FaridM\StepChat\Turn;
use FaridM\StepChat\TurnResult;
use PHPUnit\Framework\TestCase;

final class FlowLifecycleTest extends TestCase
{
    private function start(FlowEngine $engine): ArraySession
    {
        $s = new ArraySession();
        $engine->start($s);

        return $s;
    }

    // ---------------------------------------------------------- transcript

    public function test_start_writes_the_greeting_then_the_first_question_and_every_turn_echoes_first(): void
    {
        $t = new RecordingTranscript();
        $flow = Flows::lead()->greeting('Welcome!');
        $engine = new FlowEngine($flow, null, 200, $t);
        $s = $this->start($engine);

        $this->assertSame(['Welcome!', 'Make'], $t->texts('assistant'));

        $engine->handleTurn($s, new Turn('make', Turn::TEXT, ''));
        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'Audi'));

        $this->assertSame(
            [['assistant', 'Welcome!'], ['assistant', 'Make'], ['user', ''], ['assistant', "Enter make.\n\nMake"], ['user', 'Audi'], ['assistant', 'Model']],
            array_map(static function (array $r): array {
                return [$r[0], $r[2]];
            }, $t->log)
        );
        $this->assertSame('error', $t->log[3][3]);
        $this->assertSame('question', $t->log[5][3]);
    }

    public function test_messages_are_overridable(): void
    {
        $messages = new class () extends EnglishMessages {
            public function required(): string
            {
                return 'Obligatoire.';
            }
        };
        $flow = Flow::make()->add(Flows::text('a'));
        $engine = new FlowEngine($flow, null, 200, null, $messages);
        $s = $this->start($engine);

        $this->assertSame('Obligatoire.', $engine->handleTurn($s, new Turn('a', Turn::SKIP))->error);
    }

    public function test_the_english_messages_cover_the_catalogue(): void
    {
        $m = new EnglishMessages();
        $this->assertInstanceOf(Messages::class, $m);
        $this->assertSame('Updated: A, B.', $m->updated(['A', 'B']));
        $this->assertSame("Sure, let's change that.", $m->goingBack(''));
        $this->assertSame('Try again', $m->submitLabel(new ChatState(), true));
        $this->assertSame('Submit', $m->submitLabel(new ChatState(), false));
    }

    // ------------------------------------------------------- review / submit

    private function consentFlow(): Flow
    {
        return Flow::make()
            ->add(Flows::text('a'))
            ->add(StepDefinition::make('review', 'Review')->confirmation()
                ->ui(static function (): array {
                    return ['type' => 'choice', 'options' => [['value' => 'confirm', 'label' => 'Looks good']]];
                })
                ->parse(static function (Turn $t): StepResult {
                    return $t->value === 'confirm' ? StepResult::ok(true) : StepResult::unrecognised('Confirm please');
                })
                ->allowsEdits())
            ->add(StepDefinition::make('consent', 'Consent')->confirmation()->allowsEdits()
                ->ui(static function (): array {
                    return ['type' => 'consent'];
                }))
            ->submitStep('consent');
    }

    public function test_submit_is_only_valid_on_the_submit_step_with_true(): void
    {
        $engine = new FlowEngine($this->consentFlow());
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('a', Turn::TEXT, 'x'));
        $engine->handleTurn($s, new Turn('review', Turn::CHOICE, 'confirm'));
        $this->assertSame('consent', $s->currentStep());

        try {
            $engine->handleTurn($s, new Turn('consent', Turn::SUBMIT, false));
            $this->fail('expected InvalidTurnException');
        } catch (InvalidTurnException $e) {
            $this->assertFalse($s->isCompleted());
        }
    }

    public function test_submit_on_the_submit_step_completes_and_stores_the_consent_answer(): void
    {
        $answers = null;
        $flow = $this->consentFlow()->onComplete(static function (array $a) use (&$answers) {
            $answers = $a;
        });
        $t = new RecordingTranscript();
        $engine = new FlowEngine($flow, null, 200, $t);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('a', Turn::TEXT, 'x'));
        $engine->handleTurn($s, new Turn('review', Turn::CHOICE, 'confirm'));

        $r = $engine->handleTurn($s, new Turn('consent', Turn::SUBMIT, true));

        $this->assertTrue($r->isCompleted());
        $this->assertTrue($s->isCompleted());
        $this->assertTrue($answers['consent']);
        $this->assertSame('Thank you! Your request has been submitted.', $t->last('assistant'));
        $this->assertSame('done', $engine->ui($s)['type']);
    }

    public function test_without_a_submit_step_submit_works_on_done_as_in_v1(): void
    {
        $done = 0;
        $flow = Flow::make()->add(Flows::text('a'))->onComplete(static function () use (&$done) {
            $done++;
        });
        $engine = new FlowEngine($flow);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('a', Turn::TEXT, 'x'));

        $this->assertSame('done', $s->currentStep());
        $this->assertTrue($engine->handleTurn($s, new Turn('done', Turn::SUBMIT, true))->isCompleted());
        $this->assertSame(1, $done);
    }

    public function test_edits_are_only_accepted_from_done_or_allows_edits_steps_and_clear_confirmations(): void
    {
        $engine = new FlowEngine($this->consentFlow()->add(Flows::text('late')->allowsEdits(false)));
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('a', Turn::TEXT, 'x'));
        $this->assertSame('review', $s->currentStep());

        $engine->handleTurn($s, new Turn('review', Turn::CHOICE, 'confirm'));
        $this->assertTrue($s->chatState()->has('review'));
        $epoch = $s->chatState()->epoch();

        // Back from the consent step to the review (a confirmation step): allowed.
        $engine->handleTurn($s, new Turn('consent', Turn::EDIT, 'review'));
        $this->assertSame('review', $s->currentStep());
        $this->assertFalse($s->chatState()->has('review'), 'edit cleared the confirmation answer');
        $this->assertGreaterThan($epoch, $s->chatState()->epoch());

        // From a step without allowsEdits: rejected.
        $flow = Flow::make()->add(Flows::text('a'))->add(Flows::text('b'));
        $plain = new FlowEngine($flow);
        $s2 = $this->start($plain);
        $plain->handleTurn($s2, new Turn('a', Turn::TEXT, 'x'));
        $this->expectException(InvalidTurnException::class);
        $plain->handleTurn($s2, new Turn('b', Turn::EDIT, 'a'));
    }

    // ------------------------------------------------------------ interrupt

    /**
     * @param array<int, mixed> $applied collects the values passed to the interrupt step's afterApply
     */
    private function interruptEngine(?RecordingTranscript $t = null, ?array &$applied = null, bool $optional = true): FlowEngine
    {
        $applied = [];
        $policy = new class () implements HandoffPolicy {
            public function decide(ChatSession $session, ChatState $state, string $step, array $question): Handoff
            {
                return Handoff::interrupt('callback');
            }
        };
        $ai = new ClosureInterpreter(static function () {
            return Interpretation::of(['question' => ['text' => 'call me?', 'answerable' => false]]);
        });
        $callback = Flows::text('callback')->optional($optional)->afterApply(static function (ChatState $s, $v) use (&$applied) {
            $applied[] = $v;
        });
        $flow = Flow::make()->add(Flows::text('year'))->add(Flows::text('name'))->interrupt($callback);

        return new FlowEngine($flow, $ai, 200, $t, null, null, $policy);
    }

    public function test_an_interrupt_can_be_skipped_then_the_step_is_re_asked(): void
    {
        $t = new RecordingTranscript();
        $engine = $this->interruptEngine($t);
        $s = $this->start($engine);

        $engine->handleTurn($s, new Turn('year', Turn::TEXT, '?'));
        $this->assertSame('callback', $s->currentStep());
        $this->assertSame(['current' => 1, 'total' => 2], $engine->progress($s), 'progress keeps the resume step');

        $r = $engine->handleTurn($s, new Turn('callback', Turn::SKIP));

        $this->assertFalse($r->isInvalid());
        $this->assertSame('year', $s->currentStep());
        $this->assertFalse($s->chatState()->isInterrupted());
        $this->assertSame("No problem.\n\nYear", $t->last('assistant'));
        $log = $s->chatState()->askLog();
        $this->assertSame(AskReason::REASK, $log[count($log) - 1]['reason']);
        $this->assertFalse($s->chatState()->has('callback'), 'interrupt values are not stored as answers');
    }

    public function test_an_interrupt_answer_runs_after_apply_acknowledges_and_resumes(): void
    {
        $t = new RecordingTranscript();
        $applied = [];
        $engine = $this->interruptEngine($t, $applied);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('year', Turn::TEXT, '?'));

        $engine->handleTurn($s, new Turn('callback', Turn::TEXT, '555 0100'));

        $this->assertSame(['555 0100'], $applied);
        $this->assertSame("Thanks, we'll be in touch on 555 0100.\n\nYear", $t->last('assistant'));
        $this->assertSame('year', $s->currentStep());
        $this->assertFalse($s->chatState()->has('callback'));
    }

    public function test_an_interrupt_marked_stores_answer_keeps_the_value(): void
    {
        $flow = Flow::make()->add(Flows::text('year'))->interrupt(Flows::text('callback')->storesAnswer());
        $engine = new FlowEngine($flow, new ClosureInterpreter(static function () {
            return Interpretation::of(['question' => ['text' => 'call me?', 'answerable' => false]]);
        }), 200, null, null, null, new class () implements HandoffPolicy {
            public function decide(ChatSession $session, ChatState $state, string $step, array $question): Handoff
            {
                return Handoff::interrupt('callback');
            }
        });
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('year', Turn::TEXT, '?'));
        $engine->handleTurn($s, new Turn('callback', Turn::TEXT, '555'));

        $this->assertSame('555', $s->chatState()->answer('callback'));
    }

    public function test_an_invalid_interrupt_answer_stays_on_the_interrupt(): void
    {
        $engine = $this->interruptEngine(null, $applied, false);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('year', Turn::TEXT, '?'));

        $r = $engine->handleTurn($s, new Turn('callback', Turn::SKIP));

        $this->assertTrue($r->isInvalid());
        $this->assertSame('callback', $s->currentStep());
        $this->assertTrue($s->chatState()->isInterrupted());
        $this->assertSame([], $applied);
    }

    // -------------------------------------------------------- deferred submit

    public function test_deferred_completion_success_does_not_double_complete(): void
    {
        $done = 0;
        $listener = new RecordingListener();
        $flow = $this->consentFlow()->deferCompletion()->onComplete(static function () use (&$done) {
            $done++;
        });
        $engine = new FlowEngine($flow, null, 200, null, null, null, null, $listener);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('a', Turn::TEXT, 'x'));
        $engine->handleTurn($s, new Turn('review', Turn::CHOICE, 'confirm'));

        $r = $engine->handleTurn($s, new Turn('consent', Turn::SUBMIT, true));
        $this->assertTrue($r->isSubmitting());
        $this->assertSame(TurnResult::SUBMITTING, $r->status);
        $this->assertTrue($s->isSubmitting());
        $this->assertFalse($s->isCompleted());
        $this->assertSame(0, $done);
        $this->assertTrue($engine->ui($s)['submitting']);

        $again = $engine->handleTurn($s, new Turn('consent', Turn::SUBMIT, true));
        $this->assertTrue($again->isSubmitting());
        $this->assertSame(0, $done, 'a second SUBMIT while submitting is a no-op');

        $engine->completeSubmission($s, true);
        $this->assertTrue($s->isCompleted());
        $this->assertSame(1, $done);
        $this->assertTrue($s->chatState()->answer('consent'));

        $engine->completeSubmission($s, true);
        $this->assertSame(1, $done, 'completing twice does not fire onComplete twice');
        $this->assertSame(['submitted', 'completed'], array_values(array_filter($listener->events, static function ($e) {
            return in_array($e, ['submitted', 'completed'], true);
        })));
    }

    public function test_deferred_completion_failure_offers_a_retry(): void
    {
        $t = new RecordingTranscript();
        $listener = new RecordingListener();
        $done = 0;
        $flow = $this->consentFlow()->deferCompletion()->onComplete(static function () use (&$done) {
            $done++;
        });
        $engine = new FlowEngine($flow, null, 200, $t, null, null, null, $listener);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('a', Turn::TEXT, 'x'));
        $engine->handleTurn($s, new Turn('review', Turn::CHOICE, 'confirm'));
        $engine->handleTurn($s, new Turn('consent', Turn::SUBMIT, true));

        $r = $engine->completeSubmission($s, false, 'CRM down');

        $this->assertTrue($r->isInvalid());
        $this->assertFalse($s->isSubmitting());
        $this->assertFalse($s->isCompleted());
        $this->assertSame('consent', $s->currentStep());
        $this->assertSame('Try again', $engine->ui($s)['submitLabel']);
        $this->assertStringStartsWith('We could not send your details.', $t->last('assistant'));
        $this->assertContains('submissionFailed:CRM down', $listener->events);
        $this->assertSame(0, $done);

        // Retry.
        $this->assertTrue($engine->handleTurn($s, new Turn('consent', Turn::SUBMIT, true))->isSubmitting());
        $engine->completeSubmission($s, true);
        $this->assertSame(1, $done);
        $this->assertTrue($s->isCompleted());
    }

    public function test_ui_using_decorates_every_payload(): void
    {
        $flow = Flows::lead()->uiUsing(static function (array $ui, ChatSession $s, ChatState $st): array {
            $ui['decorated'] = $s->currentStep();

            return $ui;
        });
        $engine = new FlowEngine($flow);
        $s = $this->start($engine);

        $this->assertSame('make', $engine->ui($s)['decorated']);
    }

    // ----------------------------------------------------------- invariants

    public function test_answers_of_steps_that_no_longer_apply_are_dropped(): void
    {
        $listener = new RecordingListener();
        $flow = Flow::make()
            ->add(Flows::text('a'))
            ->add(Flows::text('b')->applies(static function (ChatState $s): bool {
                return $s->answer('a') !== 'x';
            }));
        $engine = new FlowEngine($flow, null, 200, null, null, null, null, $listener);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('a', Turn::TEXT, 'y'));
        $engine->handleTurn($s, new Turn('b', Turn::TEXT, '1'));

        $engine->handleTurn($s, new Turn('done', Turn::EDIT, 'a'));
        $engine->handleTurn($s, new Turn('a', Turn::TEXT, 'x'));

        $this->assertFalse($s->chatState()->has('b'));
        $this->assertSame('done', $s->currentStep());
        $this->assertContains('invariant', $listener->events);
        $this->assertStringContainsString('"b" dropped', $listener->violations[0]);
    }

    public function test_the_invariant_checker_can_be_disabled(): void
    {
        $flow = Flow::make()
            ->add(Flows::text('a'))
            ->add(Flows::text('b')->applies(static function (ChatState $s): bool {
                return $s->answer('a') !== 'x';
            }));
        $engine = new FlowEngine($flow, null, 200, null, null, null, null, null, null, false);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('a', Turn::TEXT, 'y'));
        $engine->handleTurn($s, new Turn('b', Turn::TEXT, '1'));
        $engine->handleTurn($s, new Turn('done', Turn::EDIT, 'a'));
        $engine->handleTurn($s, new Turn('a', Turn::TEXT, 'x'));

        $this->assertTrue($s->chatState()->has('b'));
    }

    public function test_answers_that_now_fail_validation_are_dropped_unless_revalidate_is_false(): void
    {
        $strict = static function ($v, ChatState $s): ?string {
            return $s->meta('strict') ? 'too strict' : null;
        };
        $flow = Flow::make()
            ->add(Flows::text('a')->validate($strict))
            ->add(Flows::text('b')->validate($strict)->revalidate(false))
            ->add(Flows::text('c')->afterApply(static function (ChatState $s) {
                $s->setMeta('strict', true);
            }));
        $engine = new FlowEngine($flow);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('a', Turn::TEXT, '1'));
        $engine->handleTurn($s, new Turn('b', Turn::TEXT, '2'));
        $engine->handleTurn($s, new Turn('c', Turn::TEXT, '3'));

        $this->assertFalse($s->chatState()->has('a'));
        $this->assertTrue($s->chatState()->has('b'));
    }

    public function test_the_flow_recovers_when_the_current_step_is_not_the_next_step(): void
    {
        $listener = new RecordingListener();
        $engine = new FlowEngine(Flows::lead(), null, 200, null, null, null, null, $listener);
        $s = $this->start($engine);
        $s->setCurrentStep('model'); // corrupted session: "make" is still unanswered

        $r = $engine->handleTurn($s, new Turn('model', Turn::TEXT, ''));

        $this->assertTrue($r->isInvalid());
        $this->assertSame('make', $s->currentStep());
        $log = $s->chatState()->askLog();
        $this->assertSame(AskReason::RECOVERED, $log[count($log) - 1]['reason']);
        $this->assertContains('invariant', $listener->events);
    }

    public function test_a_step_cleared_without_a_new_epoch_is_reported_as_a_duplicate_ask(): void
    {
        $listener = new RecordingListener();
        $flow = Flow::make()
            ->add(Flows::text('a'))
            ->add(Flows::text('b')->afterApply(static function (ChatState $s) {
                $s->forget('a');
            }));
        $engine = new FlowEngine($flow, null, 200, null, null, null, null, $listener);
        $s = $this->start($engine);
        $engine->handleTurn($s, new Turn('a', Turn::TEXT, '1'));
        $engine->handleTurn($s, new Turn('b', Turn::TEXT, '2'));

        $this->assertSame('a', $s->currentStep());
        $this->assertStringContainsString('asked twice', $listener->violations[0]);
    }

    public function test_the_checker_flags_a_step_asked_twice_in_one_epoch_for_a_non_repeatable_reason(): void
    {
        $state = new ChatState();
        $state->recordAsk('a', AskReason::NEXT);
        $mark = $state->askTotal();
        $state->recordAsk('a', AskReason::NEXT);
        $checker = new InvariantChecker();

        $this->assertCount(1, $checker->duplicateAsks($state, $mark));

        $state = new ChatState();
        $state->recordAsk('a', AskReason::NEXT);
        $mark = $state->askTotal();
        $state->recordAsk('a', AskReason::INVALID);
        $this->assertSame([], $checker->duplicateAsks($state, $mark));

        $state = new ChatState();
        $state->recordAsk('a', AskReason::NEXT);
        $state->bumpEpoch();
        $mark = $state->askTotal();
        $state->recordAsk('a', AskReason::NEXT);
        $this->assertSame([], $checker->duplicateAsks($state, $mark));
    }

    // ------------------------------------------------------------ turn limit

    public function test_the_turn_limit_ends_the_conversation_once(): void
    {
        $t = new RecordingTranscript();
        $listener = new RecordingListener();
        $engine = new FlowEngine(Flows::lead(), null, 1, $t, null, null, null, $listener);
        $s = $this->start($engine);

        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'Audi'));
        $r = $engine->handleTurn($s, new Turn('model', Turn::TEXT, 'A4'));
        $this->assertTrue($r->isEnded());
        $this->assertSame(TurnResult::ENDED, $r->status);

        $engine->handleTurn($s, new Turn('model', Turn::TEXT, 'A4'));

        $this->assertSame(1, count(array_filter($listener->events, static function ($e) {
            return $e === 'turnLimit';
        })));
        $this->assertSame('This conversation has reached its turn limit.', $t->last('assistant'));
        $this->assertSame(1, count(array_filter($t->log, static function (array $r) {
            return $r[3] === 'ended';
        })));
        $ui = $engine->ui($s);
        $this->assertSame('ended', $ui['type']);
        $this->assertSame('This conversation has reached its turn limit.', $ui['text']);
        $this->assertFalse($s->chatState()->has('model'));
    }
}
