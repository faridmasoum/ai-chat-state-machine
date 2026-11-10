<?php

namespace FaridM\AiChatStateMachine\Laravel;

use FaridM\AiChatStateMachine\Contracts\SessionStore;
use FaridM\AiChatStateMachine\Support\ArraySession;
use Illuminate\Contracts\Cache\Repository;

/**
 * Keeps widget conversations in whichever Laravel cache store you configure.
 */
class CacheSessionStore implements SessionStore
{
    /** @var Repository */
    private $cache;

    /** @var int minutes */
    private $ttl;

    public function __construct(Repository $cache, int $ttlMinutes = 1440)
    {
        $this->cache = $cache;
        $this->ttl = $ttlMinutes;
    }

    public function load(string $id): ?ArraySession
    {
        $data = $this->cache->get($this->key($id));

        return is_array($data) ? ArraySession::fromArray($data) : null;
    }

    public function save(string $id, ArraySession $session): void
    {
        $this->cache->put($this->key($id), $session->toArray(), now()->addMinutes($this->ttl));
    }

    private function key(string $id): string
    {
        return 'ai-chat-state-machine:' . $id;
    }
}
