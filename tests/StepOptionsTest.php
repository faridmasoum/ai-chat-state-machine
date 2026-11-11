<?php

namespace FaridM\StepChat\Tests;

use FaridM\StepChat\ChatState;
use FaridM\StepChat\Flow;
use FaridM\StepChat\FlowEngine;
use FaridM\StepChat\StepDefinition;
use FaridM\StepChat\StepResult;
use FaridM\StepChat\Support\ArraySession;
use FaridM\StepChat\Tests\Support\Flows;
use FaridM\StepChat\Tests\Support\RecordingTranscript;
use FaridM\StepChat\Turn;
use PHPUnit\Framework\TestCase;

final class StepOptionsTest extends TestCase
{
    private function play(Flow $flow, array $turns, ?RecordingTranscript $t = null): array
    {
        $engine = new FlowEngine($flow, null, 200, $t);
        $s = new ArraySession();
        $engine->start($s);
        foreach ($turns as $turn) {
            $engine->handleTurn($s, $turn);
        }

        return [$engine, $s];
    }

    public function test_question_is_asked_while_label_stays_for_review(): void
    {
        $flow = Flow::make()->add(Flows::text('make', 'Make')->question(static function (ChatState $s): string {
            return 'Which make?';
        }));
        $engine = new FlowEngine($flow);
        $s = new ArraySession();
        $engine->start($s);

        $this->assertSame('Which make?', $engine->ui($s)['message']);

        $plain = new FlowEngine(Flow::make()->add(Flows::text('make', 'Make')));
        $s2 = new ArraySession();
        $plain->start($s2);
        $this->assertSame('Make', $plain->ui($s2)['message']);
    }

