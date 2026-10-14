<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate;

use Vernesoft\Core\HttpClient;
use Vernesoft\Core\Types\Paginated;
use Vernesoft\Resources\Gate\Types\Role;

/**
 * Roles of one organization: named sets of permissions.
 *
 * Role names are 1–64 characters of lowercase letters, digits, `_` and `-`, starting
 * with a letter or digit, unique in the organization; at most 100 roles per
 * organization. Permissions are lowercase segments joined by `:` (`invoices:write`), at
 * most 64 characters and 100 per role; `*` and the `nautilus:` prefix are reserved. The
 * API de-duplicates and sorts them. None of this is checked client-side.
 */
class RolesResource
{
    public function __construct(private HttpClient $httpClient) {}

    /**
     * Defines a role. `409 conflict` if the name is taken in the organization,
     * `409 limit_reached` past 100 roles.
     *
     * @param  string[]|null  $permissions
     */
    public function create(string $orgId, string $name, ?array $permissions = null): Role
    {
        $body = ['name' => $name];
        if ($permissions !== null) {
            $body['permissions'] = array_values($permissions);
        }

        $data = $this->httpClient->post($this->base($orgId), $body);

        return Role::fromArray($data);
    }

    /**
     * @return Paginated<Role>
     */
    public function list(string $orgId, ?int $limit = null, ?string $cursor = null): Paginated
    {
        $data = $this->httpClient->get($this->base($orgId), ['limit' => $limit, 'cursor' => $cursor]);

        return Paginated::fromArray($data, static fn (array $r): Role => Role::fromArray($r));
    }

    public function get(string $orgId, string $roleId): Role
    {
        $data = $this->httpClient->get($this->base($orgId).'/'.HttpClient::seg($roleId));

        return Role::fromArray($data);
    }

    /**
     * Renames a role and/or **replaces** its permissions. Everyone who holds it has the new
     * permissions the next time they are read. Arguments left `null` are not sent.
     *
     * @param  string[]|null  $permissions
     */
    public function update(string $orgId, string $roleId, ?string $name = null, ?array $permissions = null): Role
    {
        $body = [];
        if ($name !== null) {
            $body['name'] = $name;
        }
        if ($permissions !== null) {
            $body['permissions'] = array_values($permissions);
        }

        $data = $this->httpClient->patch($this->base($orgId).'/'.HttpClient::seg($roleId), $body === [] ? new \stdClass : $body);

        return Role::fromArray($data);
    }

    /**
     * Deletes a role. A role someone holds is refused with `409 conflict`.
     */
    public function delete(string $orgId, string $roleId): void
    {
        $this->httpClient->delete($this->base($orgId).'/'.HttpClient::seg($roleId));
    }

    private function base(string $orgId): string
    {
        return OrganizationsResource::path($orgId).'/roles';
    }
}
