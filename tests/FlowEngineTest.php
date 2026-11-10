<?php

namespace Farid\AiChatStateMachine\Tests;

use Farid\AiChatStateMachine\ChatState;
use Farid\AiChatStateMachine\Flow;
use Farid\AiChatStateMachine\FlowEngine;
use Farid\AiChatStateMachine\StepDefinition;
use Farid\AiChatStateMachine\StepResult;
use Farid\AiChatStateMachine\Support\ArraySession;
use Farid\AiChatStateMachine\Turn;
use Farid\AiChatStateMachine\TurnResult;
use PHPUnit\Framework\TestCase;

final class FlowEngineTest extends TestCase
{
    private function engine(): FlowEngine
    {
        $flow = Flow::make()
            ->add(
                StepDefinition::make('name', 'Your name')
                    ->parse(function (Turn $turn): StepResult {
                        $value = $turn->text();

                        return $value === '' ? StepResult::invalid('A name is required.') : StepResult::ok($value);
                    })
            )
            ->add(
                StepDefinition::make('email', 'Your email')
                    ->parse(function (Turn $turn): StepResult {
                        $value = $turn->text();

                        return filter_var($value, FILTER_VALIDATE_EMAIL)
                            ? StepResult::ok($value)
                            : StepResult::invalid('That does not look like an email address.');
                    })
            )
            ->add(
                StepDefinition::make('newsletter', 'Subscribe to the newsletter?')
                    ->optional(true, false)
                    ->parse(fn (Turn $turn): StepResult => StepResult::ok($turn->value === 'yes'))
            );

        return new FlowEngine($flow);
    }

    public function test_start_points_at_the_first_step(): void
    {
        $session = new ArraySession();
        $this->engine()->start($session);

        $this->assertSame('name', $session->currentStep());
    }

    public function test_invalid_answers_do_not_advance_the_step(): void
    {
        $engine = $this->engine();
        $session = new ArraySession();
        $engine->start($session);

        $result = $engine->handleTurn($session, new Turn('name', Turn::TEXT, ''));

        $this->assertTrue($result->isInvalid());
        $this->assertSame('name', $session->currentStep());
    }

    public function test_valid_answers_advance_through_every_step_to_completion(): void
    {
        $engine = $this->engine();
        $session = new ArraySession();
        $engine->start($session);

        $engine->handleTurn($session, new Turn('name', Turn::TEXT, 'Farid'));
        $this->assertSame('email', $session->currentStep());

        $engine->handleTurn($session, new Turn('email', Turn::TEXT, 'farid@example.com'));
        $this->assertSame('newsletter', $session->currentStep());

        $result = $engine->handleTurn($session, new Turn('newsletter', Turn::SKIP));

        $this->assertSame(TurnResult::CONTINUE, $result->status);
        $this->assertSame(FlowEngine::DONE, $session->currentStep());
    }

    public function test_step_mismatch_throws(): void
    {
        $engine = $this->engine();
        $session = new ArraySession();
        $engine->start($session);

        $this->expectException(\Farid\AiChatStateMachine\Exceptions\StepMismatchException::class);
        $engine->handleTurn($session, new Turn('email', Turn::TEXT, 'farid@example.com'));
    }

    public function test_editing_a_prior_step_jumps_back_and_clears_dependents(): void
    {
        $flow = Flow::make()
            ->add(
                StepDefinition::make('country', 'Country')
                    ->invalidates(['city'])
                    ->parse(fn (Turn $turn): StepResult => StepResult::ok($turn->text()))
            )
            ->add(
                StepDefinition::make('city', 'City')
                    ->parse(fn (Turn $turn): StepResult => StepResult::ok($turn->text()))
            );
        $engine = new FlowEngine($flow);
        $session = new ArraySession();
        $engine->start($session);

        $engine->handleTurn($session, new Turn('country', Turn::TEXT, 'IR'));
        $engine->handleTurn($session, new Turn('city', Turn::TEXT, 'Tehran'));
        $this->assertSame(FlowEngine::DONE, $session->currentStep());

        $engine->handleTurn($session, new Turn('done', Turn::EDIT, 'country'));
        $this->assertSame('country', $session->currentStep());

        $engine->handleTurn($session, new Turn('country', Turn::TEXT, 'US'));
        $this->assertSame('city', $session->currentStep());
        $this->assertFalse($session->chatState()->has('city'));
    }
}
