<?php

namespace FaridM\StepChat\Exceptions;

use RuntimeException;

/**
 * The turn cannot be applied to the current step at all (e.g. unknown step key).
 */
class InvalidTurnException extends RuntimeException
{
}
