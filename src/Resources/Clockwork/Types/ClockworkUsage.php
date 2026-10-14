<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Clockwork\Types;

/** Where the tenant stands against its plan's job limits. A `null` limit means no limit. */
final readonly class ClockworkUsage
{
    public function __construct(
        public string $plan,
        /** The same value as `plan` today. */
        public ?string $tier,
        public ClockworkCronUsage $cron,
        public ClockworkDelayedUsage $delayed,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            plan: (string) ($data['plan'] ?? ''),
            tier: $data['tier'] ?? null,
            cron: ClockworkCronUsage::fromArray($data['cron'] ?? []),
            delayed: ClockworkDelayedUsage::fromArray($data['delayed'] ?? []),
        );
    }
}
