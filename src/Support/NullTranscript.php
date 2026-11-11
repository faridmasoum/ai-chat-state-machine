<?php

namespace FaridM\StepChat\Support;

use FaridM\StepChat\Contracts\ChatSession;
use FaridM\StepChat\Contracts\Transcript;
use FaridM\StepChat\Turn;

/** Records nothing (the default Transcript). */
final class NullTranscript implements Transcript
{
    public function assistant(ChatSession $session, string $step, string $text, string $kind, array $ui = []): void
    {
    }

    public function user(ChatSession $session, string $step, Turn $turn, string $echo): void
    {
    }

    public function event(ChatSession $session, string $step, string $text): void
    {
    }
}
