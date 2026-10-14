<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/** What it was done to. */
final readonly class AuditLogTarget
{
    public function __construct(
        public string $type,
        public string $id,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(type: (string) ($data['type'] ?? ''), id: (string) ($data['id'] ?? ''));
    }
}
