<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/** A named set of permissions inside one organization. */
final readonly class Role
{
    /**
     * @param  string[]  $permissions  sorted and de-duplicated by the API
     */
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $name,
        public array $permissions,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            organizationId: $data['organization_id'] ?? '',
            name: $data['name'],
            permissions: array_values($data['permissions'] ?? []),
            createdAt: $data['created_at'] ?? '',
            updatedAt: $data['updated_at'] ?? '',
        );
    }
}
