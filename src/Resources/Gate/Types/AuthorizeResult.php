<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

final readonly class AuthorizeResult
{
    public function __construct(
        public bool $allowed,
        public string $decisionId,
        public ?string $reason,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            allowed: (bool) $data['allowed'],
            decisionId: $data['decision_id'],
            reason: $data['reason'] ?? null,
        );
    }
}
