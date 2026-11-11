<?php

namespace FaridM\StepChat;

/**
 * Why a step was asked. Stored in ChatState::askLog() and used by the
 * invariant checker: only non-repeatable reasons may not occur twice for
 * the same step within one epoch.
 */
final class AskReason
{
    public const NEXT = 'next';

    public const INVALID = 'invalid';

    public const REASK = 'reask';

    public const EDIT = 'edit';

    public const INTERRUPT = 'interrupt';

    public const RESUME = 'resume';

    public const RECOVERED = 'recovered';

    public const GOBACK = 'goback';

    /** Reasons that legitimately ask the same step again within one epoch. */
    public const REPEATABLE = [self::INVALID, self::REASK, self::EDIT, self::GOBACK, self::RECOVERED, self::INTERRUPT];
}
