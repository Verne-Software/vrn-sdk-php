<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

final readonly class IntrospectResult
{
    public function __construct(
        public bool $active,
        public string $subject,
        public string $tenantId,
        public array $scopes,
        public string $expiresAt,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            active: (bool) $data['active'],
            subject: $data['subject'],
            tenantId: $data['tenant_id'],
            scopes: $data['scopes'] ?? [],
            expiresAt: $data['expires_at'],
        );
    }
}
