<?php

namespace FaridM\StepChat\Support;

use FaridM\StepChat\Contracts\ChatSession;
use FaridM\StepChat\Contracts\Transcript;
use FaridM\StepChat\Turn;

/** Forwards every call to several transcripts. */
final class CompositeTranscript implements Transcript
{
    /** @var list<Transcript> */
    private $transcripts;

    /**
     * @param list<Transcript> $transcripts
     */
    public function __construct(array $transcripts)
    {
        $this->transcripts = $transcripts;
    }

    public function assistant(ChatSession $session, string $step, string $text, string $kind, array $ui = []): void
    {
        foreach ($this->transcripts as $t) {
            $t->assistant($session, $step, $text, $kind, $ui);
        }
    }

    public function user(ChatSession $session, string $step, Turn $turn, string $echo): void
    {
        foreach ($this->transcripts as $t) {
            $t->user($session, $step, $turn, $echo);
        }
    }

    public function event(ChatSession $session, string $step, string $text): void
    {
        foreach ($this->transcripts as $t) {
            $t->event($session, $step, $text);
        }
    }
}
