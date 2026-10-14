<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/** A group of your end users (a customer's team, workspace, company). */
final readonly class Organization
{
    public function __construct(
        public string $id,
        public string $name,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
            createdAt: $data['created_at'] ?? '',
            updatedAt: $data['updated_at'] ?? '',
        );
    }
}
