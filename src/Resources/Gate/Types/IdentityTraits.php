<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/**
 * An end user's traits. The `user` schema has exactly two: `email` (required) and
 * `locale`. Application data does not go here — keep it in custom metadata
 * (`identities()->updateMetadata()`).
 */
final readonly class IdentityTraits
{
    /**
     * @param  string|null  $tenantId  Deprecated: the API never returns it, so it is always
     *                                 `null`. Kept so code that reads it still runs.
     * @param  array|null  $customData  Deprecated: there is no `custom_data` trait (creating
     *                                  with one is a `400 invalid_payload`). Use custom metadata.
     * @param  array<string, mixed>  $extra  Any other trait the identity carries.
     */
    public function __construct(
        public string $email,
        public ?string $tenantId = null,
        public ?array $customData = null,
        public ?string $locale = null,
        public array $extra = [],
    ) {}

    public static function fromArray(array $data): self
    {
        $extra = $data;
        unset($extra['email'], $extra['locale'], $extra['tenant_id'], $extra['custom_data']);

        return new self(
            email: $data['email'] ?? '',
            tenantId: $data['tenant_id'] ?? null,
            customData: $data['custom_data'] ?? null,
            locale: $data['locale'] ?? null,
            extra: $extra,
        );
    }
}
