<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Passepartout\Types;

final readonly class TokenIntrospection
{
    public function __construct(
        public bool $active,
        public ?string $subject = null,
        public ?string $tenantId = null,
        public ?array $scopes = null,
        public ?string $expiresAt = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            active: (bool) $data['active'],
            subject: $data['subject'] ?? null,
            tenantId: $data['tenant_id'] ?? null,
            scopes: $data['scopes'] ?? null,
            expiresAt: $data['expires_at'] ?? null,
        );
    }
}