    public function test_after_apply_runs_on_every_commit_and_on_change_only_for_changes(): void
    {
        $applied = [];
        $changed = [];
        $flow = Flow::make()
            ->add(Flows::text('make')
                ->afterApply(function (ChatState $s, $v) use (&$applied) {
                    $applied[] = $v;
                    $s->setMeta('catalogue', 'id-' . $v);
                })
                ->onChange(function (ChatState $s, $old, $new) use (&$changed) {
                    $changed[] = [$old, $new];
                }))
            ->add(Flows::text('model'));

        [$engine, $s] = $this->play($flow, [new Turn('make', Turn::TEXT, 'Audi'), new Turn('model', Turn::TEXT, 'A4')]);
        $this->assertSame(['Audi'], $applied);
        $this->assertSame([], $changed, 'first answer is not a change');
        $this->assertSame('id-Audi', $s->chatState()->meta('catalogue'));

        $engine->handleTurn($s, new Turn('done', Turn::EDIT, 'make'));
        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'Audi'));
        $this->assertSame(['Audi', 'Audi'], $applied, 'afterApply runs even when the value is the same');
        $this->assertSame([], $changed);

        $engine->handleTurn($s, new Turn('done', Turn::EDIT, 'make'));
        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'BMW'));
        $this->assertSame([['Audi', 'BMW']], $changed);
    }

    public function test_on_change_sees_dependents_already_cleared(): void
    {
        $seen = null;
        $flow = Flow::make()
            ->add(Flows::text('make')->invalidates(['model'])->onChange(function (ChatState $s) use (&$seen) {
                $seen = $s->has('model');
            }))
            ->add(Flows::text('model'));

        [$engine, $s] = $this->play($flow, [new Turn('make', Turn::TEXT, 'Audi'), new Turn('model', Turn::TEXT, 'A4')]);
        $engine->handleTurn($s, new Turn('done', Turn::EDIT, 'make'));
        $engine->handleTurn($s, new Turn('make', Turn::TEXT, 'BMW'));

        $this->assertFalse($seen);
        $this->assertSame('model', $s->currentStep());
    }

    public function test_resets_flow_clears_all_other_answers_when_an_existing_answer_changes(): void
    {
        $flow = Flow::make()
            ->add(Flows::text('who')->resetsFlow())
            ->add(Flows::text('name'))
            ->add(Flows::text('phone'));

        [$engine, $s] = $this->play($flow, [
            new Turn('who', Turn::TEXT, 'buyer'),
            new Turn('name', Turn::TEXT, 'Ann'),
            new Turn('phone', Turn::TEXT, '123'),
        ]);
        $this->assertSame('done', $s->currentStep());

        $engine->handleTurn($s, new Turn('done', Turn::EDIT, 'who'));
        $engine->handleTurn($s, new Turn('who', Turn::TEXT, 'buyer'));
        $this->assertSame('done', $s->currentStep(), 'same value does not reset');

        $engine->handleTurn($s, new Turn('done', Turn::EDIT, 'who'));
        $engine->handleTurn($s, new Turn('who', Turn::TEXT, 'partner'));
        $this->assertSame(['who' => 'partner'], $s->chatState()->answers());
        $this->assertSame('name', $s->currentStep());
    }

    public function test_non_editable_steps_cannot_be_edited(): void
    {
        $flow = Flow::make()->add(Flows::text('a')->editable(false))->add(Flows::text('b'));
        [$engine, $s] = $this->play($flow, [new Turn('a', Turn::TEXT, '1'), new Turn('b', Turn::TEXT, '2')]);

        $this->expectException(\FaridM\StepChat\Exceptions\InvalidTurnException::class);
        $engine->handleTurn($s, new Turn('done', Turn::EDIT, 'a'));
    }

    public function test_review_marks_editable_and_groups_by_section(): void
    {
        $flow = Flow::make()
            ->add(Flows::text('a')->section('Car')->editable(false))
            ->add(Flows::text('b')->section('Car'))
            ->add(Flows::text('c')->section('You'));
        [$engine, $s] = $this->play($flow, [
            new Turn('a', Turn::TEXT, '1'), new Turn('b', Turn::TEXT, '2'), new Turn('c', Turn::TEXT, '3'),
        ]);

        $review = $engine->review($s->chatState());
        $this->assertSame('review', $review['type']);
        $this->assertSame('Confirm', $review['confirmLabel']);
        $this->assertSame(['Car', 'You'], array_column($review['sections'], 'title'));
        $this->assertFalse($review['sections'][0]['items'][0]['editable']);
        $this->assertTrue($review['sections'][0]['items'][1]['editable']);
        $this->assertArrayNotHasKey('items', $review);
    }

    public function test_review_without_sections_returns_items_and_review_using_can_alter_it(): void
    {
        $flow = Flow::make()->add(Flows::text('a'))->reviewUsing(static function (array $review, ChatState $s): array {
            $review['items'][] = ['step' => null, 'label' => 'Source', 'value' => 'web', 'editable' => false];

            return $review;
        });
        [$engine, $s] = $this->play($flow, [new Turn('a', Turn::TEXT, '1')]);

        $ui = $engine->ui($s);
        $this->assertSame('review', $ui['type']);
        $this->assertSame('done', $ui['step']);
        $this->assertCount(2, $ui['items']);
        $this->assertSame('Source', $ui['items'][1]['label']);
    }

    public function test_required_message_and_acknowledge_and_echo(): void
    {
        $t = new RecordingTranscript();
        $flow = Flow::make()
            ->add(Flows::text('a')->requiredMessage('Please fill this in.'))
            ->add(Flows::text('b')->optional(true, null)
                ->acknowledge(function ($v, ChatState $s, bool $skipped) {
                    return $skipped ? 'Ok, skipped.' : "Saved $v.";
                })
                ->echo(static function (Turn $turn): string {
                    return 'B=' . $turn->text();
                }));

        [$engine, $s] = $this->play($flow, [], $t);
        $r = $engine->handleTurn($s, new Turn('a', Turn::SKIP));
        $this->assertSame('Please fill this in.', $r->error);

        $engine->handleTurn($s, new Turn('a', Turn::TEXT, 'x'));
        $engine->handleTurn($s, new Turn('b', Turn::TEXT, 'hello'));
        $this->assertSame('B=hello', $t->last('user'));
        $this->assertStringStartsWith("Saved hello.\n\n", $this->lastAssistantAfterUser($t));
    }

    private function lastAssistantAfterUser(RecordingTranscript $t): string
    {
        return $t->last('assistant');
    }

    public function test_default_echo_uses_option_labels(): void
    {
        $t = new RecordingTranscript();
        $flow = Flow::make()
            ->add(Flows::choice('fuel', ['petrol', 'diesel']))
            ->add(StepDefinition::make('extras', 'Extras')
                ->ui(static function (): array {
                    return ['type' => 'multi', 'options' => [['value' => 'a', 'label' => 'Alpha'], ['value' => 'b', 'label' => 'Beta']]];
                })
                ->parse(static function (Turn $turn): StepResult {
                    return StepResult::ok($turn->list());
                }))
            ->add(StepDefinition::make('ok', 'OK?')
                ->ui(static function (): array {
                    return ['type' => 'choice', 'options' => [['value' => 'yes', 'label' => 'Yes please'], ['value' => 'no', 'label' => 'No thanks']]];
                })
                ->parse(static function (Turn $turn): StepResult {
                    return StepResult::ok($turn->value === true);
                }))
            ->add(Flows::text('note')->optional(true, null));

        [$engine, $s] = $this->play($flow, [
            new Turn('fuel', Turn::CHOICE, 'diesel'),
            new Turn('extras', Turn::LIST, ['a', 'b']),
            new Turn('ok', Turn::CHOICE, true),
            new Turn('note', Turn::SKIP),
        ], $t);

        $this->assertSame(['DIESEL', 'Alpha, Beta', 'Yes please', 'Skipped'], $t->texts('user'));
    }

    public function test_attribute_bag_and_defaults(): void
    {
        $step = StepDefinition::make('a', 'A')->with('ai_fields', ['make']);
        $this->assertSame(['make'], $step->attribute('ai_fields'));
        $this->assertSame('d', $step->attribute('missing', 'd'));
        $this->assertTrue($step->isEditable());
        $this->assertTrue($step->isInterpretable());
    }
}
