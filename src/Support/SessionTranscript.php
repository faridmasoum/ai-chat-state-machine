<?php

namespace FaridM\StepChat\Support;

use FaridM\StepChat\Contracts\ChatSession;
use FaridM\StepChat\Contracts\StoresMessages;
use FaridM\StepChat\Contracts\Transcript;
use FaridM\StepChat\Turn;

/**
 * Writes the visible conversation into a session that keeps a message list
 * (StoresMessages, e.g. ArraySession). Events are internal and never stored here.
 */
final class SessionTranscript implements Transcript
{
    public function assistant(ChatSession $session, string $step, string $text, string $kind, array $ui = []): void
    {
        if ($session instanceof StoresMessages) {
            $session->addMessage(['role' => 'assistant', 'kind' => $kind, 'step' => $step, 'text' => $text]);
        }
    }

    public function user(ChatSession $session, string $step, Turn $turn, string $echo): void
    {
        if ($session instanceof StoresMessages) {
            $session->addMessage(['role' => 'user', 'kind' => 'answer', 'step' => $step, 'text' => $echo]);
        }
    }

    public function event(ChatSession $session, string $step, string $text): void
    {
    }
}
