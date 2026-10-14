<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/** The answer of `sessions()->check()`: whether a session is good enough to act on. */
final readonly class SessionCheck
{
    /**
     * @param  OrgClaim[]|null  $orgs  The user's organizations in the key's project, read now (first 100).
     */
    public function __construct(
        public bool $active,
        /** `aal1` or `aal2`. */
        public string $aal,
        /** Whether your tenant requires a second factor. */
        public bool $mfaRequired,
        /** Whether this session meets what your tenant requires — the one field to gate on. */
        public bool $mfaSatisfied,
        public ?string $identityId,
        public ?array $orgs = null,
        /** `true` when there are more organizations than `orgs` shows. */
        public bool $orgsTruncated = false,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            active: (bool) ($data['active'] ?? false),
            aal: (string) ($data['aal'] ?? ''),
            mfaRequired: (bool) ($data['mfa_required'] ?? false),
            mfaSatisfied: (bool) ($data['mfa_satisfied'] ?? false),
            identityId: $data['identity_id'] ?? null,
            orgs: OrgClaim::listFromArray($data['orgs'] ?? null),
            orgsTruncated: (bool) ($data['orgs_truncated'] ?? false),
        );
    }
}
