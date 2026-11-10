<?php

namespace FaridM\AiChatStateMachine\Tests;

use FaridM\AiChatStateMachine\ChatState;
use PHPUnit\Framework\TestCase;

final class ChatStateTest extends TestCase
{
    public function test_round_trips_through_an_array(): void
    {
        $state = new ChatState();
        $state->setAnswer('name', 'Farid');
        $state->setMeta('source', 'widget');
        $state->recordAsk('name');

        $restored = ChatState::fromArray($state->toArray());

        $this->assertSame('Farid', $restored->answer('name'));
        $this->assertSame('widget', $restored->meta('source'));
        $this->assertCount(1, $restored->askLog());
    }

    public function test_invalidates_dependent_answers_when_a_value_changes(): void
    {
        $state = new ChatState();
        $state->setAnswer('country', 'IR');
        $state->setAnswer('city', 'Tehran');

        $this->assertTrue(ChatState::same('IR', 'IR'));
        $this->assertFalse(ChatState::same('IR', 'US'));
    }

    public function test_interrupt_pauses_and_resumes(): void
    {
        $state = new ChatState();
        $state->startInterrupt('confirm_phone', 'address');

        $this->assertTrue($state->isInterrupted());
        $this->assertSame('confirm_phone', $state->interruptStep());
        $this->assertSame('address', $state->resumeStep());

        $state->endInterrupt();

        $this->assertFalse($state->isInterrupted());
    }

    public function test_tracks_invalid_attempts_per_step(): void
    {
        $state = new ChatState();
        $state->recordInvalidAttempt('email');
        $state->recordInvalidAttempt('email');

        $this->assertSame(2, $state->invalidAttempts('email'));

        $state->clearInvalidAttempts('email');

        $this->assertSame(0, $state->invalidAttempts('email'));
    }
}
