<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/** An end user's membership of an organization: they hold one role there. */
final readonly class Member
{
    public function __construct(
        public string $organizationId,
        public string $identityId,
        public string $roleId,
        /** When the membership ends, or `null` for never. */
        public ?string $expiresAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            organizationId: $data['organization_id'] ?? '',
            identityId: $data['identity_id'],
            roleId: $data['role_id'],
            expiresAt: $data['expires_at'] ?? null,
            createdAt: $data['created_at'] ?? '',
            updatedAt: $data['updated_at'] ?? '',
        );
    }
}
