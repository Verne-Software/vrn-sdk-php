<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/**
 * An end user's session, as the identity service returns it. The stable fields are
 * typed; the whole object is in `$raw`.
 */
final readonly class Session
{
    /**
     * @param  array<array<string, mixed>>  $authenticationMethods  `[{method, completed_at, …}]`
     * @param  array<array<string, mixed>>  $devices  `[{id, ip_address, user_agent, location}]`
     * @param  array<string, mixed>|null  $identity
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public ?bool $active = null,
        public ?string $authenticatedAt = null,
        public ?string $expiresAt = null,
        public ?string $issuedAt = null,
        public ?string $authenticatorAssuranceLevel = null,
        public array $authenticationMethods = [],
        public array $devices = [],
        public ?array $identity = null,
        public array $raw = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            active: isset($data['active']) ? (bool) $data['active'] : null,
            authenticatedAt: $data['authenticated_at'] ?? null,
            expiresAt: $data['expires_at'] ?? null,
            issuedAt: $data['issued_at'] ?? null,
            authenticatorAssuranceLevel: $data['authenticator_assurance_level'] ?? null,
            authenticationMethods: $data['authentication_methods'] ?? [],
            devices: $data['devices'] ?? [],
            identity: $data['identity'] ?? null,
            raw: $data,
        );
    }
}
