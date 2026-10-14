<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Clockwork\Types;

/** Pending delayed jobs against the plan's limit (`null` for no limit). */
final readonly class ClockworkDelayedUsage
{
    public function __construct(
        public int $pending,
        public ?int $limit,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            pending: (int) ($data['pending'] ?? 0),
            limit: isset($data['limit']) ? (int) $data['limit'] : null,
        );
    }
}
