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
        /** The person's address, or `null` when they have none (a Telegram user has no email). */
        public ?string $email = null,
        /**
         * What the person is called, or `null`: the `display_name` trait, or for a Telegram
         * user the first name (failing that, the username) they signed in with.
         */
        public ?string $displayName = null,
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
            email: isset($data['email']) && is_string($data['email']) ? $data['email'] : null,
            displayName: isset($data['display_name']) && is_string($data['display_name']) ? $data['display_name'] : null,
        );
    }
}
