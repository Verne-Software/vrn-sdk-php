<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Passepartout\Types;

use Vernesoft\Resources\Gate\Types\OrgClaim;

/**
 * Result of introspecting a Passepartout access token. An unknown, expired or foreign
 * token, or one whose user was deactivated or deleted, is `active: false` and nothing else.
 */
final readonly class TokenIntrospection
{
    /**
     * @param  OrgClaim[]|null  $orgs  The user's organizations in the key's project, with
     *                                 the role held in each and its permissions — read live,
     *                                 the first 100 in the order they joined.
     * @param  bool  $orgsTruncated  `true`, and only then, when there are more than `orgs` shows.
     */
    public function __construct(
        public bool $active,
        public ?string $subject = null,
        public ?string $tenantId = null,
        public ?array $scopes = null,
        public ?string $expiresAt = null,
        public ?array $orgs = null,
        public bool $orgsTruncated = false,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            active: (bool) $data['active'],
            subject: $data['subject'] ?? null,
            tenantId: $data['tenant_id'] ?? null,
            scopes: $data['scopes'] ?? null,
            expiresAt: $data['expires_at'] ?? null,
            orgs: OrgClaim::listFromArray($data['orgs'] ?? null),
            orgsTruncated: (bool) ($data['orgs_truncated'] ?? false),
        );
    }
}
