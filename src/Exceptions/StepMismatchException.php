<?php

namespace FaridM\AiChatStateMachine\Exceptions;

use RuntimeException;

/**
 * The turn names a step that is not the session's current step. This
 * usually means the client is out of sync (stale UI, double submit, replay).
 */
class StepMismatchException extends RuntimeException
{
}
