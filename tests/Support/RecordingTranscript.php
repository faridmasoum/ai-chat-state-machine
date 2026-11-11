<?php

namespace FaridM\StepChat\Tests\Support;

use FaridM\StepChat\Contracts\ChatSession;
use FaridM\StepChat\Contracts\Transcript;
use FaridM\StepChat\Turn;

final class RecordingTranscript implements Transcript
{
    /** @var list<array{string, string, string, string}> role, step, text, kind */
    public $log = [];

    public function assistant(ChatSession $session, string $step, string $text, string $kind, array $ui = []): void
    {
        $this->log[] = ['assistant', $step, $text, $kind];
    }

    public function user(ChatSession $session, string $step, Turn $turn, string $echo): void
    {
        $this->log[] = ['user', $step, $echo, $turn->type];
    }

    public function event(ChatSession $session, string $step, string $text): void
    {
        $this->log[] = ['event', $step, $text, 'event'];
    }

    /** @return list<string> */
    public function texts(string $role): array
    {
        $out = [];
        foreach ($this->log as $row) {
            if ($row[0] === $role) {
                $out[] = $row[2];
            }
        }

        return $out;
    }

    public function last(string $role): string
    {
        $t = $this->texts($role);

        return (string) end($t);
    }

    public function dump(): string
    {
        return json_encode($this->log) ?: '';
    }
}
