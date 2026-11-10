<?php

namespace FaridM\AiChatStateMachine\Tests;

use FaridM\AiChatStateMachine\Flow;
use FaridM\AiChatStateMachine\FlowEngine;
use FaridM\AiChatStateMachine\Steps;
use FaridM\AiChatStateMachine\Support\ArraySession;
use FaridM\AiChatStateMachine\Turn;
use PHPUnit\Framework\TestCase;

final class StepsTest extends TestCase
{
    private function parse($step, string $type, $value)
    {
        return $step->stepParse(new Turn($step->key, $type, $value), new \FaridM\AiChatStateMachine\ChatState());
    }

    public function test_email_preset(): void
    {
        $step = Steps::email('email', 'Email');
        $this->assertTrue($this->parse($step, Turn::TEXT, 'a@b.co')->ok);
        $this->assertFalse($this->parse($step, Turn::TEXT, 'nope')->ok);
        $this->assertSame('email', $step->stepUi(new \FaridM\AiChatStateMachine\ChatState())['inputType']);
    }

    public function test_phone_and_number_presets(): void
    {
        $this->assertTrue($this->parse(Steps::phone('p', 'Phone'), Turn::TEXT, '+1 (555) 010-0000')->ok);
        $this->assertFalse($this->parse(Steps::phone('p', 'Phone'), Turn::TEXT, 'abc')->ok);
        $this->assertSame(5, $this->parse(Steps::number('n', 'N', 1, 10), Turn::TEXT, '5')->value);
        $this->assertFalse($this->parse(Steps::number('n', 'N', 1, 10), Turn::TEXT, '11')->ok);
    }

    public function test_choice_accepts_only_listed_values_and_shows_labels(): void
    {
        $step = Steps::choice('plan', 'Plan', ['free' => 'Free tier', 'pro' => 'Pro']);
        $this->assertTrue($this->parse($step, Turn::CHOICE, 'pro')->ok);
        $this->assertFalse($this->parse($step, Turn::CHOICE, 'gold')->ok);
        $this->assertSame('Free tier', ($step->display)('free', new \FaridM\AiChatStateMachine\ChatState()));
    }

    public function test_multi_choice_and_confirm(): void
    {
        $multi = Steps::multiChoice('tags', 'Tags', ['a', 'b', 'c'], 1, 2);
        $this->assertSame(['a', 'b'], $this->parse($multi, Turn::LIST, ['a', 'b'])->value);
        $this->assertFalse($this->parse($multi, Turn::LIST, [])->ok);
        $this->assertFalse($this->parse($multi, Turn::LIST, ['a', 'b', 'c'])->ok);
        $this->assertFalse($this->parse($multi, Turn::LIST, ['z'])->ok);

        $confirm = Steps::confirm('ok', 'Sure?');
        $this->assertTrue($this->parse($confirm, Turn::CHOICE, 'yes')->value);
        $this->assertFalse($this->parse($confirm, Turn::CHOICE, 'no')->value);
    }

    public function test_full_flow_review_then_submit_fires_on_complete(): void
    {
        $captured = null;
        $flow = Flow::make()
            ->add(Steps::text('name', 'Name'))
            ->add(Steps::email('email', 'Email'))
            ->onComplete(function (array $answers) use (&$captured): void {
                $captured = $answers;
            });
        $engine = new FlowEngine($flow);
        $session = new ArraySession();
        $engine->start($session);

        $engine->handleTurn($session, new Turn('name', Turn::TEXT, 'Farid'));
        $engine->handleTurn($session, new Turn('email', Turn::TEXT, 'f@example.com'));

        $ui = $engine->ui($session);
        $this->assertSame('review', $ui['type']);
        $this->assertSame('Farid', $ui['items'][0]['value']);
        $this->assertNull($captured);

        $result = $engine->handleTurn($session, new Turn('done', Turn::SUBMIT));
        $this->assertTrue($result->isCompleted());
        $this->assertSame(['name' => 'Farid', 'email' => 'f@example.com'], $captured);
        $this->assertSame('done', $engine->ui($session)['type']);
    }

    public function test_ui_carries_message_and_optional(): void
    {
        $engine = new FlowEngine(Flow::make()->add(Steps::text('name', 'Your name?')->optional()));
        $session = new ArraySession();
        $engine->start($session);
        $ui = $engine->ui($session);
        $this->assertSame('Your name?', $ui['message']);
        $this->assertTrue($ui['optional']);
    }
}
