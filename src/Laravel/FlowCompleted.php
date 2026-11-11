<?php

namespace FaridM\StepChat\Laravel;

/**
 * Dispatched when a visitor submits a finished flow through the widget.
 * Listen for it to save a lead, send an email, call your CRM...
 *
 *   Event::listen(FlowCompleted::class, fn ($e) => Lead::create($e->answers));
 */
class FlowCompleted
{
    /** @var string */
    public $flow;

    /** @var array<string, mixed> */
    public $answers;

    /**
     * @param array<string, mixed> $answers
     */
    public function __construct(string $flow, array $answers)
    {
        $this->flow = $flow;
        $this->answers = $answers;
    }
}
