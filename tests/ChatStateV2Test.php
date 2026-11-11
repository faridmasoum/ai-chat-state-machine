<?php

namespace FaridM\StepChat\Tests;

use FaridM\StepChat\AskReason;
use FaridM\StepChat\ChatState;
use PHPUnit\Framework\TestCase;

final class ChatStateV2Test extends TestCase
{
    public function test_v1_data_loads_and_round_trips_as_v2(): void
    {
        $v1 = [
            'v' => 1,
            'answers' => ['name' => 'Farid'],
            'meta' => ['source' => 'widget'],
            'askLog' => [['step' => 'name', 'epoch' => 0], ['step' => 'email', 'epoch' => 1]],
            'invalidAttempts' => ['email' => 2],
            'epoch' => 1,
            'editing' => null,
            'interruptStep' => null,
            'resumeStep' => null,
        ];

        $state = ChatState::fromArray($v1);

        $this->assertSame('Farid', $state->answer('name'));
        $this->assertSame(AskReason::NEXT, $state->askLog()[0]['reason'], 'entries without a reason default to "next"');
        $this->assertSame(2, $state->askTotal());
        $this->assertSame([], $state->hint('name'));
        $this->assertFalse($state->ended());

        $out = $state->toArray();
        $this->assertSame(2, $out['v']);
        $this->assertSame($out, ChatState::fromArray($out)->toArray());
    }

    public function test_ask_reasons_hints_and_flags_round_trip(): void
    {
        $state = new ChatState();
        $state->recordAsk('a', AskReason::GOBACK);
        $state->setHint('a', ['selected' => ['x']]);
        $state->markEnded();
        $state->setSubmitFailed(true);

        $copy = ChatState::fromArray($state->toArray());

        $this->assertSame(AskReason::GOBACK, $copy->askLog()[0]['reason']);
        $this->assertSame(['selected' => ['x']], $copy->hint('a'));
        $this->assertTrue($copy->ended());
        $this->assertTrue($copy->submitFailed());
    }

    public function test_hints_are_cleared_with_forget_and_reset(): void
    {
        $state = new ChatState();
        $state->setAnswer('a', 1);
        $state->setHint('a', ['x' => 1]);
        $state->setHint('b', ['y' => 1]);

        $state->forget('a');
        $this->assertSame([], $state->hint('a'));
        $this->assertSame(['y' => 1], $state->hint('b'));

        $state->resetAnswers();
        $this->assertSame([], $state->hint('b'));
    }

    public function test_asks_since_and_asked_in_epoch(): void
    {
        $state = new ChatState();
        $state->recordAsk('a');
        $mark = $state->askTotal();
        $state->recordAsk('b', AskReason::INVALID);

        $this->assertSame([['step' => 'b', 'reason' => 'invalid', 'epoch' => 0]], $state->asksSince($mark));
        $this->assertTrue($state->askedInEpoch('a'));
        $state->bumpEpoch();
        $this->assertFalse($state->askedInEpoch('a'));
    }
}
