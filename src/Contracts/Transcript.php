<?php

namespace FaridM\StepChat\Contracts;

use FaridM\StepChat\Turn;

/**
 * Receives the conversation itself: what the bot said, what the user did,
 * and internal log lines. Persist it wherever your app keeps messages.
 * The default is Support\NullTranscript (records nothing).
 */
interface Transcript
{
    /**
     * A message written by the bot. $kind is one of: question, error, info, ack, done, ended.
     *
     * @param array<string, mixed> $ui the UI payload rendered with this message (empty when not a question)
     */
    public function assistant(ChatSession $session, string $step, string $text, string $kind, array $ui = []): void;

    /**
     * The user's turn, as it should be shown (sensitive values are already masked in $echo).
     */
    public function user(ChatSession $session, string $step, Turn $turn, string $echo): void;

    /**
     * An internal log line (edit, go-back, recovery, submission...). Never shown to the user.
     */
    public function event(ChatSession $session, string $step, string $text): void;
}
