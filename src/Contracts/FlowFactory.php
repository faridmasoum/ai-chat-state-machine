<?php

namespace FaridM\AiChatStateMachine\Contracts;

use FaridM\AiChatStateMachine\Flow;

/**
 * Implement this on a class that builds one of your flows, so it can be
 * registered by class name (e.g. in the Laravel config).
 */
interface FlowFactory
{
    /**
     * @param string|null $locale BCP-47 tag chosen in the widget (e.g. "fa", "ar"), so labels can be localised
     */
    public function make(?string $locale = null): Flow;
}
