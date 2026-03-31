<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Relay\Types;

final readonly class Message
{
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
