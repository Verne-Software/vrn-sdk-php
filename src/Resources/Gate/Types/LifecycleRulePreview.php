<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/** How many people a rule with given settings would affect right now. */
final readonly class LifecycleRulePreview
{
    public function __construct(
        public string $kind,
        public bool $available,
        public int $matchingNow,
        /** `true` when the count stopped at 1,000. */
        public bool $capped,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            kind: (string) ($data['kind'] ?? ''),
            available: (bool) ($data['available'] ?? false),
            matchingNow: (int) ($data['matching_now'] ?? 0),
            capped: (bool) ($data['capped'] ?? false),
        );
    }
}
