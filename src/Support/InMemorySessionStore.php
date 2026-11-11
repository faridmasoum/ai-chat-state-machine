<?php

namespace FaridM\StepChat\Support;

use FaridM\StepChat\Contracts\SessionStore;

/**
 * Per-process store. Meant for tests and demos; data is lost when the
 * request ends.
 */
final class InMemorySessionStore implements SessionStore
{
    /** @var array<string, array<string, mixed>> */
    private $data = [];

    public function load(string $id): ?ArraySession
    {
        return isset($this->data[$id]) ? ArraySession::fromArray($this->data[$id]) : null;
    }

    public function save(string $id, ArraySession $session): void
    {
        $this->data[$id] = $session->toArray();
    }
}
