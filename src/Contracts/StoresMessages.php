<?php

namespace FaridM\StepChat\Contracts;

/**
 * A session that keeps its own message list (see Support\ArraySession), so
 * an endpoint can return the messages written since a cursor.
 */
interface StoresMessages
{
    /**
     * @param array<string, mixed> $message
     */
    public function addMessage(array $message): void;

    /**
     * Messages with an index >= $cursor, each with an `id` (its absolute index).
     *
     * @return list<array<string, mixed>>
     */
    public function messagesSince(int $cursor): array;

    /** Absolute index the next message will get (pass it back as the cursor). */
    public function messageCursor(): int;
}
