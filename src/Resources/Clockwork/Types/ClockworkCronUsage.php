<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Clockwork\Types;

/** Active cron jobs against the plan's limit (`null` for no limit). */
final readonly class ClockworkCronUsage
{
    public function __construct(
        public int $active,
        public ?int $limit,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            active: (int) ($data['active'] ?? 0),
            limit: isset($data['limit']) ? (int) $data['limit'] : null,
        );
    }
}
