<?php

namespace FaridM\StepChat\Contracts;

use FaridM\StepChat\Support\ArraySession;

/**
 * Where conversations live between HTTP requests (PHP session, cache, Redis,
 * a database row...). The widget only ever sees an opaque session id.
 */
interface SessionStore
{
    public function load(string $id): ?ArraySession;

    public function save(string $id, ArraySession $session): void;
}
