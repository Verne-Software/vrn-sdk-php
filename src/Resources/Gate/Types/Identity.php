<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/**
 * An end user (an identity of the identity service) managed by Gate.
 *
 * The stable fields are typed; the whole object as the API sent it is in `$raw`.
 */
final readonly class Identity
{
    /**
     * @param  array<string, mixed>|null  $metadataPublic  Your custom metadata. Read and
     *                                                     change it with `getMetadata()` / `updateMetadata()`.
     * @param  array<array<string, mixed>>  $verifiableAddresses
     * @param  array<array<string, mixed>>  $recoveryAddresses
     * @param  array<string, mixed>  $raw  The identity object as received.
     */
    public function __construct(
        public string $id,
        public string $schemaId,
        public string $state,
        public IdentityTraits $traits,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
        public ?string $stateChangedAt = null,
        public ?array $metadataPublic = null,
        public array $verifiableAddresses = [],
        public array $recoveryAddresses = [],
        public array $raw = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            schemaId: $data['schema_id'],
            state: $data['state'],
            traits: IdentityTraits::fromArray($data['traits'] ?? []),
            createdAt: $data['created_at'] ?? null,
            updatedAt: $data['updated_at'] ?? null,
            stateChangedAt: $data['state_changed_at'] ?? null,
            metadataPublic: $data['metadata_public'] ?? null,
            verifiableAddresses: $data['verifiable_addresses'] ?? [],
            recoveryAddresses: $data['recovery_addresses'] ?? [],
            raw: $data,
        );
    }
}
