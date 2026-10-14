<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/**
 * One organization a user belongs to: the unit Gate uses everywhere it says who a user
 * is — introspection, the session check, the signed token's `orgs` claim and
 * `identities()->listOrganizations()`.
 */
final readonly class OrgClaim
{
    /**
     * @param  string[]  $permissions
     */
    public function __construct(
        public string $id,
        public string $role,
        public array $permissions,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            role: (string) ($data['role'] ?? ''),
            permissions: array_values(array_map('strval', $data['permissions'] ?? [])),
        );
    }

    /**
     * @param  array<array<string, mixed>>|null  $list
     * @return self[]|null
     */
    public static function listFromArray(?array $list): ?array
    {
        if ($list === null) {
            return null;
        }

        return array_map(static fn (array $o): self => self::fromArray($o), $list);
    }
}
