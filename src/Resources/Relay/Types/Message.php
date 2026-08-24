<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Relay\Types;

final readonly class Message
{
    /**
     * @param string $id        Unique message identifier.
     * @param string $eventType Dot-notated event name, e.g. `user.created`.
     * @param string $status    Always `accepted`. It records that Relay took the event,
     *                          not what each subscriber endpoint did with it afterwards
     *                          — per-endpoint delivery state lives in the Console under
     *                          Dashboard → Relay.
     * @param string $timestamp ISO 8601 time the event was accepted.
     */
    public function __construct(
        public string $id,
        public string $eventType,
        public string $status,
        public string $timestamp,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            eventType: $data['event_type'],
            status: $data['status'],
            timestamp: $data['timestamp'],
        );
    }
}
