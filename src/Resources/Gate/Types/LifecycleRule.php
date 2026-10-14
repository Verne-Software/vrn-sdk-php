<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/** One automatic-deletion rule of the key's project. */
final readonly class LifecycleRule
{
    /**
     * @param  array<string, mixed>|null  $params  the saved settings, `null` when never set up
     */
    public function __construct(
        /** One of the `LifecycleRuleKind` constants. */
        public string $kind,
        public bool $configured,
        public bool $enabled,
        public ?array $params,
        /** Whether your plan includes the rule. */
        public bool $available,
        /** `plan` when a plan change switched the rule off. */
        public ?string $disabledReason,
        public ?string $enabledAt,
        public ?string $updatedAt,
        /** How many people have something scheduled by this rule. */
        public int $pending,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            kind: (string) $data['kind'],
            configured: (bool) ($data['configured'] ?? false),
            enabled: (bool) ($data['enabled'] ?? false),
            params: is_array($data['params'] ?? null) ? $data['params'] : null,
            available: (bool) ($data['available'] ?? false),
            disabledReason: $data['disabled_reason'] ?? null,
            enabledAt: $data['enabled_at'] ?? null,
            updatedAt: $data['updated_at'] ?? null,
            pending: (int) ($data['pending'] ?? 0),
        );
    }
}
