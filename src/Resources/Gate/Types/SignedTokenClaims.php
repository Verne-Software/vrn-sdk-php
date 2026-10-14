<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/**
 * The verified payload of a Gate signed token.
 *
 * The token lives 15 minutes and cannot be revoked; `orgs` is as old as the token. For
 * anything that must follow a revocation or a role change at once, use
 * `sessions()->check()`, introspection or `organizations()->can()`.
 */
final readonly class SignedTokenClaims
{
    /**
     * @param  OrgClaim[]  $orgs  The user's organizations, as of when the token was made.
     * @param  array<string, mixed>  $raw  Every claim as received.
     */
    public function __construct(
        /** `<base URL>/auth/<tenant id>` — checked by the verifier. */
        public string $iss,
        /** The end user's identity id. */
        public string $sub,
        /** The session the token was made from (revocable with `sessions()->revoke()`). */
        public ?string $sid,
        public ?string $tenantId,
        public ?string $schemaId,
        public ?string $email,
        /** Always `authenticated` (the database role PostgREST-style consumers use). */
        public ?string $role,
        public array $orgs,
        /** `true` when `orgs` is incomplete; list them with `identities()->listOrganizations()`. */
        public bool $orgsTruncated,
        public ?int $iat,
        public ?int $nbf,
        public int $exp,
        public ?string $jti,
        public array $raw = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            iss: (string) ($data['iss'] ?? ''),
            sub: (string) ($data['sub'] ?? ''),
            sid: $data['sid'] ?? null,
            tenantId: $data['tenant_id'] ?? null,
            schemaId: $data['schema_id'] ?? null,
            email: $data['email'] ?? null,
            role: $data['role'] ?? null,
            orgs: OrgClaim::listFromArray(is_array($data['orgs'] ?? null) ? $data['orgs'] : []) ?? [],
            orgsTruncated: (bool) ($data['orgs_truncated'] ?? false),
            iat: isset($data['iat']) ? (int) $data['iat'] : null,
            nbf: isset($data['nbf']) ? (int) $data['nbf'] : null,
            exp: (int) ($data['exp'] ?? 0),
            jti: $data['jti'] ?? null,
            raw: $data,
        );
    }
}
