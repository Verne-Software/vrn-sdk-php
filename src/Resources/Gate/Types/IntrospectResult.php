<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/**
 * Result of introspecting a Gate access token.
 *
 * An inactive token (unknown, expired, another tenant's, or its user deactivated or
 * deleted) is `active: false` and nothing else: every other field is then `null`.
 */
final readonly class IntrospectResult
{
    /**
     * @param  string[]|null  $scopes
     * @param  OrgClaim[]|null  $orgs  When `subject` is an end user's id: their
     *                                 organizations in the key's project, read live, the
     *                                 first 100 in the order they joined. `null` for any
     *                                 other subject and for an inactive token.
     * @param  bool  $orgsTruncated  `true`, and only then, when there are more than `orgs`
     *                               shows; list them with `identities()->listOrganizations()`.
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
            active: (bool) ($data['active'] ?? false),
            subject: $data['subject'] ?? null,
            tenantId: $data['tenant_id'] ?? null,
            scopes: $data['scopes'] ?? null,
            expiresAt: $data['expires_at'] ?? null,
            orgs: OrgClaim::listFromArray($data['orgs'] ?? null),
            orgsTruncated: (bool) ($data['orgs_truncated'] ?? false),
        );
    }
}
