<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

final readonly class Identity
{
    public function __construct(
        public string $id,
        public string $schemaId,
        public string $state,
        public IdentityTraits $traits,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            schemaId: $data['schema_id'],
            state: $data['state'],
            traits: IdentityTraits::fromArray($data['traits'] ?? []),
        );
    }
}
