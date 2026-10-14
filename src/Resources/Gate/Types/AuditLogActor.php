<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/** Who did it: `type` is `team_member`, `api_key`, `end_user`, `system`, `staff` or `unknown` (open set). */
final readonly class AuditLogActor
{
    public function __construct(
        public string $type,
        public ?string $id,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(type: (string) ($data['type'] ?? 'unknown'), id: $data['id'] ?? null);
    }
}
