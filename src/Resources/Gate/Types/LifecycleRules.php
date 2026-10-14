<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/** All four rules of the key's project, set up or not, and the plan. */
final readonly class LifecycleRules
{
    /**
     * @param  LifecycleRule[]  $rules
     */
    public function __construct(
        /** Your plan: `free`, `pro`, `advanced` or `contract`. */
        public string $tier,
        public array $rules,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            tier: (string) ($data['tier'] ?? ''),
            rules: array_map(static fn (array $r): LifecycleRule => LifecycleRule::fromArray($r), $data['rules'] ?? []),
        );
    }
}
