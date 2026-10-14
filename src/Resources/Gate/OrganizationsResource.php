<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate;

use Vernesoft\Core\HttpClient;
use Vernesoft\Core\Types\Paginated;
use Vernesoft\Resources\Gate\Types\AuthorizeResult;
use Vernesoft\Resources\Gate\Types\Organization;

/**
 * Organizations of your end users — their teams, workspaces, companies — with named
 * roles and members. Everything here acts on **the project the API key belongs to**.
 *
 * Gate keeps the data and reports it (introspection, the session check, the signed
 * token's `orgs` claim); it does not enforce permissions. Ask with `can()`.
 */
class OrganizationsResource
{
    private ?RolesResource $rolesResource = null;

    private ?MembersResource $membersResource = null;

    public function __construct(private HttpClient $httpClient) {}

    public function roles(): RolesResource
    {
        return $this->rolesResource ??= new RolesResource($this->httpClient);
    }

    public function members(): MembersResource
    {
        return $this->membersResource ??= new MembersResource($this->httpClient);
    }

    /**
     * Creates an organization. `$name` is free text, 1–120 characters, not unique.
     */
    public function create(string $name): Organization
    {
        $data = $this->httpClient->post('/v1/gate/organizations', ['name' => $name]);

        return Organization::fromArray($data);
    }

    /**
     * Lists the project's organizations, oldest first, one page at a time.
     *
     * @param  int|null  $limit  1–100, default 20 (the server clamps values outside the range)
     * @param  string|null  $cursor  the previous page's `nextCursor`
     * @return Paginated<Organization>
     */
    public function list(?int $limit = null, ?string $cursor = null): Paginated
    {
        $data = $this->httpClient->get('/v1/gate/organizations', ['limit' => $limit, 'cursor' => $cursor]);

        return Paginated::fromArray($data, static fn (array $o): Organization => Organization::fromArray($o));
    }

    public function get(string $orgId): Organization
    {
        $data = $this->httpClient->get(self::path($orgId));

        return Organization::fromArray($data);
    }

    /**
     * Renames an organization.
     */
    public function update(string $orgId, string $name): Organization
    {
        $data = $this->httpClient->patch(self::path($orgId), ['name' => $name]);

        return Organization::fromArray($data);
    }

    /**
     * Deletes an organization with its roles and memberships.
     */
    public function delete(string $orgId): void
    {
        $this->httpClient->delete(self::path($orgId));
    }

    /**
     * *May this end user do this in this organization?* — answered from the role the user
     * holds there and its permissions, as they are now. `$permission` must match exactly
     * (no wildcards). An organization of another project looks like a missing one
     * (`no_such_organization`). An invalid permission (upper case, `*`, the `nautilus:`
     * prefix) is a `400 invalid_payload` exception, never `allowed: false`.
     *
     * `reason` is one of the `AuthorizeReason` constants; `role` is set when the user is
     * a member.
     */
    public function authorize(string $orgId, string $identityId, string $permission): AuthorizeResult
    {
        $data = $this->httpClient->post('/v1/gate/authorize', [
            'subject' => $identityId,
            'action' => $permission,
            'resource' => 'organization:'.$orgId,
        ]);

        return AuthorizeResult::fromArray($data);
    }

    /**
     * `true` when the end user holds `$permission` in the organization — the boolean of
     * `authorize()`. Errors are thrown, never turned into `false`.
     */
    public function can(string $orgId, string $identityId, string $permission): bool
    {
        return $this->authorize($orgId, $identityId, $permission)->allowed;
    }

    /** @internal */
    public static function path(string $orgId): string
    {
        return '/v1/gate/organizations/'.HttpClient::seg($orgId);
    }
}
