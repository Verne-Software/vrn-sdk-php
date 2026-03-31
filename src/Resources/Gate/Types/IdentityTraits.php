<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

final readonly class IdentityTraits
{
    public function __construct(
        public string $email,
        public ?string $tenantId,
        public ?array $customData,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            email: $data['email'] ?? '',
            tenantId: $data['tenant_id'] ?? null,
            customData: $data['custom_data'] ?? null,
        );
    }
}
