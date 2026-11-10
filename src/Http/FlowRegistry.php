<?php

namespace FaridM\AiChatStateMachine\Http;

use Closure;
use FaridM\AiChatStateMachine\Contracts\FlowFactory;
use FaridM\AiChatStateMachine\Flow;
use InvalidArgumentException;

/**
 * Named flows, so one endpoint can serve several widgets on a site.
 */
final class FlowRegistry
{
    /** @var array<string, Closure|FlowFactory> */
    private $flows = [];

    /**
     * @param Closure(string|null): Flow|FlowFactory $flow
     */
    public function register(string $name, $flow): self
    {
        if (!$flow instanceof Closure && !$flow instanceof FlowFactory) {
            throw new InvalidArgumentException('A flow must be a Closure returning a Flow, or a FlowFactory.');
        }
        $this->flows[$name] = $flow;

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->flows[$name]);
    }

    public function make(string $name, ?string $locale = null): Flow
    {
        $source = $this->flows[$name] ?? null;
        if ($source === null) {
            throw new InvalidArgumentException(sprintf('Unknown flow "%s".', $name));
        }

        return $source instanceof FlowFactory ? $source->make($locale) : $source($locale);
    }
}
