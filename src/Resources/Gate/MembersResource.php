<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate;

use Vernesoft\Core\HttpClient;
use Vernesoft\Core\Types\Paginated;
use Vernesoft\Resources\Gate\Types\Member;

/** Members of one organization: end users holding one role each. */
class MembersResource
{
    public function __construct(private HttpClient $httpClient) {}

    /**
     * Gives an end user a role in the organization. `404` if the identity is not one of
     * your end users, `409 conflict` if they already are a member (change the role with
     * `update()`).
     *
     * @param  string|\DateTimeInterface|null  $expiresAt  when the membership ends (a
     *                                                     future time, at most 10 years
     *                                                     ahead); `null` for never. Sent as RFC 3339 UTC.
     */
    public function add(string $orgId, string $identityId, string $roleId, string|\DateTimeInterface|null $expiresAt = null): Member
    {
        $body = ['identity_id' => $identityId, 'role_id' => $roleId];
        if ($expiresAt !== null) {
            $body['expires_at'] = HttpClient::formatTime($expiresAt);
        }

        $data = $this->httpClient->post($this->base($orgId), $body);

        return Member::fromArray($data);
    }

    /**
     * @return Paginated<Member>
     */
    public function list(string $orgId, ?int $limit = null, ?string $cursor = null): Paginated
    {
        $data = $this->httpClient->get($this->base($orgId), ['limit' => $limit, 'cursor' => $cursor]);

        return Paginated::fromArray($data, static fn (array $m): Member => Member::fromArray($m));
    }

    /**
     * Changes a member's role and/or deadline.
     *
     * The deadline has three cases: leave `$expiresAt` null and `$clearExpiresAt` false
     * to **keep** it; pass `$clearExpiresAt: true` to **remove** it (sends `null`); pass a
     * time to **set** it. Passing both is an `InvalidArgumentException`. Sending neither a
     * role nor a deadline change is refused by the API with `400`.
     *
     * @throws \InvalidArgumentException when both `$expiresAt` and `$clearExpiresAt` are given
     */
    public function update(
        string $orgId,
        string $identityId,
        ?string $roleId = null,
        string|\DateTimeInterface|null $expiresAt = null,
        bool $clearExpiresAt = false,
    ): Member {
        if ($expiresAt !== null && $clearExpiresAt) {
            throw new \InvalidArgumentException('Pass either $expiresAt (set a deadline) or $clearExpiresAt (remove it), not both.');
        }

        $body = [];
        if ($roleId !== null) {
            $body['role_id'] = $roleId;
        }
        if ($expiresAt !== null) {
            $body['expires_at'] = HttpClient::formatTime($expiresAt);
        } elseif ($clearExpiresAt) {
            $body['expires_at'] = null;
        }

        $data = $this->httpClient->patch(
            $this->base($orgId).'/'.HttpClient::seg($identityId),
            $body === [] ? new \stdClass : $body,
        );

        return Member::fromArray($data);
    }

    /**
     * Removes the membership. The person stays your end user.
     */
    public function remove(string $orgId, string $identityId): void
    {
        $this->httpClient->delete($this->base($orgId).'/'.HttpClient::seg($identityId));
    }

    private function base(string $orgId): string
    {
        return OrganizationsResource::path($orgId).'/members';
    }
}
