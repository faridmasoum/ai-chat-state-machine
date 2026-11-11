<?php

namespace FaridM\StepChat\Engine;

use FaridM\StepChat\AskReason;
use FaridM\StepChat\ChatState;
use FaridM\StepChat\Flow;

/**
 * Self-healing checks run after every turn. @internal
 */
final class InvariantChecker
{
    /**
     * Drop answers of steps that no longer apply and answers that now fail
     * validation (except confirmation and revalidate(false) steps).
     *
     * @return list<string> violations found (and repaired)
     */
    public function repair(Flow $flow, ChatState $state): array
    {
        $violations = [];
        for ($pass = 0; $pass < 5; $pass++) {
            $changed = false;
            foreach ($flow->steps() as $key => $step) {
                if (!$state->has($key)) {
                    continue;
                }
                if (!$step->stepApplies($state)) {
                    $state->forget($key);
                    $violations[] = sprintf('answer of "%s" dropped: step no longer applies', $key);
                    $changed = true;
                } elseif (!$step->confirmation && $step->revalidate && ($step->validate)($state->answer($key), $state) !== null) {
                    $state->forget($key);
                    $violations[] = sprintf('answer of "%s" dropped: it no longer passes validation', $key);
                    $changed = true;
                }
            }
            if (!$changed) {
                break;
            }
        }

        return $violations;
    }

    /**
     * Steps asked twice in one epoch for a non-repeatable reason.
     *
     * @return list<string>
     */
    public function duplicateAsks(ChatState $state, int $askTotalBefore): array
    {
        $violations = [];
        $log = $state->askLog();
        $new = $state->asksSince($askTotalBefore);
        $firstNew = count($log) - count($new);
        foreach ($new as $i => $entry) {
            if (in_array($entry['reason'], AskReason::REPEATABLE, true)) {
                continue;
            }
            for ($j = 0; $j < $firstNew + $i; $j++) {
                if ($log[$j]['step'] === $entry['step'] && $log[$j]['epoch'] === $entry['epoch']) {
                    $violations[] = sprintf('step "%s" asked twice in epoch %d (reason "%s")', $entry['step'], $entry['epoch'], $entry['reason']);
                    break;
                }
            }
        }

        return $violations;
    }
}
