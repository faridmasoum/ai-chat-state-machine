<?php

namespace FaridM\StepChat\Support;

use FaridM\StepChat\Contracts\SessionStore;

/**
 * Stores conversations in PHP's native $_SESSION. Zero setup for plain-PHP
 * sites: the visitor's PHP session cookie already scopes the data.
 */
final class NativeSessionStore implements SessionStore
{
    private const KEY = '_stepchat';

    public function load(string $id): ?ArraySession
    {
        $this->boot();
        $data = $_SESSION[self::KEY][$id] ?? null;

        return is_array($data) ? ArraySession::fromArray($data) : null;
    }

    public function save(string $id, ArraySession $session): void
    {
        $this->boot();
        $_SESSION[self::KEY][$id] = $session->toArray();
    }

    private function boot(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}
